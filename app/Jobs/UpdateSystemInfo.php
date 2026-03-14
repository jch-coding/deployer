<?php

namespace App\Jobs;

use App\Events\CentralAPIFail;
use App\Events\DeploymentEvent;
use App\Events\DeviceConfigFailedEvent;
use App\Events\DeviceGetScopeIdEvent;
use App\Events\DeviceSystemInfoUpdateEvent;
use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\Task;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class UpdateSystemInfo implements ShouldQueue
{
    use Queueable, Batchable;

    /**
     * Create a new job instance.
     */
    public function __construct(public Device $device, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $pivotForDevice = $this->task->devices()->find($this->device)->pivot;

        if(!$this->device->scope_id || $pivotForDevice->status === 'FAILED') {
            $scope_id_response = $this->centralAPIHelper->getScopeIdFromCentral($this->device);
            if(array_key_exists('error', $scope_id_response)) {
                Log::error('Failed to retrieve scope ID for device ' . $this->device->name);
            }
            $this->device->scope_id = $scope_id_response[0]['scopeId'];
            $this->device->save();
        }

        $response = $this->centralAPIHelper->updateSystemInfo($this->device);
        if ($response->status() == 200) {
            DeploymentEvent::dispatch([
                'deployment_name' => $this->task->deployment->name,
                'device_id' => $this->device->id,
                'task_type' => $this->task->task_type,
                'message' => 'System info for ' . $this->device->name . ' updated'
            ]);
            $pivotForDevice->update(['status' => 'COMPLETED']);
            Log::info('System info for ' . $this->device->name . ' updated successfully');
        }
        else {
            Log::error('Failed to update system info for device ' . $this->device->name);
            $pivotForDevice->update(['status' => 'FAILED']);
        }
    }
}
