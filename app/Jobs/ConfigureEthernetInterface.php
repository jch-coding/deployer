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
        $interface_response = $this->centralAPIHelper->patch_ethernet_interface($this->deviceInterface->load(['switch_port','lacp_profile','stp_profile','sw_profile']));
        if(!$interface_response->ok()) {
            Log::error('Failed to patch ethernet interface: '.$this->deviceInterface->name.' on device '.$this->deviceInterface->device->name.' with error: '.$interface_response->json());
            $this->release();
        }
        $statusMessage = 'interface '.$this->deviceInterface->name.' configured';
        if($this->deviceInterface->sw_profile)
            $statusMessage .= ' with '.$this->deviceInterface->sw_profile->name.' profile';
        DeploymentEvent::dispatch([
            'deployment_name' => $this->task->deployment->name,
            'device_name' => $this->deviceInterface->name,
            'task_type' => $this->task->task_type,
            'status' => $statusMessage,
        ]);
    }
}
