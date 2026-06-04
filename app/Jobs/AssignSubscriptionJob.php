<?php

namespace App\Jobs;

use App\Helper\GreenLakeAPIHelper;
use App\Models\Task;
use Illuminate\Support\Facades\Log;
use Throwable;

class AssignSubscriptionJob extends BaseTaskJob
{
    public function __construct(
        public array $devices,
        public string $greenlakeSubscriptionId,
        public Task $task,
        public GreenLakeAPIHelper $greenLakeAPIHelper,
    ) {
        $this->initTaskTiming($task, defaultDeploymentMinutes: 3, defaultWaitMinutes: 1);
    }

    public function handle(): void
    {
        $this->handleSafely(function (): void {
            $this->assignSubscriptions();
        }, 'Assign subscription');
    }

    public function assignSubscriptions(): void
    {
        $subscriptionId = trim($this->greenlakeSubscriptionId);
        if ($subscriptionId === '') {
            $message = 'No GreenLake subscription id configured for this job.';
            Log::error($message);
            $this->task->processTaskStatusLog($message);

            return;
        }

        $deviceIds = [];
        foreach ($this->devices as $device) {
            $greenlakeDeviceId = trim((string) ($device['greenlake_device_id'] ?? ''));
            if ($greenlakeDeviceId !== '') {
                $deviceIds[] = $greenlakeDeviceId;
            }
        }

        if ($deviceIds === []) {
            $this->failTask('No GreenLake device ids available for license assignment.');

            return;
        }

        $result = $this->greenLakeAPIHelper->assignSubscriptionToDevices($deviceIds, $subscriptionId);
        $ok = $result['error'] === null && array_filter(
            $result['responses'],
            fn ($response) => ! $response->ok(),
        ) === [];

        if ($ok) {
            foreach ($this->devices as $device) {
                $this->task->devices()->find($device['id'])?->pivot?->update(['status' => 'COMPLETED']);
            }
            $message = array_reduce(
                $this->devices,
                fn (string $carry, array $device) => $carry."\nAssigned license ({$subscriptionId}) to device ".($device['serial'] ?? ''),
                ''
            );
            $this->task->processTaskStatusLog($message);
        } else {
            foreach ($this->devices as $device) {
                $this->markDeviceFailed($device['id']);
            }
            $errorDetail = $result['error'] ?? 'GreenLake assign failed.';
            Log::error('Failed to assign subscription with error '.$errorDetail);
            $this->task->processTaskStatusLog("\nFailed to assign license ({$subscriptionId}): {$errorDetail}");
        }

        $this->task->load('devices');

        if ($this->task->allTrackedItemsCompleted()) {
            $this->task->update(['status' => 'COMPLETED']);
        } elseif ($this->allTaskDevicesFailed()) {
            $this->failTask('All devices failed license assignment.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->logFailedException($exception);
        $this->markAllDevicesFailed();
        $this->failTask('Failed assigning licenses. Task timed out or failed.');
    }
}
