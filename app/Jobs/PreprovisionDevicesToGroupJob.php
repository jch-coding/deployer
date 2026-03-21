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

    public function __construct(public array $device_serials, public string $group_name, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->deployment_time = $task->deployment_time > 0 ? $task->deployment_time : 3;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $response = $this->centralAPIHelper->preprovision_devices_to_group($this->group_name, $this->device_serials);
        if (! $response->status() !== 201) {
            Log::error('Failed to preprovision devices to group');
            $this->fail();
        } else {
            $status_log = $this->task->status_log;
            array_reduce($this->device_serials, function ($carry, $item) {
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
