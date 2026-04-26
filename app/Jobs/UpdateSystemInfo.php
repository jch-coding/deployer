<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\Task;
use Illuminate\Support\Facades\Log;
use Throwable;

class UpdateSystemInfo extends BaseTaskJob
{
    /**
     * Create a new job instance.
     */
    public function __construct(public Device $device, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->initTaskTiming($task, defaultDeploymentMinutes: 3, defaultWaitMinutes: 1);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->handleSafely(function (): void {
            $pivotForDevice = $this->task->devices()->find($this->device)->pivot;

            if (! $this->device->scope_id || $pivotForDevice->status === 'FAILED') {
                $scope_id_response = $this->centralAPIHelper->getScopeIdFromCentral($this->device);
                if (array_key_exists('error', $scope_id_response)) {
                    Log::error('Failed to retrieve scope ID for device '.$this->device->name);
                    $this->task->update(['status_log' => $this->task->status_log.'\nFailed to retrieve scope ID for '.$this->device->name]);

                    return;
                }
                $this->device->scope_id = $scope_id_response[0]['scopeId'];
                $this->device->save();
            }

            $response = $this->centralAPIHelper->updateSystemInfo($this->device);
            if ($response->status() == 200) {
                $pivotForDevice->update(['status' => 'COMPLETED']);
                $message = 'System info for '.$this->device->name.' updated successfully';
                Log::info($message);
                $this->task->processTaskStatusLog($message);
            } else {
                if (str_contains($response->json('message'), 'System Info doesn\'t exist')) {
                    $message = 'Failed to update system info for device '.$this->device->name.' Trying to create system info profile...';
                    Log::error($message);
                    $this->task->processTaskStatusLog($message, true);
                    $response = $this->centralAPIHelper->postSystemInfo($this->device);
                    if ($response->status() == 200) {
                        $pivotForDevice->update(['status' => 'COMPLETED']);
                        $message = 'System info for '.$this->device->name.' created successfully';
                        Log::info($message);
                        $this->task->processTaskStatusLog($message);
                    } else {
                        $message = 'Failed to create system info for device '.$this->device->name;
                        Log::error($message);
                        $this->task->processTaskStatusLog($message, true);
                        $this->release($this->wait_time * 60);
                    }
                } else {
                    $message = 'Failed to update system info for device '.$this->device->name. ' with error: '.$response->json('message');
                    Log::error($message);
                    $this->task->processTaskStatusLog($message, true);
                    $this->release($this->wait_time * 60);
                }
            }
        }, 'Update system info');
    }

    public function failed(?Throwable $exception): void
    {
        $this->logFailedException($exception);
        $message = 'Failed updating system info for .'.$this->device->name;
        $this->task->processTaskStatusLog($message, true);
        $this->failDeviceAndTaskIfNeeded($this->device);
    }
}
