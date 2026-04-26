<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\Task;
use Illuminate\Support\Facades\Log;
use Throwable;

class RemoveLocalOverrideStaticRouteJob extends BaseTaskJob
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
                $message = '\nFailed to get scope-id from Central.';
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

        //remove local override static route
        $static_route_response = $this->centralAPIHelper->get_static_route($query_parameters);
        if ($static_route_response->ok()) {
            $static_profile_name = array_pop($static_route_response->json()['profile'])['name'];
            $delete_static_response = $this->centralAPIHelper->delete_static_route($static_profile_name, $query_parameters);
            if (! $delete_static_response->ok()) {
                $message = "\nFailed to delete static route: {$delete_static_response->json()['message']}";
                $this->task->processTaskStatusLog($message, true);
                $this->release($this->wait_time * 60);
            } else {
                $message = "\nDeleted static route: {$static_profile_name}";
                $this->task->processTaskStatusLog($message);
                $this->task->devices()->find($this->device)->pivot->update(['status' => 'COMPLETED']);
                $completed_devices = $this->task->devices->filter(fn ($device) => $device->pivot->status === 'COMPLETED');
                if ($completed_devices->count() === $this->task->devices->count()) {
                    $this->task->update(['status' => 'COMPLETED']);
                }
            }
        } else {
            $message = "\nFailed to get local override static routes: {$static_route_response->json()['message']}";
            $this->task->processTaskStatusLog($message, true);
            $this->release($this->wait_time * 60);
        }
        }, 'Remove local static-route override');
    }

    public function failed(?Throwable $exception): void
    {
        $this->logFailedException($exception);
        $message = "\nFailed to delete all local override static routes or task timed out. Please check Central for more details.";
        $this->task->processTaskStatusLog($message, true);
        $this->failDeviceAndTaskIfNeeded($this->device);
    }
}
