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
    ): ProvisioningStepResult {
        $device = $workflowDevice->device;
        $profileName = (string) $device->vsx_profile;

        if ($profileName === '') {
            return ProvisioningStepResult::skipped('No VSX profile configured.');
        }

        $peers = $this->findReadyPeers($workflowDevice, $profileName);
        if ($peers === null) {
            $workflowDevice->update([
                'vsx_wait_state' => 'waiting_for_peer',
                'status_message' => 'Waiting for VSX peer to reach stack profile step...',
            ]);

            return ProvisioningStepResult::waitingPeer('Waiting for VSX peer device.');
        }

        $result = $this->createVsxProfilePairAction->execute($profileName, $peers, $centralAPIHelper);
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
    private function findReadyPeers(ProvisioningWorkflowDevice $workflowDevice, string $profileName): ?Collection
    {
        $workflowDevice->loadMissing('device');
        $device = $workflowDevice->device;

        $peerWorkflowDevices = ProvisioningWorkflowDevice::query()
            ->where('provisioning_workflow_id', $workflowDevice->provisioning_workflow_id)
            ->whereHas('device', fn ($query) => $query->where('vsx_profile', $profileName))
            ->with('device')
            ->get();

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
