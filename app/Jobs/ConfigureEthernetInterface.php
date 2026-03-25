<?php

namespace App\Jobs;

use App\Events\DeploymentEvent;
use App\Events\FailureEvent;
use App\Helper\CentralAPIHelper;
use App\Models\DeviceInterface;
use App\Models\Task;
use DateTime;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ConfigureEthernetInterface implements ShouldQueue
{
    use Batchable, Queueable;

    public int $deployment_time;
    public int $wait_time;

    /**
     * Create a new job instance.
     */
    public function __construct(public DeviceInterface $deviceInterface, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->deployment_time = $task->deployment_time > 0 ? $task->deployment_time : 3;
        $this->wait_time = $task->wait_time ?? 3;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $device = $this->deviceInterface->device;
        if (! $device->scope_id) {
            $scopeid_response = $this->centralAPIHelper->getScopeIdFromCentral($device);
            if (array_key_exists('error', $scopeid_response)) {
                return;
            }
            $device->scope_id = $scopeid_response[0]['scopeId'];
            $device->save();
        }
        $statusLog = $this->task->status_log;
        $interface_response = $this->centralAPIHelper->patch_ethernet_interface($this->deviceInterface);
        if (! $interface_response->ok()) {
            $newStatusLog = $statusLog . "\nFailed to patch ethernet interface: " . $this->deviceInterface->interface . " on device " . $device->name . " with message: " . $interface_response->json()['message'] . "\n";
            $this->task->update(['status_log' => $newStatusLog]);
            Log::error('Failed to patch ethernet interface: '.$this->deviceInterface->interface.' on device '.$device->name.' with message:'.$interface_response->json()['message']);
            $this->release(random_int(15, 30));
        }
        else {
            $newStatusLog = $statusLog . "\nInterface " . $this->deviceInterface->interface . " configured";
            if ($this->deviceInterface->sw_profile) {
                $newStatusLog .= ' with ' . $this->deviceInterface->sw_profile . ' profile';
            }
            $this->task->deviceInterfaces()->find($this->deviceInterface)->pivot->update(['status' => 'COMPLETED']);
            $deviceInterfaces = $this->task->deviceInterfaces->filter(fn($deviceInterface) => $deviceInterface->device_id === $device->id);
            $completedDeviceInterfaces = $deviceInterfaces->filter(fn($deviceInterface) => $deviceInterface->pivot->status === 'COMPLETED');
            if ($completedDeviceInterfaces->count() === $deviceInterfaces->count()) {
                $this->task->devices()->find($device)->pivot->update(['status' => 'COMPLETED']);
            }
            $this->task->update(['status_log' => $newStatusLog]);
        }
    }

    public function retryUntil(): DateTime
    {
        return now()->addMinutes($this->deployment_time)->toDateTime();
    }

    public function failed(?Throwable $exception)
    {
        Log::error($exception);
        $this->task->deviceInterfaces()->find($this->deviceInterface)->pivot->update(['status' => 'FAILED']);
        $statusLog = $this->task->status_log;
        $newStatusLog = $statusLog . "\nFailed Updating Interface ".$this->deviceInterface->interface." on ".$this->deviceInterface->device->name." with message: ".$exception->getMessage();
        $this->task->update(['status_log' => $newStatusLog]);
        FailureEvent::dispatch([
            'deployment_name' => $this->task->deployment->name,
            'item_name' => $this->deviceInterface->interface,
            'task_type' => $this->task->task_type,
            'message' => 'Failed Updating Interface '.$this->deviceInterface->interface.' on '.$this->deviceInterface->device->name,
            'event_type' => 'failure_event',
        ]);
    }
}
