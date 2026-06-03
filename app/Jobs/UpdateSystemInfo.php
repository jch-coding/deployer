<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\Task;
use Illuminate\Http\Client\Response;
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
            $pivotForDevice = $this->task->devices()->find($this->device)?->pivot;
            if ($pivotForDevice === null) {
                Log::error('Device '.$this->device->name.' is not attached to task '.$this->task->id);

                return;
            }

            if (! $this->device->scope_id || in_array($pivotForDevice->status, ['FAILED', 'TIMED_OUT'], true)) {
                $scope_id_response = $this->centralAPIHelper->getScopeIdFromCentral($this->device);
                if (array_key_exists('error', $scope_id_response)) {
                    Log::error('Failed to retrieve scope ID for device '.$this->device->name);
                    $this->task->processTaskStatusLog('Failed to retrieve scope ID for '.$this->device->name);

                    return;
                }

                $scopeEntries = array_values($scope_id_response);
                if ($scopeEntries === [] || ! isset($scopeEntries[0]['scopeId'])) {
                    Log::error('No scope id in hierarchy response for device '.$this->device->name);
                    $this->task->processTaskStatusLog('No scope id in hierarchy response for '.$this->device->name);

                    return;
                }

                $this->device->scope_id = $scopeEntries[0]['scopeId'];
                $this->device->save();
            }

            $response = $this->centralAPIHelper->updateSystemInfo($this->device);
            if (is_array($response) || ! $response instanceof Response) {
                $detail = is_array($response)
                    ? ($response['error'] ?? json_encode($response))
                    : 'Invalid response from Central';
                Log::error('updateSystemInfo failed before HTTP: '.$detail);
                $this->task->processTaskStatusLog('Failed to update system info for '.$this->device->name, true);
                $this->release($this->wait_time * 60);

                return;
            }

            if ($response->successful()) {
                $this->markDevicePivotCompletedAndMaybeFinishTask($pivotForDevice);
                $message = 'System info for '.$this->device->name.' updated successfully';
                Log::info($message);
                $this->task->processTaskStatusLog($message);

                return;
            }

            $messageStr = $this->responseMessageString($response);
            $message = 'Failed to update system info for device '.$this->device->name.' with error: '.$messageStr;
            Log::error($message);
            $this->task->processTaskStatusLog($message.' Trying to create system info profile...', true);
            $this->attemptPostSystemInfo($pivotForDevice);
        }, 'Update system info');
    }

    private function attemptPostSystemInfo($pivot): void
    {
        $createResponse = $this->centralAPIHelper->postSystemInfo($this->device);
        if (is_array($createResponse) || ! $createResponse instanceof Response) {
            $detail = is_array($createResponse)
                ? ($createResponse['error'] ?? json_encode($createResponse))
                : 'Invalid response from Central';
            Log::error('postSystemInfo failed: '.$detail);
            $this->task->processTaskStatusLog('Failed to create system info for device '.$this->device->name, true);
            $this->release($this->wait_time * 60);

            return;
        }

        if ($createResponse->successful()) {
            $this->markDevicePivotCompletedAndMaybeFinishTask($pivot);
            $message = 'System info for '.$this->device->name.' created successfully';
            Log::info($message);
            $this->task->processTaskStatusLog($message);

            return;
        }

        $message = 'Failed to create system info for device '.$this->device->name;
        Log::error($message);
        $this->task->processTaskStatusLog($message, true);
        $this->release($this->wait_time * 60);
    }

    private function markDevicePivotCompletedAndMaybeFinishTask($pivot): void
    {
        $pivot->update(['status' => 'COMPLETED']);
        $this->task->load('devices');
        if ($this->task->allTrackedItemsCompleted()) {
            $this->task->update(['status' => 'COMPLETED']);
        }
    }

    private function responseMessageString(Response $response): string
    {
        $msg = $response->json('message');
        if (is_string($msg)) {
            return $msg;
        }
        if (is_array($msg)) {
            return json_encode($msg);
        }

        return (string) $response->body();
    }

    public function failed(?Throwable $exception): void
    {
        $this->logFailedException($exception);
        $message = 'Failed updating system info for '.$this->device->name;
        $this->task->processTaskStatusLog($message, true);
        $this->failDeviceAndTaskIfNeeded($this->device);
    }
}
