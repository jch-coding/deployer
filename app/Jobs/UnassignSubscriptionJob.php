<?php

namespace App\Jobs;

use App\Helper\GreenLakeAPIHelper;
use App\Models\Task;
use Illuminate\Support\Facades\Log;
use Throwable;

class UnassignSubscriptionJob extends BaseTaskJob
{
    public function __construct(
        public array $devices,
        public Task $task,
        public GreenLakeAPIHelper $greenLakeAPIHelper,
    ) {
        $this->initTaskTiming($task, defaultWaitMinutes: 1);
    }

    public function handle(): void
    {
        $this->handleSafely(function (): void {
            $this->unassignSubscriptions();
        }, 'Unassign subscription');
    }

    public function unassignSubscriptions(): void
    {
        $deviceIds = [];
        foreach ($this->devices as $device) {
            $greenlakeDeviceId = trim((string) ($device['greenlake_device_id'] ?? ''));
            if ($greenlakeDeviceId !== '') {
                $deviceIds[] = $greenlakeDeviceId;
            }
        }

        if ($deviceIds === []) {
            $this->failTask('No GreenLake device ids available for license unassignment.');

            return;
        }

        $result = $this->greenLakeAPIHelper->unassignSubscriptionFromDevices($deviceIds);
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
                fn (string $carry, array $device) => $carry."\nUnassigned license from device ".($device['serial'] ?? ''),
                ''
            );
            $this->task->processTaskStatusLog($message);
        } else {
            foreach ($this->devices as $device) {
                $this->markDeviceFailed($device['id']);
            }
            $errorDetail = $result['error'] ?? 'GreenLake unassign failed.';
            Log::error('Failed to unassign subscription with error '.$errorDetail);
            $this->task->processTaskStatusLog("\nFailed to unassign license: {$errorDetail}");
        }

        $this->task->load('devices');

        if ($this->task->allTrackedItemsCompleted()) {
            $this->task->update(['status' => 'COMPLETED']);
        } elseif ($this->allTaskDevicesFailed()) {
            $this->failTask('All devices failed license unassignment.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->logFailedException($exception);
        $this->markAllDevicesFailed();
        $this->failTask('Failed unassigning licenses. Task timed out or failed.');
    }
}
