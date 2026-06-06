<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\InterfaceKind;
use App\Models\DeviceInterface;
use App\Models\Task;
use Illuminate\Support\Facades\Log;
use Throwable;

class ConfigureVlanInterfaceJob extends BaseTaskJob
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
                $message = 'No scope id found for device '.$device->name.', getting scope id from Central...';
                Log::info($message);
                $this->task->processTaskStatusLog($message);
                $scopeid_response = $this->centralAPIHelper->getScopeIdFromCentral($device);
                if (array_key_exists('error', $scopeid_response)) {
                    $error_message = 'Failed to get scope id for device '.$device->name;
                    Log::error($error_message);
                    $this->task->processTaskStatusLog($error_message, true);

                    return;
                }
                $message = 'Scope id found for device '.$device->name.', updating device...';
                Log::info($message);
                $this->task->processTaskStatusLog($message);
                $device->scope_id = $scopeid_response[0]['scopeId'];
                $device->save();
            }
            $message = 'Getting vlans available in the library...';
            Log::info($message);
            $this->task->processTaskStatusLog($message);
            // get vlans available in the library
            $l2_vlans_response = $this->centralAPIHelper->get_l2_vlans();
            if (! $l2_vlans_response->ok()) {
                $error_message = '\nFailed to retrieve VLANs from the library... continuing anyways.';
                Log::error($l2_vlans_response->json('message'));
                $this->task->processTaskStatusLog($error_message);
            } else {
                $message = 'Vlans available in the library found, getting vlan...';
                Log::info($message);
                $this->task->processTaskStatusLog($message);
                $l2_vlans = $l2_vlans_response->json('l2-vlan');
                $found_vlan = array_find($l2_vlans, fn ($vlan) => $vlan['vlan'] === (int) $this->deviceInterface->interface);
                if (! $found_vlan) {
                    $error_message = '\nVLAN not found in library. Aborting...';
                    Log::error($error_message);
                    $this->task->processTaskStatusLog($error_message);

                    return;
                } else {
                    $message = 'Vlan found in the library, overriding vlan...';
                    Log::info($message);
                    $this->task->processTaskStatusLog($message);
                    // do a device level override of the vlan
                    $query_params = [
                        'view-type' => 'LOCAL',
                        'object-type' => 'LOCAL',
                        'scope-id' => $device->scope_id,
                        'device-function' => $device->device_function,
                    ];
                    $override_response = $this->centralAPIHelper->post_l2_vlan($query_params, ['vlan' => $this->deviceInterface->interface]);
                    if (! $override_response->ok()) {
                        // check if there is already a local override of the vlan
                        if (str_contains($override_response->json()['message'], 'Cannot create duplicate config')) {
                            $message = '\nVLAN already assigned to device scope for '.$device->name;
                            Log::info($message);
                            $this->task->processTaskStatusLog($message);

                        } else {
                            $error_message = '\nFailed to override VLAN.'.$override_response->json()['message'].' Aborting...';
                            Log::error($error_message);
                            $this->task->processTaskStatusLog($error_message);

                            return;
                        }
                    } else {
                        $success_message = '\nVLAN overridden successfully for '.$this->deviceInterface->interface;
                        Log::info($success_message);
                        $this->task->processTaskStatusLog($success_message);
                    }
                }
            }
            $message = 'Creating vlan interface...';
            Log::info($message);
            $this->task->processTaskStatusLog($message);
            // create the vlan interface
            $vlan_interface_response = $this->centralAPIHelper->post_vlan_interface($this->deviceInterface);
            if (! $vlan_interface_response->ok()) {
                if (str_contains($vlan_interface_response->json()['message'], 'Cannot create duplicate config')) {
                    $info_message = '\nVlan interface '.$this->deviceInterface->interface.' already exists for device';
                    Log::info($info_message);
                    $this->task->processTaskStatusLog($info_message);
                    $patch_vlan_interface_response = $this->centralAPIHelper->patch_vlan_interface($this->deviceInterface);
                    if (! $patch_vlan_interface_response->ok()) {
                        $error_message = '\nFailed to patch vlan interface.';
                        Log::error($error_message);
                        $this->task->processTaskStatusLog($error_message, true);
                        $this->release($this->wait_time * 60);

                        return;
                    } else {
                        $success_message = '\nSuccessfully patched vlan interface for .'.$this->deviceInterface->device;
                        $this->task->processTaskStatusLog($success_message);
                        $this->markInterfaceCompleted();

                        return;
                    }
                }
                $error_message = '\nFailed to post vlan interface.';
                Log::error($error_message);
                $this->task->processTaskStatusLog($error_message, true);
                $this->release($this->wait_time * 60);
            } else {
                $success_message = '\nVLAN interface posted successfully for VLAN '.$this->deviceInterface->interface;
                Log::info($success_message);
                $this->task->processTaskStatusLog($success_message);
                $this->markInterfaceCompleted();
            }
        }, 'Configure VLAN interface');
    }

    protected function markInterfaceCompleted(): void
    {
        $this->task->deviceInterfaces()->find($this->deviceInterface)?->pivot?->update(['status' => 'COMPLETED']);
        $this->task->load('deviceInterfaces');
        if ($this->task->allTrackedItemsCompleted()) {
            $this->task->update(['status' => 'COMPLETED']);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->logFailedException($exception);
        $this->failInterfaceAndTaskIfNeeded(
            $this->deviceInterface,
            fn ($interface) => $interface->interface_kind === InterfaceKind::VLAN,
            fn ($interface) => $interface->pivot->status === 'FAILED' && $interface->interface_kind === InterfaceKind::VLAN
        );
    }
}
