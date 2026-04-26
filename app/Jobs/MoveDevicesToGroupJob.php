<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\Task;
use DateTime;
use Illuminate\Support\Facades\Log;
use Throwable;

class MoveDevicesToGroupJob extends BaseTaskJob
{
    public int $deployment_time;

    public int $wait_time;

    /**
     * Create a new job instance.
     */
    public function __construct(public string $group_name, public array $devices, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->deployment_time = $task->deployment_time ?? 3;
        $this->wait_time = $task->wait_time ?? 1;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->handleSafely(function (): void {
            $device_serials = array_map(fn ($device) => $device['serial'], $this->devices);
            $this->moveDevicesToGroup($device_serials);
        }, 'Move devices to group');
    }

    public function moveDevicesToGroup($devices): void
    {
        // check if group exists
        $groups_response = $this->centralAPIHelper->classic_get_groups();
        if (! $groups_response->ok()) {
            $message = 'Failed to preprovision devices to group. Group not found.';
            Log::error($groups_response->json('detail'));
            $this->task->processTaskStatusLog($message);

            return;
        } else {
            // try to find the group within the list of groups
            $group_found = collect($groups_response->json('data'))->collapse()->filter(fn ($item) => $item === $this->group_name);
            if ($group_found->isEmpty()) {
                $message = 'Group not found in Central. Double check the group name.';
                Log::error($message);
                $this->task->processTaskStatusLog($message);

                return;
            }
        }
        $response = $this->centralAPIHelper->move_devices_to_group($this->group_name, $devices);
        $message = '';
        if (! $response->ok()) {
            Log::error('Failed to move devices to group with error '.$response->json()['message']);
            array_reduce($devices, function ($carry, $item) {
                $carry .= "\nFailed Device ".$item.' move to group '.$this->group_name;

                return $carry;
            }, $message);
            $this->task->processTaskStatusLog($message);
        } else {
            array_reduce($devices, function ($carry, $item) {
                $carry .= "\nDevice ".$item.' moved to group '.$this->group_name;

                return $carry;
            }, $message);
            $this->task->processTaskStatusLog($message);
        }
    }

    public function retryUntil(): DateTime
    {
        return now()->addMinutes($this->wait_time)->toDateTime();
    }

    public function failed(?Throwable $exception): void
    {
        $this->logFailedException($exception);
        $this->markAllDevicesFailed();
        $this->failTask('Failed moving devices to group. Task timed out or failed.');
    }
}
