<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Task;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class MoveDevicesToGroupJob extends BaseTaskJob
{
    private const SERIALS_PER_REQUEST = 25;

    /**
     * Create a new job instance.
     */
    public function __construct(public array $devices, public string $group_name, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->initTaskTiming($task, defaultWaitMinutes: 1);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->handleSafely(function (): void {
            $this->moveDevicesToGroup();
        }, 'Move devices to group');
    }

    public function moveDevicesToGroup(): void
    {
        $groups_result = $this->centralAPIHelper->classic_collect_all_group_names();
        if (isset($groups_result['error'])) {
            $message = 'Failed to move devices to group. Could not load groups from Central.';
            Log::error($groups_result['error']);
            $this->task->processTaskStatusLog($message);

            return;
        }

        if (! in_array($this->group_name, $groups_result['names'], true)) {
            $message = 'Group not found in Central. Double check the group name.';
            Log::error($message);
            $this->task->processTaskStatusLog($message);

            return;
        }

        $chunks = array_chunk($this->devices, self::SERIALS_PER_REQUEST);

        foreach ($chunks as $chunk) {
            $serials = array_map(fn ($device) => $device['serial'], $chunk);
            $response = $this->centralAPIHelper->move_devices_to_group($this->group_name, $serials);
            $ok = ! is_array($response) && $response instanceof Response && $response->ok();

            if ($ok) {
                foreach ($chunk as $device) {
                    $this->task->devices()->find($device['id'])?->pivot?->update(['status' => 'COMPLETED']);
                }
                $message = array_reduce(
                    $serials,
                    fn (string $carry, string $serial) => $carry."\nDevice ".$serial.' moved to group '.$this->group_name,
                    ''
                );
                $this->task->processTaskStatusLog($message);
            } else {
                foreach ($chunk as $device) {
                    $this->markDeviceFailed($device['id']);
                }
                $errorDetail = $this->formatMoveToGroupError($response);
                Log::error('Failed to move devices to group with error '.$errorDetail);
                $message = array_reduce(
                    $serials,
                    fn (string $carry, string $serial) => $carry."\nFailed Device ".$serial.' move to group '.$this->group_name,
                    ''
                );
                $this->task->processTaskStatusLog($message);
            }
        }

        $this->task->load('devices');

        if ($this->task->allTrackedItemsCompleted()) {
            $this->task->update(['status' => 'COMPLETED']);
        } elseif ($this->allTaskDevicesFailed()) {
            $this->failTask('All devices failed to move to group.');
        }
    }

    private function formatMoveToGroupError(mixed $response): string
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
        $this->failTask('Failed moving devices to group. Task timed out or failed.');
    }
}
