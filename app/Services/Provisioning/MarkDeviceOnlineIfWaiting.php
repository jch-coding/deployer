<?php

namespace App\Services\Provisioning;

use App\Enums\OnlineDetectionMode;
use App\Enums\ProvisioningStep;
use App\Helper\CentralAPIHelper;
use App\Models\ProvisioningWorkflowDevice;

class MarkDeviceOnlineIfWaiting
{
    public function __construct(
        private readonly ClassicDeviceOnlineService $classicDeviceOnlineService,
        private readonly ProvisioningWorkflowOrchestrator $orchestrator,
    ) {}

    /**
     * @param  array<string, string>  $switchStatuses
     * @param  array<string, string>  $apStatuses
     */
    public function __invoke(
        ProvisioningWorkflowDevice $workflowDevice,
        array $switchStatuses,
        array $apStatuses,
    ): bool {
        $workflowDevice->loadMissing('device', 'steps', 'workflow');

        if ($workflowDevice->isTerminal()) {
            return false;
        }

        $stepRow = $workflowDevice->steps->firstWhere('step_key', ProvisioningStep::WaitForOnline->value);
        if ($stepRow === null || $stepRow->status !== 'in_progress') {
            return false;
        }

        if (! $this->classicDeviceOnlineService->isDeviceUp($workflowDevice->device, $switchStatuses, $apStatuses)) {
            return false;
        }

        $this->orchestrator->processStepResult(
            $workflowDevice,
            ProvisioningStep::WaitForOnline,
            ProvisioningStepResult::completed('Device is online (Up).'),
        );

        return true;
    }

    public function forSerial(int $clientId, string $serial): void
    {
        $waitingDevices = ProvisioningWorkflowDevice::query()
            ->where('overall_status', 'in_progress')
            ->whereHas('steps', fn ($query) => $query
                ->where('step_key', ProvisioningStep::WaitForOnline->value)
                ->where('status', 'in_progress'))
            ->whereHas('device', fn ($query) => $query
                ->where('serial', $serial)
                ->where('client_id', $clientId))
            ->whereHas('workflow', fn ($query) => $query
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->where('online_detection_mode', OnlineDetectionMode::Webhook->value))
            ->with(['device', 'steps', 'workflow.deployment.client'])
            ->get();

        if ($waitingDevices->isEmpty()) {
            return;
        }

        /** @var ProvisioningWorkflowDevice $first */
        $first = $waitingDevices->first();
        $client = $first->workflow->deployment->client;
        $centralAPIHelper = new CentralAPIHelper($client);

        $needsSwitch = $waitingDevices->contains(
            fn (ProvisioningWorkflowDevice $wd) => str_contains((string) $wd->device->device_function, 'SWITCH'),
        );
        $needsAp = $waitingDevices->contains(
            fn (ProvisioningWorkflowDevice $wd) => str_contains((string) $wd->device->device_function, 'AP'),
        );

        $switchStatuses = $needsSwitch
            ? $this->classicDeviceOnlineService->fetchSwitchStatuses($centralAPIHelper)
            : [];
        $apStatuses = $needsAp
            ? $this->classicDeviceOnlineService->fetchApStatuses($centralAPIHelper)
            : [];

        foreach ($waitingDevices as $workflowDevice) {
            ($this)($workflowDevice, $switchStatuses, $apStatuses);
        }
    }
}
