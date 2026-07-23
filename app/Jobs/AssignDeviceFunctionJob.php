<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Task;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class AssignDeviceFunctionJob extends BaseTaskJob
{
    public const BATCH_DELAY_SECONDS = 15;

    /**
     * Create a new job instance.
     *
     * @param  array<int, array<int, array{id: int, serial: string, name: string}>>  $device_chunks
     */
    public function __construct(public array $device_chunks, public string $device_function, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->initTaskTiming($task, defaultWaitMinutes: 1);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->handleSafely(function (): void {
            foreach ($this->device_chunks as $index => $devices) {
                if ($index > 0) {
                    sleep(self::BATCH_DELAY_SECONDS);
                }

                $pendingDevices = $this->pendingDevicesInChunk($devices);
                if ($pendingDevices === []) {
                    continue;
                }

                if (! $this->assignChunk($pendingDevices)) {
                    return;
                }
            }

            $this->task->load('devices');
            if ($this->task->allTrackedItemsCompleted()) {
                $this->task->update(['status' => 'COMPLETED']);
            }
        }, 'Assign device function');
    }

    /**
     * @param  array<int, array{id: int, serial: string, name: string}>  $devices
     */
    private function assignChunk(array $devices): bool
    {
        $deviceSerials = array_map(fn ($device) => $device['serial'], $devices);
        $response = $this->centralAPIHelper->assignDeviceFunction($deviceSerials, $this->device_function);

        if (is_array($response) || ! $response instanceof Response || ! $response->ok()) {
            $detail = $this->formatAssignDeviceFunctionError($response);
            Log::error($detail);
            $message = array_reduce(
                $deviceSerials,
                fn (string $carry, string $serial): string => $carry."\nFailed to assign device ".$serial.' to '.$this->device_function.'. Will retry.',
                ''
            );
            $this->task->processTaskStatusLog($message);
            $this->release(self::BATCH_DELAY_SECONDS);

            return false;
        }

        foreach ($devices as $device) {
            $this->task->devices()->find($device['id'])?->pivot?->update(['status' => 'COMPLETED']);
        }

        $message = array_reduce(
            $devices,
            fn (string $carry, array $device): string => $carry."\nDevice ".$device['name'].' assigned to '.$this->device_function,
            ''
        );
        $this->task->processTaskStatusLog($message);

        return true;
    }

    /**
     * @param  array<int, array{id: int, serial: string, name: string}>  $devices
     * @return array<int, array{id: int, serial: string, name: string}>
     */
    private function pendingDevicesInChunk(array $devices): array
    {
        $this->task->load('devices');
        $pendingIds = $this->task->devices
            ->filter(fn ($device) => $device->pivot->status === 'PENDING')
            ->pluck('id')
            ->all();

        return array_values(array_filter(
            $devices,
            fn (array $device): bool => in_array($device['id'], $pendingIds, true)
        ));
    }

    private function formatAssignDeviceFunctionError(mixed $response): string
    {
        if (is_array($response)) {
            return $response['error'] ?? json_encode($response);
        }

        if ($response instanceof Response) {
            $json = $response->json();
            if (is_array($json) && isset($json['message'])) {
                return (string) $json['message'];
            }

            return $response->body();
        }

        return 'unknown error';
    }

    public function failed(?Throwable $exception): void
    {
        $this->logFailedException($exception);
        $this->markAllDevicesFailed();
        $this->failTask('Failed assigning device function. Task timed out or failed.');
    }
}
