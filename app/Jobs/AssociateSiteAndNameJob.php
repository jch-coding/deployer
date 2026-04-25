<?php

namespace App\Jobs;

use App\Jobs\Concerns\HandlesUncaughtTaskExceptions;
use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\Task;
use DateTime;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class AssociateSiteAndNameJob implements ShouldQueue
{
    use Batchable, HandlesUncaughtTaskExceptions, Queueable;

    /**
     * Create a new job instance.
     */
    public int $deployment_time;

    public int $wait_time;
    public int $tries = 1;

    public function __construct(public Device $device, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->deployment_time = $task->deployment_time ?? 3;
        $this->wait_time = $task->wait_time ?? 3;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // check that site has a classic central id
            if (! $this->device->site->classic_id) {
                // get the classic central id
                $classic_sites = $this->centralAPIHelper->classic_get_sites();
                if (! $classic_sites->ok()) {
                    Log::error($classic_sites->json('message'));
                    $this->fail();
                }
                $site_list = $classic_sites->json('sites');
                $found_site = array_find($site_list, fn ($classic_site) => $classic_site['site_name'] === $this->device->site->name);
                if (! $found_site) {
                    Log::error('Site '.$this->device->site->name.' not found in classic central');
                    $this->fail();
                } else {
                    $this->device->site->classic_id = $found_site['site_id'];
                    $this->device->site->save();
                }
            }
            // assign device to the classic site
            $classic_device_type = $this->get_device_type($this->device->device_function);
            if (! $this->device->scope_id) {
                $device_site_association_body = [
                    'device_id' => $this->device->serial,
                    'device_type' => $classic_device_type,
                    'site_id' => $this->device->site->classic_id,
                ];
                $response = $this->centralAPIHelper->classic_associate_device_to_site($device_site_association_body);
                if (! $response->ok()) {
                    $message = 'Failed to associate device '.$this->device->name.' to site';
                    Log::error($message);
                    $this->task->processTaskStatusLog($message, true);
                    $this->release(60 * $this->wait_time);
                } else {
                    $status_log = $this->task->status_log;
                    $new_log = $status_log.'\nAssociated '.$this->device->name.' to site '.$this->device->site->name;
                    $this->task->update(['status_log' => $new_log]);
                    // now name the device through new Central. Get the device scope id.
                    sleep(5);
                }
                $scope_id_object = $this->centralAPIHelper->getScopeIdFromCentral($this->device);
                if (array_key_exists('error', $scope_id_object)) {
                    $message = 'Failed to get scope id for device '.$this->device->name;
                    Log::error($message);
                    $this->task->processTaskStatusLog($message, true);
                    $this->release(60 * $this->wait_time);
                } else {
                    $scope_id = $scope_id_object[0]['scopeId'];
                    $this->device->scope_id = $scope_id;
                    $this->device->save();
                }
            }
            // name the device through new Central.
            $response = $this->centralAPIHelper->postSystemInfo($this->device);
            if (! $response->ok()) {
                $message = 'Failed to name '.$this->device->name;
                Log::error($response->json('message'));
                $this->task->processTaskStatusLog($message, true);
                $this->release(60 * $this->wait_time);
            } else {
                $message = '\nNamed '.$this->device->serial.' as '.$this->device->name;
                $this->task->processTaskStatusLog($message);
                $this->task->devices()->find($this->device)->pivot->update(['status' => 'COMPLETED']);
            }
        } catch (Throwable $exception) {
            $this->failTaskOnUnhandledException($exception, 'Associate site and name');
        }
    }

    public function get_device_type($device_function): string
    {
        switch ($device_function) {
            case str_contains($device_function, 'SWITCH'): return 'SWITCH';
            case str_contains($device_function, 'AP'): return 'IAP';
            default: return 'CONTROLLER';
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
        $failed_devices = $this->task->devices->filter(fn ($device) => $device->pivot->status == 'FAILED')->count();
        $total_devices = $this->task->devices->count();
        if ($failed_devices === $total_devices) {
            $this->task->update(['status' => 'FAILED']);
        }
        $this->task->processTaskStatusLog('Task timed out or failed.');
    }
}
