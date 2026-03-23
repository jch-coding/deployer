<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Task;
use DateTime;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PreprovisionDevicesToGroupJob implements ShouldQueue
{
    use Batchable, Queueable;

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
        $device_serials = array_map(fn($device) => $device['serial_number'], $this->devices);
        $this->preprovisionDevices($device_serials);
    }

    public function preprovisionDevices($devices)
    {
        $response = $this->centralAPIHelper->preprovision_devices_to_group($this->group_name, $devices);
        $status_log = $this->task->status_log;
        if (! $response->status() !== 201) {
            Log::error('Failed to preprovision devices to group');
            array_reduce($this->devices, function ($carry, $item) {
                $carry .= "\nFailed Device ".$item.' preprovisioned to group '.$this->group_name;

                return $carry;
            }, $status_log);
            $this->task->update(['status_log' => $status_log]);
        } else {
            array_reduce($this->devices, function ($carry, $item) {
                $carry .= "\nDevice ".$item.' preprovisioned to group '.$this->group_name;

                return $carry;
            }, $status_log);
            $this->task->update(['status_log' => $status_log]);
        }
    }

    public function retryUntil(): DateTime
    {
        return now()->addMinutes($this->deployment_time)->toDateTime();
    }
}
