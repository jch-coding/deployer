<?php

namespace App\Jobs;

use App\Events\CentralAPIFail;
use App\Events\DeviceConfigFailedEvent;
use App\Events\DeviceGetScopeIdEvent;
use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\Task;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class UpdateSystemInfo implements ShouldQueue
{
    use Queueable, Batchable;

    /**
     * Create a new job instance.
     */
    public function __construct(public Device $device, public Task $task)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $centralAPIHelper = new CentralAPIHelper($this->device->client);
        $pivotForDevice = $this->task->devices()->find($this->device)->pivot;

        if(!$this->device->scope_id || $pivotForDevice->status === 'FAILED') {
            DeviceGetScopeIdEvent::dispatch($this->device);
            $scope_id_response = $centralAPIHelper->getScopeIdFromCentral($this->device);
            if(!count($scope_id_response) > 0) {
                CentralAPIFail::dispatch($this->device);
                $this->release(10);
                return;
            }
            $this->device->update(['scope_id' => $scope_id_response[0]['scopeId']]);
        }

        $response = $centralAPIHelper->updateSystemInfo($this->device);
        if ($response->status() == 200) {

            $pivotForDevice->update(['status' => 'COMPLETED']);
        }
        else {
            DeviceConfigFailedEvent::dispatch($this->device);
            $pivotForDevice->update(['status' => 'FAILED']);
            $this->release(10);
        }
    }
}
