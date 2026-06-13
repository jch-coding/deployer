<?php

namespace App\Services\Provisioning;

use App\Actions\Provisioning\CreateVsxProfilePairAction;
use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\ProvisioningWorkflowDevice;
use Illuminate\Support\Collection;

class ProvisioningVsxCoordinator
{
    public function __construct(
        private readonly CreateVsxProfilePairAction $createVsxProfilePairAction,
    ) {}

    public function attemptVsxProfileCreation(
        ProvisioningWorkflowDevice $workflowDevice,
        CentralAPIHelper $centralAPIHelper,
        ?ProvisioningStepContext $context = null,
    ): ProvisioningStepResult {
        $context ??= ProvisioningStepContext::forWorkflow($workflowDevice->workflow);
        $device = $workflowDevice->device;
        $profileName = CentralAPIHelper::resolveVsxProfileName($device, $context->vsxFallbackMode);

        if ($profileName === null || $profileName === '') {
            return ProvisioningStepResult::skipped('No VSX profile configured.');
        }

        $peers = $this->findReadyPeers($workflowDevice, $profileName, $context->vsxFallbackMode);
        if ($peers === null) {
            $workflowDevice->update([
                'vsx_wait_state' => 'waiting_for_peer',
                'status_message' => 'Waiting for VSX peer to reach stack profile step...',
            ]);

            return ProvisioningStepResult::waitingPeer('Waiting for VSX peer device.');
        }

        $preparedPeers = $peers->map(function (Device $peer) use ($context): Device {
            if ($context->vsxFallbackMode && ! filled($peer->vsx_profile)) {
                CentralAPIHelper::applyVsxFallbackAttributes($peer);
            }

            return $peer;
        });

        $result = $this->createVsxProfilePairAction->execute($profileName, $preparedPeers, $centralAPIHelper);
        if ($result->isCompleted()) {
            foreach ($peers as $peerDevice) {
                ProvisioningWorkflowDevice::query()
                    ->where('provisioning_workflow_id', $workflowDevice->provisioning_workflow_id)
                    ->where('device_id', $peerDevice->id)
                    ->update(['vsx_wait_state' => null]);
            }
        }

        return $result;
    }

    /**
     * @return Collection<int, Device>|null
     */
    private function findReadyPeers(
        ProvisioningWorkflowDevice $workflowDevice,
        string $profileName,
        bool $fallbackMode,
    ): ?Collection {
        $workflowDevice->loadMissing('device');

        $peerWorkflowDevices = ProvisioningWorkflowDevice::query()
            ->where('provisioning_workflow_id', $workflowDevice->provisioning_workflow_id)
            ->with('device')
            ->get()
            ->filter(function (ProvisioningWorkflowDevice $row) use ($profileName, $fallbackMode): bool {
                $resolvedProfile = CentralAPIHelper::resolveVsxProfileName($row->device, $fallbackMode);

                return $resolvedProfile === $profileName;
            })
            ->values();

        if ($peerWorkflowDevices->count() !== 2) {
            return null;
        }

        foreach ($peerWorkflowDevices as $peerWorkflowDevice) {
            if ($peerWorkflowDevice->current_step_key !== 'create_stack_profile') {
                return null;
            }

            $step = $peerWorkflowDevice->steps()
                ->where('step_key', 'create_stack_profile')
                ->first();
            if ($step === null || ! in_array($step->status, ['in_progress', 'pending'], true)) {
                return null;
            }
        }

        return $peerWorkflowDevices->map(fn (ProvisioningWorkflowDevice $row) => $row->device)->values();
    }
}
