<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\Site;
use App\Models\Task;
use DateTime;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class AssociateDeviceToSiteJob implements ShouldQueue
{
    use Queueable, Batchable;

    public int $deployment_time;
    public int $wait_time;
    /**
     * Create a new job instance.
     */
    public function __construct(public Device $device, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->deployment_time = $task->deployment_time > 0 ? $task->deployment_time : 3;
        $this->wait_time = $task->wait_time > 0 ? $task->wait_time : 3;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $device_type = $this->get_device_type($this->device->device_function);
        $this->populate_classic_site_id();
        $device_site_association_body = [
            'site_id' => $this->device->site->classic_id,
            'device_type' => $device_type,
            'device_id' => $this->device->serial,
        ];
        $response = $this->centralAPIHelper->classic_associate_device_to_site($device_site_association_body);
        if (! $response->ok()) {
            Log::error('Failed to associate devices to site');
            $this->release($this->wait_time * 60);
        }
        else {
            $status_log = $this->task->status_log;
            $new_log = $status_log . '\nDevice ' . $this->device->name . ' associated to site ' . $this->site->name;
            $this->task->update(['status_log' => $new_log]);
        }
    }

    public function get_device_type($device_function)
    {
        switch ($device_function) {
            case str_contains($device_function, 'SWITCH'):
                return 'SWITCH';
            case str_contains($device_function, 'AP'):
                return 'IAP';
            default: return 'CONTROLLER';
        }
    }

    public function populate_classic_site_id()
    {
        if ($this->device->site->classic_id) {
            return;
        }
        $sites = $this->centralAPIHelper->classic_get_sites();
        if (! $sites->ok()) {
            Log::error($sites->json('message'));
            $this->fail();
        }

        else {
            $site_list = $sites->json('sites');
            $classic_site = array_find($site_list, fn($site) => $site['site_name'] == $this->device->site->name);
            if (!$classic_site) {
                $error_message = 'Site ' . $this->device->site->name . ' not found in classic';
                Log::error($error_message);
                $this->fail($error_message);
            }
            else {
                $this->device->site->update(['classic_id' => $classic_site['site_id']]);
            }
        }
    }

    public function retryUntil(): DateTime
    {
        return now()->addMinutes($this->deployment_time)->toDateTime();
    }
}
