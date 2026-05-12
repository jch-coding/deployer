<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Task;

class AddVlansToDeviceGroup extends BaseTaskJob
{
    /**
     * Create a new job instance.
     */
    public function __construct(public string $device_group, public array $vlans, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->initTaskTiming($task, defaultDeploymentMinutes: 3, defaultWaitMinutes: 1);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->handleSafely(function (): void {
            // Get the scopeid for the device_group from Central
            $device_group_scope_id = $this->centralAPIHelper->get_scopeid_for_device_group($this->device_group);
            if (! $device_group_scope_id) {
                $message = 'Failed to get scopeid for device group '.$this->device_group;
                $this->task->processTaskStatusLog($message, true);

                return;
            } else {
                $device_function = str_contains($this->device_group, 'CORE') ? 'CORE_SWITCH' : 'ACCESS_SWITCH';
                $query_params = [
                    'view-type' => 'LOCAL',
                    'object-type' => 'LOCAL',
                    'scope-id' => $device_group_scope_id,
                    'device-function' => $device_function,
                ];
                $failed = [];
                collect($this->vlans)->each(function ($vlan) use ($query_params, &$failed) {
                    // vlan in the form of ['vlan' => 100, 'name' => 'vlan_name']
                    $response = $this->centralAPIHelper->post_l2_vlan($query_params, $vlan);
                    if (! $response->ok() && ! str_contains($response->json('message'), 'Cannot create duplicate config')) {
                        $failed[] = $vlan;
                    } elseif (! $response->ok() && str_contains($response->json('message'), 'Cannot create duplicate config')) {
                        $this->task->processTaskStatusLog('VLAN '.$vlan['vlan'].' already exists in device group '.$this->device_group);
                    } else {
                        $this->task->processTaskStatusLog('Added vlan '.$vlan['vlan'].' to device group '.$this->device_group);
                    }
                });
                if (count($failed) > 0) {
                    $message = 'Failed to add VLANs to device group '.$this->device_group.': '.implode(', ', array_column($failed, 'vlan'));
                    $this->task->processTaskStatusLog($message, true);
                } else {
                    $this->task->update(['status' => 'COMPLETED']);
                    $this->task->processTaskStatusLog('Added all VLANs to device group '.$this->device_group);
                }
            }
        });
    }
}
