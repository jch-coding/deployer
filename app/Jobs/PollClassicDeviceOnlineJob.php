<?php

namespace App\Jobs;

use App\Enums\ProvisioningStep;
use App\Helper\CentralAPIHelper;
use App\Models\ProvisioningWorkflow;
use App\Models\ProvisioningWorkflowDevice;
use App\Services\Provisioning\ClassicDeviceOnlineService;
use App\Services\Provisioning\MarkDeviceOnlineIfWaiting;
use DateTime;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PollClassicDeviceOnlineJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $workflowId,
    ) {}

    public function handle(
        ClassicDeviceOnlineService $classicDeviceOnlineService,
        MarkDeviceOnlineIfWaiting $markDeviceOnlineIfWaiting,
    ): void {
        $workflow = ProvisioningWorkflow::query()
            ->with(['deployment.client', 'workflowDevices.device', 'workflowDevices.steps'])
            ->find($this->workflowId);

        if ($workflow === null || $workflow->isTerminal()) {
            return;
        }

        $waitingDevices = $workflow->workflowDevices()
            ->where('overall_status', 'in_progress')
            ->whereHas('steps', fn ($query) => $query
                ->where('step_key', ProvisioningStep::WaitForOnline->value)
                ->where('status', 'in_progress'))
            ->with(['device', 'steps', 'workflow'])
            ->get();

        if ($waitingDevices->isEmpty()) {
            $workflow->update(['classic_poller_active' => false]);

            return;
        }

        $centralAPIHelper = new CentralAPIHelper($workflow->deployment->client);
        $switchStatuses = $classicDeviceOnlineService->workflowNeedsSwitchPoll($workflow)
            ? $classicDeviceOnlineService->fetchSwitchStatuses($centralAPIHelper)
            : [];
        $apStatuses = $classicDeviceOnlineService->workflowNeedsApPoll($workflow)
            ? $classicDeviceOnlineService->fetchApStatuses($centralAPIHelper)
            : [];

        $anyWaiting = false;

        /** @var ProvisioningWorkflowDevice $workflowDevice */
        foreach ($waitingDevices as $workflowDevice) {
            if ($markDeviceOnlineIfWaiting($workflowDevice, $switchStatuses, $apStatuses)) {
                continue;
            }

            $anyWaiting = true;
            $status = $classicDeviceOnlineService->currentStatus(
                $workflowDevice->device,
                $switchStatuses,
                $apStatuses,
            );
            $workflowDevice->update([
                'status_message' => "Waiting for device to come online (status: {$status}).",
            ]);
        }

        if ($anyWaiting && ! $workflow->isTerminal()) {
            $this->release(max(60, $workflow->wait_time * 60));

            return;
        }

        $workflow->update(['classic_poller_active' => false]);
    }

    public function retryUntil(): DateTime
    {
        $workflow = ProvisioningWorkflow::query()->find($this->workflowId);
        $minutes = $workflow?->deployment_time ?? 10;

        return now()->addMinutes($minutes)->toDateTime();
    }
}
