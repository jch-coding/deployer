<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Task;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class PreprovisionDevicesToGroupJob extends BaseTaskJob
{
    /**
     * Create a new job instance.
     */
    public function __construct(public array $devices, public string $group_name, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->initTaskTiming($task, defaultDeploymentMinutes: 3);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->handleSafely(function (): void {
            $device_serials = array_map(fn ($device) => $device['serial'], $this->devices);
            $this->preprovisionDevices($device_serials);
        }, 'Preprovision devices to group');
    }

    public function preprovisionDevices($devices): void
    {
        $groups_result = $this->centralAPIHelper->classic_collect_all_group_names();
        if (isset($groups_result['error'])) {
            $message = "\nFailed to preprovision devices to group. Could not load groups from Central.";
            Log::error($groups_result['error']);
            $this->task->processTaskStatusLog($message);
            $this->markAllDevicesFailed();
            $this->failTask('Failed preprovisioning devices to group.');

            return;
        }

        if (! in_array($this->group_name, $groups_result['names'], true)) {
            $message = "\nGroup not found in Central. Double check the group name.";
            Log::error($message);
            $this->task->processTaskStatusLog($message);
            $this->markAllDevicesFailed();
            $this->failTask('Failed preprovisioning devices to group.');

            return;
        }
        $response = $this->centralAPIHelper->preprovision_devices_to_group($this->group_name, $devices);
        $ok = ! is_array($response) && $response instanceof Response && $response->status() === 201;
        $shouldFallbackToMove = $response instanceof Response
            && $response->status() === 400
            && str_contains((string) $response->json('description'), 'Following Devices are already connected to Central');

        if ($shouldFallbackToMove) {
            $moveResponse = $this->centralAPIHelper->move_devices_to_group($this->group_name, $devices);
            $ok = ! is_array($moveResponse) && $moveResponse instanceof Response && $moveResponse->ok();
            $response = $moveResponse;
        }

        if (! $ok) {
            foreach ($this->devices as $device) {
                $this->markDeviceFailed($device['id']);
            }
            $status = $response instanceof Response ? $response->status() : 'unknown';
            $body = $response instanceof Response ? $response->body() : json_encode($response);
            Log::error('Failed to preprovision devices to group', ['status' => $status, 'body' => $body]);
            $message = array_reduce(
                $devices,
                fn (string $carry, string $item): string => $carry."\nFailed to preprovision device ".$item.' to group '.$this->group_name,
                ''
            );
            $this->task->processTaskStatusLog($message);

            if ($this->allTaskDevicesFailed()) {
                $this->failTask('All devices failed to preprovision to group.');
            }
        } else {
            foreach ($this->devices as $device) {
                $this->task->devices()->find($device['id'])?->pivot?->update(['status' => 'COMPLETED']);
            }

            $message = $shouldFallbackToMove
                ? array_reduce(
                    $devices,
                    fn (string $carry, string $item): string => $carry."\nDevice ".$item.' moved to group '.$this->group_name.' (fallback from preprovision)',
                    ''
                )
                : array_reduce(
                    $devices,
                    fn (string $carry, string $item): string => $carry."\nDevice ".$item.' preprovisioned to group '.$this->group_name,
                    ''
                );
            $this->task->processTaskStatusLog($message);
        }

        $this->task->load('devices');
        if ($this->task->allTrackedItemsCompleted()) {
            $this->task->update(['status' => 'COMPLETED']);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->logFailedException($exception);
        $this->markAllDevicesFailed();
        $this->failTask('Failed preprovisioning devices to group. Task timed out or failed.');
    }
}
