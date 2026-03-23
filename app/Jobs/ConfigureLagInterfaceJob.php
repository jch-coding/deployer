<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\DeviceInterface;
use App\Models\Task;
use DateTime;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ConfigureLagInterfaceJob implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * Create a new job instance.
     */
    public $deployment_time;

    public function __construct(public DeviceInterface $device_interface, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->deployment_time = $task->deployment_time > 0 ? $task->deployment_time : 3;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $response = $this->centralAPIHelper->post_interface_portchannel($this->device_interface->interface);
        if (! $response->ok()) {
            Log::error($response->json('message'));
            $this->release(random_int(1, 10));
        }
        $status_log = $this->task->status_log;
        $new_log = $status_log.'\nConfigured LAG '.$this->device_interface->interface;
        $this->task->update(['status_log' => $new_log]);
    }

    public function retryUntil(): DateTime
    {
        return now()->addMinutes($this->deployment_time)->toDateTime();
    }
}
