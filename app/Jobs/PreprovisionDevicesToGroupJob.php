<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Task;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class PreprovisionDevicesToGroupJob extends BaseTaskJob
{
    public const BATCH_DELAY_SECONDS = 15;

    /**
     * Create a new job instance.
     *
     * @param  array<int, array{id: int, serial: string, name: string}>  $devices
     */
    public function __construct(public array $devices, public string $group_name, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->initTaskTiming($task);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->handleSafely(function (): void {
            if (! $this->validateGroupExists()) {
                return;
            }

            $devices = array_values($this->devices);
            if ($devices === []) {
                return;
            }

            $deviceSerials = array_map(fn ($device) => $device['serial'], $devices);
            if (! $this->preprovisionChunk($devices, $deviceSerials)) {
                return;
            }

            $this->task->load('devices');
            if ($this->task->allTrackedItemsCompleted()) {
                $this->task->update(['status' => 'COMPLETED']);
            }
        }, 'Preprovision devices to group');
    }

    private function validateGroupExists(): bool
    {
        $groups_result = $this->centralAPIHelper->classic_collect_all_group_names();
        if (isset($groups_result['error'])) {
            $message = "\nFailed to preprovision devices to group. Could not load groups from Central.";
            Log::error($groups_result['error']);
            $this->task->processTaskStatusLog($message);
            $this->markJobDevicesFailed();
            $this->failTask('Failed preprovisioning devices to group.');

            return false;
        }

        if (! in_array($this->group_name, $groups_result['names'], true)) {
            $message = "\nGroup not found in Central. Double check the group name.";
            Log::error($message);
            $this->task->processTaskStatusLog($message);
            $this->markJobDevicesFailed();
            $this->failTask('Failed preprovisioning devices to group.');

            return false;
        }

        return true;
    }

    /**
     * @param  array<int, array{id: int, serial: string, name: string}>  $devices
     * @param  array<int, string>  $serials
     */
    private function preprovisionChunk(array $devices, array $serials): bool
    {
        $response = $this->centralAPIHelper->preprovision_devices_to_group($this->group_name, $serials);
        $ok = ! is_array($response) && $response instanceof Response && $response->status() === 201;
        $shouldFallbackToMove = $response instanceof Response
            && $response->status() === 400
            && str_contains((string) $response->json('description'), 'Following Devices are already connected to Central');

        if ($shouldFallbackToMove) {
            $moveResponse = $this->centralAPIHelper->move_devices_to_group($this->group_name, $serials);
            $ok = ! is_array($moveResponse) && $moveResponse instanceof Response && $moveResponse->ok();
            $response = $moveResponse;
        }

        if (! $ok) {
            $status = $response instanceof Response ? $response->status() : 'unknown';
            $body = $response instanceof Response ? $response->body() : json_encode($response);
            Log::error('Failed to preprovision devices to group', ['status' => $status, 'body' => $body]);
            $message = array_reduce(
                $serials,
                fn (string $carry, string $item): string => $carry."\nFailed to preprovision device ".$item.' to group '.$this->group_name.'. Will retry.',
                ''
            );
            $this->task->processTaskStatusLog($message);
            $this->release(self::BATCH_DELAY_SECONDS);

            return false;
        }

        foreach ($devices as $device) {
            $this->task->devices()->find($device['id'])?->pivot?->update(['status' => 'COMPLETED']);
        }

        $message = $shouldFallbackToMove
            ? array_reduce(
                $serials,
                fn (string $carry, string $item): string => $carry."\nDevice ".$item.' moved to group '.$this->group_name.' (fallback from preprovision)',
                ''
            )
            : array_reduce(
                $serials,
                fn (string $carry, string $item): string => $carry."\nDevice ".$item.' preprovisioned to group '.$this->group_name,
                ''
            );
        $this->task->processTaskStatusLog($message);

        return true;
    }

    private function markJobDevicesFailed(): void
    {
        foreach ($this->devices as $device) {
            $this->markDeviceFailed($device['id']);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->logFailedException($exception);
        $this->markJobDevicesFailed();

        if ($this->allTaskDevicesFailed()) {
            $this->task->update(['status' => 'FAILED']);
            $this->task->processTaskStatusLog('Failed preprovisioning devices to group. Task timed out or failed.');

            return;
        }

        $this->failTask('Failed preprovisioning devices to group. Task timed out or failed.');
    }
}
