<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Task;
use DateTime;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

class PreprovisionDevicesToGroupJob extends BaseTaskJob
{
    /**
     * Create a new job instance.
     */
    public int $deployment_time;

    public function __construct(public array $devices, public string $group_name, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->deployment_time = $task->deployment_time > 0 ? $task->deployment_time : 3;
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
        $response = $this->centralAPIHelper->preprovision_devices_to_group($this->group_name, $devices);
        $message = '';
        if (! $response->status() !== 201) {
            Log::error('Failed to preprovision devices to group');
            array_reduce($devices, function ($carry, $item) {
                $carry .= "\nFailed Device ".$item.' preprovisioned to group '.$this->group_name;

                return $carry;
            }, $message);
            $this->task->processTaskStatusLog($message);
        } else {
            array_reduce($devices, function ($carry, $item) {
                $carry .= "\nDevice ".$item.' preprovisioned to group '.$this->group_name;

                return $carry;
            }, $message);
            $this->task->processTaskStatusLog($message);
        }
    }

    public function retryUntil(): DateTime
    {
        return now()->addMinutes($this->deployment_time)->toDateTime();
    }

    public function failed(?Throwable $exception): void
    {
        $this->logFailedException($exception);
        $this->markAllDevicesFailed();
        $this->failTask('Failed preprovisioning devices to group. Task timed out or failed.');
    }
}
