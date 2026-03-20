<?php

namespace App\Jobs;

use App\Events\DeploymentEvent;
use App\Events\FailureEvent;
use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\Task;
use DateTime;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class UpdateSystemInfo implements ShouldQueue
{
    use Queueable, Batchable;

    public int $deployment_time;

    /**
     * Create a new job instance.
     */
    public function __construct(public Device $device, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->deployment_time = $task->deployment_time > 0 ? $task->deployment_time : 10;
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

        $statusLog = $this->task->status_log;

        $response = $this->centralAPIHelper->updateSystemInfo($this->device);
        if ($response->status() == 200) {
            $newStatusLog = $statusLog . "\nSystem info for " . $this->device->name . " updated successfully at " . now()->format('Y-m-d H:i:s') . "\n";
            $this->task->update(['status_log' => $newStatusLog]);
            sleep(random_int(1, 5));
            DeploymentEvent::dispatch([
                'deployment_name' => $this->task->deployment->name,
                'item_name' => $this->device->id,
                'task_type' => $this->task->task_type,
                'message' => 'System info for ' . $this->device->name . ' updated',
                'event_type' => 'deployment_event',
            ]);
            $pivotForDevice->update(['status' => 'COMPLETED']);
            Log::info('System info for ' . $this->device->name . ' updated successfully');
        }
        else {
            $newStatusLog = $statusLog . "\nFailed to update system infor for device " . $this->device->name;
            $this->task->update(['status_log' => $newStatusLog]);
            Log::error('Failed to update system info for device ' . $this->device->name);
            sleep(300);
            $this->release();
        }
    }

    public function retryUntil(): DateTime
    {
        return now()->addMinutes($this->deployment_time)->toDateTime();
    }

    public function failed(?Throwable $exception): void
    {
        Log::error($exception);
        $this->task->devices()->find($this->device)->pivot->update(['status' => 'FAILED']);
        $statusLog = $this->task->status_log;
        $newStatusLog = $statusLog . "\nFailed updating system info for " . $this->device->name . " at " . now()->format('Y-m-d H:i:s') . "\n";
        $this->task->update(['status_log' => $newStatusLog]);
        FailureEvent::dispatch([
            'deployment_name' => $this->task->deployment->name,
            'item_name' => $this->device->id,
            'task_type' => $this->task->task_type,
            'message' => 'Failed updating system info for ' . $this->device->name,
            'event_type' => 'failure_event',
        ]);
    }
}
