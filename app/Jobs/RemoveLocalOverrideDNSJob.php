<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\Task;
use Illuminate\Support\Facades\Log;
use Throwable;

class RemoveLocalOverrideDNSJob extends BaseTaskJob
{
    /**
     * Create a new job instance.
     */
    public function __construct(public Task $task, public Device $device, public CentralAPIHelper $centralAPIHelper)
    {
        $this->initTaskTiming($task, defaultDeploymentMinutes: 3, defaultWaitMinutes: 3);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->handleSafely(function (): void {
        //refresh device scope-id
        if (!$this->device->scope_id) {
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
        //build device specific query parameter
        $query_parameters = [
            'view-type' => 'LOCAL',
            'object-type' => 'LOCAL',
            'scope-id' => $this->device->scope_id,
            'device-function' => $this->device->device_function
        ];

        //remove local dns
        $dns_response = $this->centralAPIHelper->get_dns_profiles($query_parameters);
        if ($dns_response->ok()) {
            if (key_exists('profile', $dns_response->json())) {
                $dns_profile_name = array_pop($dns_response->json()['profile'])['name'];
                $delete_dns_response = $this->centralAPIHelper->delete_dns_profile($dns_profile_name, $query_parameters);
                if (! $delete_dns_response->ok()) {
                    $message = "\nFailed to delete dns profile: {$delete_dns_response->json()['message']} for device ".$this->device->name;
                    $this->task->processTaskStatusLog($message, true);
                    $this->release($this->wait_time * 60);
                } else {
                    $message = "\nDeleted dns profile: {$dns_profile_name} for device ".$this->device->name;
                    $this->task->processTaskStatusLog($message);
                    $this->task->devices()->find($this->device)->pivot->update(['status' => 'COMPLETED']);
                    $completed_devices = $this->task->devices->filter(fn ($device) => $device->pivot->status === 'COMPLETED');
                    if ($completed_devices->count() === $this->task->devices->count()) {
                        $this->task->update(['status' => 'COMPLETED']);
                    }
                }
            } else {
                $message = "\nNo local override dns profiles found for device ".$this->device->name;
                $this->task->processTaskStatusLog($message);
                $this->task->devices()->find($this->device)->pivot->update(['status' => 'COMPLETED']);
                $completed_devices = $this->task->devices->filter(fn ($device) => $device->pivot->status === 'COMPLETED');
                if ($completed_devices->count() === $this->task->devices->count()) {
                    $this->task->update(['status' => 'COMPLETED']);
                }
            }
        } else {
            $message = "\nFailed to get local override dns profiles: {$dns_response->json()['message']} for device ".$this->device->name;
            $this->task->processTaskStatusLog($message, true);
            $this->release($this->wait_time * 60);
        }
        }, 'Remove local DNS override');
    }

    public function failed(?Throwable $exception): void
    {
        $this->logFailedException($exception);
        $message = "\nFailed to delete all local override dns profiles or task timed out. Please check Central for more details.";
        $this->task->processTaskStatusLog($message, true);
        $this->failDeviceAndTaskIfNeeded($this->device);
    }
}
