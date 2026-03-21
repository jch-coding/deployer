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
    /**
     * Create a new job instance.
     */
    public function __construct(public $device, public Site $site, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->deployment_time = $task->deployment_time > 0 ? $task->deployment_time : 10;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $device_type = $this->get_device_type($this->device->device_function);
        $device_site_association_body = [
            'site_id' => $this->site->classic_id,
            'device_type' => $device_type,
            'device_id' => $this->device->serial,
        ];
        $response = $this->centralAPIHelper->classic_associate_device_to_site($device_site_association_body);
        if (! $response->ok()) {
            Log::error('Failed to associate devices to site');
            $this->release(random_int(1, 10));
        }
        $status_log = $this->task->status_log;
        $new_log = $status_log.'\nDevice '.$this->device->name.' associated to site '.$this->site->name;
        $this->task->update(['status_log' => $new_log]);
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

    public function retryUntil(): DateTime
    {
        return now()->addMinutes($this->deployment_time)->toDateTime();
    }
}
