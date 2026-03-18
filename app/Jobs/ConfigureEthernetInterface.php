<?php

namespace App\Jobs;

use App\Events\DeploymentEvent;
use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\Task;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ConfigureEthernetInterface implements ShouldQueue
{
    use Queueable, Batchable;

    public $tries = 20;

    /**
     * Create a new job instance.
     */
    public function __construct(public DeviceInterface $deviceInterface, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $device = $this->deviceInterface->device;
        if(!$device->scope_id) {
            $scopeid_response = $this->centralAPIHelper->getScopeIdFromCentral($device);
            if(array_key_exists('error', $scopeid_response)) {
                return;
            }
            $device->scope_id = $scopeid_response[0]['scopeId'];
            $device->save();
        }
        $interface_response = $this->centralAPIHelper->patch_ethernet_interface($this->deviceInterface);
        if(!$interface_response->ok()) {
            Log::error('Failed to patch ethernet interface: '.$this->deviceInterface->name.' on device '.$device->name.' with message:'.$interface_response->json()['message']);
        }
        $statusMessage = 'interface '.$this->deviceInterface->name.' configured';
        if($this->deviceInterface->sw_profile)
            $statusMessage .= ' with '.$this->deviceInterface->sw_profile.' profile';
        $this->task->devices()->find($device)->pivot->update(['status' => 'COMPLETED']);
        DeploymentEvent::dispatch([
            'deployment_name' => $this->task->deployment->name,
            'device_name' => $this->deviceInterface->name,
            'task_type' => $this->task->task_type,
            'status' => $statusMessage,
        ]);
    }
}
