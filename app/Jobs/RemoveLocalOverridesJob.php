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

class RemoveLocalOverridesJob implements ShouldQueue
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
        if (! $this->device->scope_id) {
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
        //remove local vlans except vlan 1
        $l2_vlan_response = $this->centralAPIHelper->get_l2_vlans($query_parameters);
        if ($l2_vlan_response->ok()) {
            $l2_vlans = $l2_vlan_response->json()['l2-vlan'];
            $override_vlans = array_filter($l2_vlans, fn($vlan) => $vlan['vlan'] != 1);
            $success = 0;
            array_map(function ($vlan_array) use (&$message, &$success) {
                $delete_response = $this->centralAPIHelper->delete_l2_vlan($this->device, $vlan_array['vlan']);
                if ($delete_response->ok()) {
                    $success++;
                } else {
                    $message = "\nFailed to delete vlan {$vlan_array['vlan']}: {$delete_response->json()['message']}";
                    $this->task->processTaskStatusLog($message, true);
                }
            }, $override_vlans);
            } else {
            $message = "\nFailed to get local override vlans: {$l2_vlan_response->json()['message']}";
            $this->task->processTaskStatusLog($message, true);
            return;
        }
        //remove local dns
        $dns_response = $this->centralAPIHelper->get_dns_profiles($query_parameters);
        if ($dns_response->ok()) {
            $dns_profile_name = $dns_response->json()['profile']['name'];
            $delete_dns_response = $this->centralAPIHelper->delete_dns_profile($dns_profile_name);
            if (! $delete_dns_response->ok()) {
                $message = "\nFailed to delete dns profile: {$delete_dns_response->json()['message']}";
                $this->task->processTaskStatusLog($message, true);
                return;
            }
        }

    }

    public function retryUntil(): DateTime
    {
        return now()->addMinutes($this->deployment_time)->toDateTime();
    }

    public function failed(?Throwable $exception): void
    {
        Log::error($exception);
        $message = "\nFailed to delete all local override dns profiles or task timed out. Please check Central for more details.";
        $this->task->processTaskStatusLog($message, true);
        $this->task->devices()->find($this->device)->pivot->update(['status' => 'FAILED']);
        $failed_devices = $this->task->devices->filter(fn ($device) => $device->pivot->status == 'FAILED')->count();
        if ($failed_devices == $this->task->devices->count()) {
            $this->task->update(['status' => 'FAILED']);
        }
    }
}
