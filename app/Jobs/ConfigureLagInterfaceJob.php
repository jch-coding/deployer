<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\DeviceInterface;
use App\Models\Task;
use Illuminate\Support\Facades\Log;
use Throwable;

class ConfigureLagInterfaceJob extends BaseTaskJob
{
    /**
     * Create a new job instance.
     */
    public function __construct(public DeviceInterface $device_interface, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->initTaskTiming($task, defaultDeploymentMinutes: 3, defaultWaitMinutes: 3);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->handleSafely(function (): void {
            $device = $this->device_interface->device;
            if (! $device->scope_id) {
                $message = '\nScope ID not found for '.$this->device_interface->device->name;
                Log::info($message);
                $this->task->processTaskStatusLog($message);
                $scopeid_response = $this->centralAPIHelper->getScopeIdFromCentral($device);
                if (array_key_exists('error', $scopeid_response)) {
                    $message = 'Failed to get scope id for device '.$device->name;
                    Log::error($message);
                    $this->task->processTaskStatusLog($message, true);

                    return;
                }
                $message = '\nGot Scope ID from Central for '.$this->device_interface->device->name;
                Log::info($message);
                $this->task->processTaskStatusLog($message);
                $device->scope_id = $scopeid_response[0]['scopeId'];
                $device->save();
            }
            $message = '\nCreating LAG for '.$this->device_interface->device->name;
            Log::info($message);
            $this->task->processTaskStatusLog($message);
            $response = $this->centralAPIHelper->post_interface_portchannel($this->device_interface);
            if (! $response->ok()) {
                Log::error($response->json('message'));
                $this->task->processTaskStatusLog($response->json('message'), true);
                $this->release($this->wait_time * 60);

                return;
            }
            if ($this->device_interface->sw_profile) {
                $message = '\nFound switchport configuration for LAG. Applying switchport configuration to LAG for '.$this->device_interface->device->name;
                Log::info($message);
                $this->task->processTaskStatusLog($message);
                $patch_response = $this->centralAPIHelper->patch_interface_portchannel($this->device_interface);
                if (! $patch_response->ok()) {
                    $message = 'Failed to apply port profile to interface LAG .'.$this->device_interface->interface.': '.$patch_response->json('message');
                    $this->task->processTaskStatusLog($message, true);
                    $this->release($this->wait_time * 60);

                    return;
                }
            }
            $message = 'Configured LAG '.$this->device_interface->interface.' for '.$this->device_interface->device->name;
            $this->task->processTaskStatusLog($message);
            $this->task->deviceInterfaces()->find($this->device_interface)->pivot->update(['status' => 'COMPLETED']);
        }, 'Configure LAG interface');
    }

    public function failed(?Throwable $exception)
    {
        $this->logFailedException($exception);
        $this->failInterfaceAndTaskIfNeeded(
            $this->device_interface,
            fn ($interface) => (bool) $interface->lacp_profile_id,
            fn ($interface) => $interface->pivot->status === 'FAILED' && (bool) $interface->lacp_profile_id
        );
    }
}
