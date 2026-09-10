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
        $this->initTaskTiming($task, defaultWaitMinutes: 1);
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

        $devicesWithIds = [];
        foreach ($this->devices as $device) {
            $greenlakeDeviceId = trim((string) ($device['greenlake_device_id'] ?? ''));
            if ($greenlakeDeviceId === '') {
                $this->markDeviceFailed($device['id'] ?? null);
                $this->task->processTaskStatusLog(
                    "\nFailed to assign license ({$subscriptionId}) to device ".($device['serial'] ?? '').': missing GreenLake device id.',
                );

                continue;
            }

            $devicesWithIds[] = [
                ...$device,
                'greenlake_device_id' => $greenlakeDeviceId,
            ];
        }

        if ($devicesWithIds === []) {
            $this->failTask('No GreenLake device ids available for license assignment.');

            return;
        }

        foreach (array_chunk($devicesWithIds, GreenLakeAPIHelper::DEVICES_PER_ASSIGN_REQUEST) as $deviceChunk) {
            $chunkDeviceIds = array_map(
                fn (array $device): string => $device['greenlake_device_id'],
                $deviceChunk,
            );

            $result = $this->greenLakeAPIHelper->assignSubscriptionToDevices($chunkDeviceIds, $subscriptionId);
            $resultsByGreenLakeId = $result['results'] ?? [];

            foreach ($deviceChunk as $device) {
                $greenlakeDeviceId = $device['greenlake_device_id'];
                $ok = (bool) ($resultsByGreenLakeId[$greenlakeDeviceId] ?? false);

                if ($ok) {
                    $this->task->devices()->find($device['id'])?->pivot?->update(['status' => 'COMPLETED']);
                    $this->task->processTaskStatusLog(
                        "\nAssigned license ({$subscriptionId}) to device ".($device['serial'] ?? ''),
                    );
                } else {
                    $this->markDeviceFailed($device['id']);
                    $errorDetail = $result['error'] ?? 'GreenLake assign failed.';
                    Log::error('Failed to assign subscription with error '.$errorDetail);
                    $this->task->processTaskStatusLog(
                        "\nFailed to assign license ({$subscriptionId}) to device ".($device['serial'] ?? '').": {$errorDetail}",
                    );
                }
            }
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
