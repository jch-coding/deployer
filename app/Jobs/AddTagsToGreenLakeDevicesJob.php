<?php

namespace App\Jobs;

use App\Helper\GreenLakeAPIHelper;
use App\Models\LicensingInventoryDevice;
use App\Models\Task;
use Illuminate\Support\Facades\Log;
use Throwable;

class AddTagsToGreenLakeDevicesJob extends BaseTaskJob
{
    /**
     * @param  array<int, array{id: int|string, serial: string}>  $devices
     */
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
            $this->addTags();
        }, 'Add tags to GreenLake devices');
    }

    public function addTags(): void
    {
        $tags = $this->task->greenlake_tags;
        if (! is_array($tags) || $tags === []) {
            $this->failTask('No GreenLake tags were provided for this task.');

            return;
        }

        $this->task->loadMissing('deployment');
        $clientId = (int) ($this->task->deployment?->client_id ?? 0);

        $payloadDevices = [];
        foreach ($this->devices as $device) {
            $serial = trim((string) ($device['serial'] ?? ''));
            if ($serial === '') {
                $this->markDeviceFailed($device['id'] ?? null);
                $this->task->processTaskStatusLog(
                    "\nSkipped device ".(string) ($device['id'] ?? '').': missing serial.'
                );

                continue;
            }

            $inventory = LicensingInventoryDevice::query()
                ->where('client_id', $clientId)
                ->whereRaw('UPPER(TRIM(serial)) = ?', [strtoupper($serial)])
                ->first();

            $greenlakeDeviceId = trim((string) ($inventory?->greenlake_device_id ?? ''));
            if ($greenlakeDeviceId === '') {
                $this->markDeviceFailed($device['id'] ?? null);
                $this->task->processTaskStatusLog(
                    "\nSkipped device {$serial}: not in GreenLake inventory or missing GreenLake device id."
                );

                continue;
            }

            $payloadDevices[] = [
                'id' => $device['id'],
                'serial' => $serial,
                'greenlake_device_id' => $greenlakeDeviceId,
            ];
        }

        if ($payloadDevices === []) {
            $this->failTask('No devices in GreenLake inventory were available to tag.');

            return;
        }

        $result = $this->greenLakeAPIHelper->assignTagsToDevices(
            array_map(fn (array $device): string => $device['greenlake_device_id'], $payloadDevices),
            $tags,
        );

        $perDevice = $result['results'] ?? [];

        foreach ($payloadDevices as $device) {
            $greenlakeId = $device['greenlake_device_id'];
            $ok = $result['success'] === true;
            if (array_key_exists($greenlakeId, $perDevice)) {
                $ok = $perDevice[$greenlakeId] === true;
            }

            if ($ok) {
                $this->task->devices()->find($device['id'])?->pivot?->update(['status' => 'COMPLETED']);
                $this->task->processTaskStatusLog("\nUpdated GreenLake tags on device {$device['serial']}.");
            } else {
                $this->markDeviceFailed($device['id']);
                $detail = $result['error'] ?? 'GreenLake tag update failed.';
                $this->task->processTaskStatusLog("\nFailed to update tags on device {$device['serial']}: {$detail}");
            }
        }

        if ($result['success'] !== true && ($result['error'] ?? null) !== null) {
            Log::error('Failed to add GreenLake device tags: '.$result['error']);
        }

        $this->task->load('devices');

        if ($this->task->allTrackedItemsCompleted()) {
            $this->task->update(['status' => 'COMPLETED']);
        } elseif ($this->allTaskDevicesFailed()) {
            $this->failTask('All devices failed to update GreenLake tags.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->logFailedException($exception);
        $this->markAllDevicesFailed();
        $this->failTask('Failed adding tags to GreenLake devices. Task timed out or failed.');
    }
}
