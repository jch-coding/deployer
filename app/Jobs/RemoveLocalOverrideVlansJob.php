<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\Task;
use Throwable;

class RemoveLocalOverrideVlansJob extends BaseTaskJob
{
    /**
     * Create a new job instance.
     */
    public function __construct(public Task $task, public Device $device, public CentralAPIHelper $centralAPIHelper)
    {
        $this->initTaskTiming($task, defaultWaitMinutes: 3);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->handleSafely(function (): void {
            // refresh device scope-id
            if (! $this->device->scope_id) {
                $scopeid_response = $this->centralAPIHelper->getScopeIdFromCentral($this->device);
                if (array_key_exists('error', $scopeid_response)) {
                    $message = '\nFailed to get scope-id from Central for device '.$this->device->name;
                    $this->task->processTaskStatusLog($message, true);

                    return;
                } else {
                    $scope_id = array_pop($scopeid_response)['scopeId'];
                    $this->device->scope_id = $scope_id;
                    $this->device->save();
                }
            }
            // build device specific query parameter
            $query_parameters = [
                'view-type' => 'LOCAL',
                'object-type' => 'LOCAL',
                'scope-id' => $this->device->scope_id,
                'device-function' => $this->device->device_function,
            ];
            // remove local vlans except vlan 1
            $l2_vlan_response = $this->centralAPIHelper->get_l2_vlans($query_parameters);
            if ($l2_vlan_response->ok()) {
                $l2_vlans = $l2_vlan_response->json()['l2-vlan'] ?? [];
                if (! is_array($l2_vlans)) {
                    $l2_vlans = [];
                }
                $override_vlans = array_filter($l2_vlans, fn ($vlan) => ($vlan['vlan'] ?? null) != 1);
                if (count($override_vlans) === 0) {
                    $message = "\nNo local override vlans found for device ".$this->device->name;
                    $this->task->processTaskStatusLog($message);
                    $this->task->devices()->find($this->device)->pivot->update(['status' => 'COMPLETED']);
                    $completed_devices = $this->task->devices->filter(fn ($device) => $device->pivot->status === 'COMPLETED');
                    if ($completed_devices->count() === $this->task->devices->count()) {
                        $this->task->update(['status' => 'COMPLETED']);
                    }
                } else {
                    $success = 0;
                    array_map(function ($vlan_array) use (&$message, &$success) {
                        $delete_response = $this->centralAPIHelper->delete_l2_vlan($this->device, $vlan_array['vlan']);
                        if ($delete_response->ok()) {
                            $success++;
                        } else {
                            $message = "\nFailed to delete vlan {$vlan_array['vlan']}: {$delete_response->json()['message']} for device ".$this->device->name;
                            $this->task->processTaskStatusLog($message, true);
                        }
                    }, $override_vlans);
                    if ($success != count($override_vlans)) {
                        $message = "\nFailed to delete all local override vlans for device ".$this->device->name.". Please check Central for more details.";
                        $this->task->processTaskStatusLog($message, true);
                        $this->release($this->wait_time * 60);
                    } else {
                        $message = "\nSuccessfully deleted all local override vlans for device ".$this->device->name;
                        $this->task->processTaskStatusLog($message);
                        $this->task->devices()->find($this->device)->pivot->update(['status' => 'COMPLETED']);
                        $completed_devices = $this->task->devices->filter(fn ($device) => $device->pivot->status === 'COMPLETED');
                        if ($completed_devices->count() === $this->task->devices->count()) {
                            $this->task->update(['status' => 'COMPLETED']);
                        }
                    }
                }
            } else {
                $message = "\nFailed to get local override vlans: {$l2_vlan_response->json()['message']} for device ".$this->device->name;
                $this->task->processTaskStatusLog($message, true);
                $this->release($this->wait_time * 60);
            }
        }, 'Remove local VLAN overrides');
    }

    public function failed(?Throwable $exception): void
    {
        $this->logFailedException($exception);
        $message = "\nFailed to delete all local override vlans or task timed out. Please check Central for more details.";
        $this->task->processTaskStatusLog($message, true);
        $this->failDeviceAndTaskIfNeeded($this->device);
    }
}
