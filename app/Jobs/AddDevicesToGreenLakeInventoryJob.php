<?php

namespace App\Jobs;

use App\Helper\GreenLakeAPIHelper;
use App\Models\Task;
use Illuminate\Support\Facades\Log;
use Throwable;

class AddDevicesToGreenLakeInventoryJob extends BaseTaskJob
{
    /**
     * @param  array<int, array{id: int|string, serial: string, mac_address: string}>  $devices
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
            $this->addDevices();
        }, 'Add devices to GreenLake inventory');
    }

    public function addDevices(): void
    {
        $payloadDevices = [];
        foreach ($this->devices as $device) {
            $serial = trim((string) ($device['serial'] ?? ''));
            $mac = trim((string) ($device['mac_address'] ?? ''));
            if ($serial === '' || $mac === '') {
                $this->markDeviceFailed($device['id'] ?? null);
                $this->task->processTaskStatusLog(
                    "\nSkipped device ".($serial !== '' ? $serial : (string) ($device['id'] ?? '')).': missing serial or mac_address.'
                );

                continue;
            }
            $payloadDevices[] = [
                'id' => $device['id'],
                'serial' => $serial,
                'mac_address' => $mac,
            ];
        }

        if ($payloadDevices === []) {
            $this->failTask('No devices with serial and mac_address available to add to GreenLake.');

            return;
        }

        $result = $this->greenLakeAPIHelper->addNetworkDevices(
            array_map(fn (array $device) => [
                'serial' => $device['serial'],
                'mac_address' => $device['mac_address'],
            ], $payloadDevices),
        );

        $perSerial = $result['results'] ?? [];

        foreach ($payloadDevices as $device) {
            $serial = $device['serial'];
            $ok = $result['success'] === true;
            if (array_key_exists($serial, $perSerial)) {
                $ok = $perSerial[$serial] === true;
            }

            if ($ok) {
                $this->task->devices()->find($device['id'])?->pivot?->update(['status' => 'COMPLETED']);
                $this->task->processTaskStatusLog("\nAdded device {$serial} to GreenLake inventory.");
            } else {
                $this->markDeviceFailed($device['id']);
                $detail = $result['error'] ?? 'GreenLake add failed.';
                $this->task->processTaskStatusLog("\nFailed to add device {$serial}: {$detail}");
            }
        }

        if ($result['success'] !== true && ($result['error'] ?? null) !== null) {
            Log::error('Failed to add devices to GreenLake: '.$result['error']);
        }

        $this->task->load('devices');

        if ($this->task->allTrackedItemsCompleted()) {
            $this->task->update(['status' => 'COMPLETED']);
        } elseif ($this->allTaskDevicesFailed()) {
            $this->failTask('All devices failed to add to GreenLake inventory.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->logFailedException($exception);
        $this->markAllDevicesFailed();
        $this->failTask('Failed adding devices to GreenLake inventory. Task timed out or failed.');
    }
}
