<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\Task;
use DateTime;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RemoveLocalOverrideNTPJob implements ShouldQueue
{
    use Queueable, Batchable;
    public int $deployment_time;
    public int $wait_time;

    /**
     * Create a new job instance.
     */
    public function __construct(public Task $task, public Device $device, public CentralAPIHelper $centralAPIHelper)
    {
        $this->deployment_time = $task->deployment_time ?? 3;
        $this->wait_time = $task->wait_time ?? 3;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        //refresh device scope-id
        if (!$this->device->scope_id) {
            $scopeid_response = $this->centralAPIHelper->getScopeIdFromCentral($this->device);
            if (array_key_exists('error', $scopeid_response)) {
                $message = '\nFailed to get scope-id from Central.';
                $this->task->processTaskStatusLog($message, true);
                return;
            } else {
                $scope_id = array_pop($scopeid_response)['scopeId'];
                $this->device->scope_id = $scope_id;
                $this->device->save();
            }
        }
        //build device specific query parameter
        $query_parameters = [
            'view-type' => 'LOCAL',
            'object-type' => 'LOCAL',
            'scope-id' => $this->device->scope_id,
            'device-function' => $this->device->device_function
        ];

        //remove local ntp
        $ntp_response = $this->centralAPIHelper->get_ntp_profiles($query_parameters);
        if ($ntp_response->ok()) {
            $ntp_profile_name = array_pop($ntp_response->json()['profile'])['name'];
            $delete_ntp_response = $this->centralAPIHelper->delete_ntp_profile($ntp_profile_name, $query_parameters);
            if (! $delete_ntp_response->ok()) {
                $message = "\nFailed to delete ntp profile: {$delete_ntp_response->json()['message']}";
                $this->task->processTaskStatusLog($message, true);
                $this->release($this->wait_time * 60);
            } else {
                $message = "\nDeleted ntp profile: {$ntp_profile_name}";
                $this->task->processTaskStatusLog($message);
                $this->task->devices()->find($this->device)->pivot->update(['status' => 'COMPLETED']);
                $completed_devices = $this->task->devices->filter(fn ($device) => $device->pivot->status === 'COMPLETED');
                if ($completed_devices->count() === $this->task->devices->count()) {
                    $this->task->update(['status' => 'COMPLETED']);
                }
            }
        } else {
            $message = "\nFailed to get local override ntp profiles: {$ntp_response->json()['message']}";
            $this->task->processTaskStatusLog($message, true);
            $this->release($this->wait_time * 60);
        }
    }

    public function retryUntil(): DateTime
    {
        return now()->addMinutes($this->deployment_time)->toDateTime();
    }

    public function failed(?Throwable $exception): void
    {
        Log::error($exception);
        $message = "\nFailed to delete all local override ntp profiles or task timed out. Please check Central for more details.";
        $this->task->processTaskStatusLog($message, true);
        $this->task->devices()->find($this->device)->pivot->update(['status' => 'FAILED']);
        $failed_devices = $this->task->devices->filter(fn ($device) => $device->pivot->status == 'FAILED')->count();
        if ($failed_devices == $this->task->devices->count()) {
            $this->task->update(['status' => 'FAILED']);
        }
    }
}

