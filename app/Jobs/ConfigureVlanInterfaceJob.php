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

class ConfigureVlanInterfaceJob implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * Create a new job instance.
     */
    public $deployment_time;

    public $wait_time;

    public function __construct(public DeviceInterface $deviceInterface, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->deployment_time = $task->deployment_time ?? 3;
        $this->wait_time = $task->wait_time ?? 1;
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
        // get vlans available in the library
        $l2_vlans_response = $this->centralAPIHelper->get_l2_vlans();
        if (! $l2_vlans_response->ok()) {
            $error_message = '\nFailed to retrieve VLANs from the library... continuing anyways.';
            Log::error($l2_vlans_response->json('message'));
            $this->task->update(['status_log' => $this->task->status_log.$error_message]);
        } else {
            $l2_vlans = $l2_vlans_response->json('l2-vlan');
            $found_vlan = array_find($l2_vlans, fn ($vlan) => $vlan['vlan'] === (int) $this->deviceInterface->interface);
            if (! $found_vlan) {
                $error_message = '\nVLAN not found in library. Aborting...';
                Log::error($error_message);
                $this->task->update(['status_log' => $this->task->status_log.$error_message]);
                $this->fail($error_message);
            } else {
                // do a device level override of the vlan
                $override_response = $this->centralAPIHelper->post_l2_vlan($device, ['vlan' => $this->deviceInterface->interface]);
                if (! $override_response->ok()) {
                    // check if there is already a local override of the vlan
                    if (str_contains($override_response->json()['message'], 'Cannot create duplicate config')) {
                        Log::info('VLAN already assigned to device scope for '.$device->name);
                    } else {
                        $error_message = '\nFailed to override VLAN. Aborting...';
                        Log::error($error_message);
                        $this->fail($error_message);
                        $this->task->update(['status_log' => $this->task->status_log.$error_message]);
                    }
                } else {
                    $success_message = '\nVLAN overridden successfully for '.$this->deviceInterface->interface;
                    Log::info($success_message);
                    $this->task->update(['status_log' => $this->task->status_log.$success_message]);
                }
            }
        }
        // create the vlan interface
        $vlan_interface_response = $this->centralAPIHelper->post_vlan_interface($this->deviceInterface);
        if (! $vlan_interface_response->ok()) {
            if (str_contains($vlan_interface_response->json()['message'], 'Cannot create duplicate config')) {
                $info_message = '\nVlan interface '.$this->deviceInterface->interface.' already exists for device';
                Log::info($info_message);
                $this->task->update(['status_log' => $this->task->status_log.$info_message]);

                return;
            }
            $error_message = '\nFailed to post vlan interface.';
            Log::error($error_message);
            $this->task->update(['status_log' => $this->task->status_log.$error_message]);
            $this->release($this->wait_time * 60);
        } else {
            $success_message = '\nVLAN interface posted successfully for VLAN '.$this->deviceInterface->interface;
            Log::info($success_message);
            $this->task->update(['status_log' => $this->task->status_log.$success_message]);
            $this->task->deviceInterfaces()->find($this->deviceInterface)->pivot->update(['status' => 'COMPLETED']);
        }
    }

    public function retryUntil(): DateTime
    {
        return now()->addMinutes($this->deployment_time)->toDateTime();
    }
}
