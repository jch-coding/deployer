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
use Throwable;

class ConfigureLagInterfaceJob implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * Create a new job instance.
     */
    public $deployment_time;
    public $wait_time;

    public function __construct(public DeviceInterface $device_interface, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->deployment_time = $task->deployment_time > 0 ? $task->deployment_time : 3;
        $this->wait_time = $task->wait_time ?? 3;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $device = $this->device_interface->device;
        if (! $device->scope_id) {
            $scopeid_response = $this->centralAPIHelper->getScopeIdFromCentral($device);
            if (array_key_exists('error', $scopeid_response)) {
                $message = 'Failed to get scope id for device '.$device->name;
                Log::error($message);
                $this->task->processTaskStatusLog($message, true);

                return;
            }
            $device->scope_id = $scopeid_response[0]['scopeId'];
            $device->save();
        }
        $response = $this->centralAPIHelper->post_interface_portchannel($this->device_interface);
        if (! $response->ok()) {
            Log::error($response->json('message'));
            $this->task->processTaskStatusLog($response->json('message'), true);
            $this->release($this->wait_time * 60);
        }
        $status_log = $this->task->status_log;
        $message = 'Configured LAG '.$this->device_interface->interface;
        $this->task->processTaskStatusLog($message);
        $this->task->deviceInterfaces()->find($this->device_interface)->pivot->update(['status' => 'COMPLETED']);
    }

    public function retryUntil(): DateTime
    {
        return now()->addMinutes($this->deployment_time)->toDateTime();
    }

    public function failed(?Throwable $exception)
    {
        Log::error($exception);
        $this->task->deviceInterfaces()->find($this->device_interface)->pivot->update(['status' => 'FAILED']);
        $failed_interfaces = $this->task->deviceInterfaces->filter(fn ($interface) => $interface->pivot->status == 'FAILED')->count();
        $total_interfaces = $this->task->deviceInterfaces->count();
        if ($failed_interfaces === $total_interfaces) {
            $this->task->update(['status' => 'FAILED']);
        }
        $this->task->processTaskStatusLog($exception);
        $this->release($this->wait_time * 60);
    }
}
