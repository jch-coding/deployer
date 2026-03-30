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

class UpdateEthernetInterface implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * Create a new job instance.
     */
    public int $deployment_time;

    public int $wait_time;

    public function __construct(public DeviceInterface $deviceInterface, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->deployment_time = $task->deployment_time > 0 ? $task->deployment_time : 3;
        $this->wait_time = $task->wait_time ?? 1;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (! $this->centralAPIHelper->client->handleBearerTokenAuth()) {
            $message = 'Access Token Renewal failed';
            Log::error($message);
            $this->task->processTaskStatusLog($message);
        } else {
            $response = $this->centralAPIHelper->patch_ethernet_interface($this->deviceInterface);
            if (! $response->ok()) {
                $message = 'Failed updating: '.$this->deviceInterface->interface.' on '.$this->deviceInterface->device->name;
                Log::error($message);
                $this->task->processTaskStatusLog($message, true);
                $this->release($this->wait_time * 60);
            } else {
                $message = 'Updated interface '.$this->deviceInterface->interface.' on '.$this->deviceInterface->device->name;
                $this->task->deviceInterfaces()->find($this->deviceInterface)->pivot->update(['status' => 'COMPLETED']);
                $this->task->processTaskStatusLog($message);
            }
        }
    }

    public function retryUntil(): DateTime
    {
        return now()->addMinutes($this->deployment_time)->toDateTime();
    }

    public function failed(?Throwable $exception): void
    {
        Log::error($exception);
        $this->task->deviceInterfaces()->find($this->deviceInterface)->pivot->update(['status' => 'FAILED']);
        $this->task->processTaskStatusLog($exception);

        sleep($this->wait_time * 60);
    }
}
