<?php

namespace App\Jobs;

use App\Events\DeploymentEvent;
use App\Events\FailureEvent;
use App\Models\DeviceInterface;
use App\Models\Task;
use DateTime;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Helper\CentralAPIHelper;
use Illuminate\Support\Facades\Log;
use Throwable;

class UpdateEthernetInterface implements ShouldQueue
{
    use Queueable, Batchable;

    /**
     * Create a new job instance.
     */
    public int $deployment_time;
    public function __construct(public DeviceInterface $deviceInterface, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->deployment_time = $task->deployment_time > 0 ? $task->deployment_time : 10;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (! $this->centralAPIHelper->client->handleBearerTokenAuth()) {
            Log::error('Access Token Renewal failed');
            $this->fail('Access Token Renewal failed. Releasing job for interface '.$this->deviceInterface->interface);
        }
        else {
            $response = $this->centralAPIHelper->patch_ethernet_interface($this->deviceInterface);
            if(! $response->ok()) {
                Log::error('Failed Updating: '.$this->deviceInterface->interface.' on '.$this->deviceInterface->device->name);
                $this->fail('Failed Updating: '.$this->deviceInterface->interface.' on '.$this->deviceInterface->device->name);
            }
            else {
                $this->task->deviceInterfaces()->find($this->deviceInterface)->pivot->update(['status' => 'COMPLETED']);
                DeploymentEvent::dispatch([
                    'deployment_name' => $this->task->deployment->name,
                    'item_name' => $this->deviceInterface->interface,
                    'task_type' => $this->task->task_type,
                    'message' => 'Updated Interface '.$this->deviceInterface->interface.' on '.$this->deviceInterface->device->name,
                    'event_type' => 'deployment_event',
                ]);
            }
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
        FailureEvent::dispatch([
            'deployment_name' => $this->task->deployment->name,
            'item_name' => $this->deviceInterface->interface,
            'task_type' => $this->task->task_type,
            'message' => 'Failed Updating Interface '.$this->deviceInterface->interface.' on '.$this->deviceInterface->device->name,
            'event_type' => 'failure_event',
        ]);
    }
}
