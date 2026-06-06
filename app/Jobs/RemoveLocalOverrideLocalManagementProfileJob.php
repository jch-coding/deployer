<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\Task;
use Throwable;

class RemoveLocalOverrideLocalManagementProfileJob extends BaseTaskJob
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
            if (! $this->device->scope_id) {
                $scopeid_response = $this->centralAPIHelper->getScopeIdFromCentral($this->device);
                if (array_key_exists('error', $scopeid_response)) {
                    $message = '\nFailed to get scope-id from Central for device '.$this->device->name;
                    $this->task->processTaskStatusLog($message, true);

                    return;
                }

                $scope_id = array_pop($scopeid_response)['scopeId'];
                $this->device->scope_id = $scope_id;
                $this->device->save();
            }

            $query_parameters = [
                'view-type' => 'LOCAL',
                'object-type' => 'LOCAL',
                'scope-id' => $this->device->scope_id,
                'device-function' => $this->device->device_function,
            ];

            $profile_response = $this->centralAPIHelper->get_local_management_profiles($query_parameters);
            if ($profile_response->ok()) {
                $profiles = $profile_response->json()['profile'] ?? [];
                if (! is_array($profiles)) {
                    $profiles = [];
                }

                $named_profiles = array_values(array_filter(
                    $profiles,
                    fn ($profile) => is_array($profile) && isset($profile['name']) && $profile['name'] !== '',
                ));

                if (count($named_profiles) === 0) {
                    $message = "\nNo local override local management profiles found for device ".$this->device->name;
                    $this->task->processTaskStatusLog($message);
                    $this->markDeviceCompletedIfNeeded();

                    return;
                }

                $success = 0;
                foreach ($named_profiles as $profile) {
                    $profile_name = $profile['name'];
                    $delete_response = $this->centralAPIHelper->delete_local_management_profile($profile_name, $query_parameters);
                    if ($delete_response->ok()) {
                        $success++;
                        $message = "\nDeleted local management profile: {$profile_name} for device ".$this->device->name;
                        $this->task->processTaskStatusLog($message);
                    } else {
                        $message = "\nFailed to delete local management profile: {$delete_response->json()['message']} for device ".$this->device->name;
                        $this->task->processTaskStatusLog($message, true);
                    }
                }

                if ($success !== count($named_profiles)) {
                    $message = "\nFailed to delete all local override local management profiles for device ".$this->device->name.'. Please check Central for more details.';
                    $this->task->processTaskStatusLog($message, true);
                    $this->release($this->wait_time * 60);

                    return;
                }

                $this->markDeviceCompletedIfNeeded();
            } else {
                $message = "\nFailed to get local override local management profiles: {$profile_response->json()['message']} for device ".$this->device->name;
                $this->task->processTaskStatusLog($message, true);
                $this->release($this->wait_time * 60);
            }
        }, 'Remove local management profile override');
    }

    protected function markDeviceCompletedIfNeeded(): void
    {
        $this->task->devices()->find($this->device)->pivot->update(['status' => 'COMPLETED']);
        $completed_devices = $this->task->devices->filter(fn ($device) => $device->pivot->status === 'COMPLETED');
        if ($completed_devices->count() === $this->task->devices->count()) {
            $this->task->update(['status' => 'COMPLETED']);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->logFailedException($exception);
        $message = "\nFailed to delete all local override local management profiles or task timed out. Please check Central for more details.";
        $this->task->processTaskStatusLog($message, true);
        $this->failDeviceAndTaskIfNeeded($this->device);
    }
}
