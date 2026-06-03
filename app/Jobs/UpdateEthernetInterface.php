<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\DeviceInterface;
use App\Models\Task;
use Illuminate\Support\Facades\Log;
use Throwable;

class UpdateEthernetInterface extends BaseTaskJob
{
    /**
     * Create a new job instance.
     */
    public function __construct(public DeviceInterface $deviceInterface, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->initTaskTiming($task, defaultDeploymentMinutes: 3, defaultWaitMinutes: 1);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->handleSafely(function (): void {
            if (! $this->centralAPIHelper->client->handleBearerTokenAuth()) {
                $message = 'Access Token Renewal failed';
                Log::error($message);
                $this->task->processTaskStatusLog($message);
            } else {
                $this->deviceInterface->loadMissing('device.site');
                $vrfResult = $this->centralAPIHelper->ensureVrfForRoutedInterface($this->deviceInterface);
                if (isset($vrfResult['error'])) {
                    $message = 'Failed to ensure VRF '.$this->deviceInterface->vrf_forwarding.' for interface '.$this->deviceInterface->interface.': '.$vrfResult['error'];
                    $this->task->processTaskStatusLog($message, true);
                    Log::error($message);
                    $this->fail();

                    return;
                }
                if ($vrfResult['created'] ?? false) {
                    $message = 'Created VRF '.$this->deviceInterface->vrf_forwarding.' before updating interface '.$this->deviceInterface->interface;
                    $this->task->processTaskStatusLog($message);
                    Log::info($message);
                }

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
        }, 'Update ethernet interface');
    }

    public function failed(?Throwable $exception): void
    {
        $this->logFailedException($exception);
        $this->markInterfaceFailed($this->deviceInterface);
        $this->task->processTaskStatusLog($exception);
    }
}
