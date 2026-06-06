<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\DeviceInterface;
use App\Models\Task;
use Illuminate\Support\Facades\Log;
use Throwable;

class ConfigureEthernetInterface extends BaseTaskJob
{
    /**
     * Create a new job instance.
     */
    public function __construct(public DeviceInterface $deviceInterface, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->initTaskTiming($task, defaultWaitMinutes: 1);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->handleSafely(function (): void {
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
            // checks current interface configuration for any associated port profiles
            $get_response = $this->centralAPIHelper->get_ethernet_interface($this->deviceInterface);
            if (! $get_response->ok()) {
                $message = '\nFailed to retrieve ethernet interface: '.$this->deviceInterface->interface.' Trying to patch anyways. This will fail if a port profile is already associated.';
                $this->task->processTaskStatusLog($message, true);
                Log::info($message);
            } else {
                // if sw-profile exists, there is a port-profile associated
                if (array_key_exists('sw-profile', $get_response->json())) {
                    $message = '\nEthernet interface '.$this->deviceInterface->interface.' on device '.$device->name.' has a port profile associated. Removing port profile before patching interface.';
                    $this->task->processTaskStatusLog($message);
                    Log::info($message);

                    $patch_body = [
                        'sw-profile' => null,
                    ];
                    $interface_response = $this->centralAPIHelper->patch_ethernet_interface($this->deviceInterface, $patch_body);
                    if (! $interface_response->ok()) {
                        $message = '\nFailed to remove port profile from ethernet interface: '.$this->deviceInterface->interface.' on device '.$device->name.' with message:'.$interface_response->json()['message'].' Aborting configuration pus for interface.';
                        $this->task->processTaskStatusLog($message, true);
                        Log::error($message);
                        $this->fail();

                        return;
                    } else {
                        $message = "\nPort profile ".$get_response->json()['sw-profile'].' removed from ethernet interface: '.$this->deviceInterface->interface.' on device '.$device->name;
                        $this->task->processTaskStatusLog($message);
                        Log::info($message);
                    }
                }
            }

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
                $message = 'Created VRF '.$this->deviceInterface->vrf_forwarding.' before configuring interface '.$this->deviceInterface->interface;
                $this->task->processTaskStatusLog($message);
                Log::info($message);
            }

            $interface_response = $this->centralAPIHelper->patch_ethernet_interface($this->deviceInterface);
            if (! $interface_response->ok()) {
                $message = 'Failed to patch ethernet interface: '.$this->deviceInterface->interface.' ondevice '.$device->name.' with message:'.$interface_response->json()['message'];
                $this->task->processTaskStatusLog($message, true);
                Log::error('Failed to patch ethernet interface: '.$this->deviceInterface->interface.' on device '.$device->name.' with message:'.$interface_response->json()['message']);
                $this->release($this->wait_time * 60);
            } else {
                $message = 'Interface '.$this->deviceInterface->interface.' configured on device '.$device->name;
                if ($this->deviceInterface->sw_profile) {
                    $message .= ' with '.$this->deviceInterface->sw_profile.' profile';
                }
                $this->task->deviceInterfaces()->find($this->deviceInterface)->pivot->update(['status' => 'COMPLETED']);
                $deviceInterfaces = $this->task->deviceInterfaces->filter(fn ($deviceInterface) => $deviceInterface->device_id === $device->id);
                $completedDeviceInterfaces = $deviceInterfaces->filter(fn ($deviceInterface) => $deviceInterface->pivot->status === 'COMPLETED');
                if ($completedDeviceInterfaces->count() === $deviceInterfaces->count()) {
                    $this->task->devices()->find($device)->pivot->update(['status' => 'COMPLETED']);
                }
                $this->task->processTaskStatusLog($message);
            }
        }, 'Configure ethernet interface');
    }

    public function failed(?Throwable $exception): void
    {
        $this->logFailedException($exception);
        $this->failInterfaceAndTaskIfNeeded(
            $this->deviceInterface,
            fn ($interface) => str_contains($interface->interface, '/'),
            fn ($interface) => $interface->pivot->status === 'FAILED' && str_contains($interface->interface, '/')
        );
    }
}
