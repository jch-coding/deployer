<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\Task;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class AssociateDeviceToSiteJob extends BaseTaskJob
{
    /**
     * Create a new job instance.
     */
    public function __construct(public Device $device, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->initTaskTiming($task, defaultDeploymentMinutes: 3, defaultWaitMinutes: 1);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->handleSafely(function (): void {
            $device_type = $this->get_device_type($this->device->device_function);
            if (! $this->populate_classic_site_id()) {
                return;
            }

            $this->device->load('site');

            $device_site_association_body = [
                'site_id' => $this->device->site->classic_id,
                'device_type' => $device_type,
                'device_id' => $this->device->serial,
            ];
            $response = $this->centralAPIHelper->classic_associate_device_to_site($device_site_association_body);
            if (is_array($response) || ! $response instanceof Response || ! $response->ok()) {
                $detail = $this->formatClassicAssociateError($response);
                Log::error('Failed to associate device to site: '.$detail);
                $this->task->processTaskStatusLog('Failed to associate device '.$this->device->name.' to site', true);
                $this->release($this->wait_time * 60);

                return;
            }

            $siteName = $this->device->site?->name ?? 'site';
            $status_log = $this->task->status_log;
            $new_log = $status_log.'\nDevice '.$this->device->name.' associated to site '.$siteName;
            $this->task->update(['status_log' => $new_log]);

            $this->task->devices()->find($this->device)?->pivot?->update(['status' => 'COMPLETED']);
            $this->task->load('devices');
            if ($this->task->allTrackedItemsCompleted()) {
                $this->task->update(['status' => 'COMPLETED']);
            }
        }, 'Associate device to site');
    }

    public function get_device_type($device_function): string
    {
        if (str_contains((string) $device_function, 'SWITCH')) {
            return 'SWITCH';
        }
        if (str_contains((string) $device_function, 'AP')) {
            return 'IAP';
        }

        return 'CONTROLLER';
    }

    /**
     * Ensure {@see Device::$site} has {@see Site::$classic_id} from Classic Central when missing.
     *
     * @return bool False when lookup failed and the job should stop (queue fail was signaled).
     */
    public function populate_classic_site_id(): bool
    {
        if ($this->device->site->classic_id) {
            return true;
        }

        $sites_result = $this->centralAPIHelper->classic_collect_all_sites();
        if (isset($sites_result['error'])) {
            Log::error($sites_result['error']);
            $this->fail($sites_result['error']);

            return false;
        }

        $site_list = $sites_result['sites'];
        $classic_site = array_find($site_list, fn ($site) => $site['site_name'] == $this->device->site->name);
        if (! $classic_site) {
            $error_message = 'Site '.$this->device->site->name.' not found in classic';
            Log::error($error_message);
            $this->fail($error_message);

            return false;
        }

        $this->device->site->update(['classic_id' => $classic_site['site_id']]);
        $this->device->site->refresh();

        return true;
    }

    private function formatClassicAssociateError(mixed $response): string
    {
        if (is_array($response)) {
            return $response['error'] ?? json_encode($response);
        }

        if ($response instanceof Response) {
            $json = $response->json();
            if (is_array($json) && isset($json['message'])) {
                return (string) $json['message'];
            }

            return $response->body();
        }

        return 'unknown error';
    }

    public function failed(?Throwable $exception): void
    {
        $this->logFailedException($exception);
        $this->failDeviceAndTaskIfNeeded($this->device);
    }
}
