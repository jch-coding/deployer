<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\Task;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class AssociateSiteAndNameJob extends BaseTaskJob
{
    /**
     * Create a new job instance.
     */
    public function __construct(public Device $device, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->initTaskTiming($task, defaultDeploymentMinutes: 3, defaultWaitMinutes: 3);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->handleSafely(function (): void {
            // check that site has a classic central id
            if (! $this->device->site->classic_id) {
                // get the classic central id
                $classic_sites = $this->centralAPIHelper->classic_get_sites();
                if (is_array($classic_sites) || ! $classic_sites instanceof Response || ! $classic_sites->ok()) {
                    $detail = is_array($classic_sites)
                        ? ($classic_sites['error'] ?? json_encode($classic_sites))
                        : ($classic_sites->json('message') ?? 'Classic sites request failed');
                    Log::error($detail);
                    $this->fail(is_string($detail) ? $detail : 'Failed to load classic sites from Central');

                    return;
                }
                $site_list = $classic_sites->json('sites') ?? [];
                $found_site = array_find($site_list, fn ($classic_site) => $classic_site['site_name'] === $this->device->site->name);
                if (! $found_site) {
                    Log::error('Site '.$this->device->site->name.' not found in classic central');
                    $this->fail('Site '.$this->device->site->name.' not found in classic central');

                    return;
                }
                $this->device->site->classic_id = $found_site['site_id'];
                $this->device->site->save();
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
                if (is_array($response) || ! $response instanceof Response || ! $response->ok()) {
                    $message = 'Failed to associate device '.$this->device->name.' to site';
                    Log::error($message);
                    $this->task->processTaskStatusLog($message, true);
                    $this->release(60 * $this->wait_time);

                    return;
                }
                $status_log = $this->task->status_log;
                $new_log = $status_log.'\nAssociated '.$this->device->name.' to site '.$this->device->site->name;
                $this->task->update(['status_log' => $new_log]);
                // now name the device through new Central. Get the device scope id.
                sleep(5);

                $retry_count = 3;
                while ($retry_count > 0) {
                    $scope_id_object = $this->centralAPIHelper->getScopeIdFromCentral($this->device);
                    if (array_key_exists('error', $scope_id_object)) {
                        $message = 'Failed to get scope id for device '.$this->device->name.'. Retrying in 5 seconds.';
                        Log::error($message);
                        $this->task->processTaskStatusLog($message, true);
                        sleep(5);
                    } else {
                        $scope_id = $scope_id_object[0]['scopeId'];
                        $this->device->scope_id = $scope_id;
                        $this->device->save();
                        break;
                    }
                    $retry_count--;
                }
                if ($retry_count === 0) {
                    $message = 'Failed to get scope id for device '.$this->device->name.' after 3 attempts.';
                    Log::error($message);
                    $this->task->processTaskStatusLog($message, true);
                    $this->release(60 * $this->wait_time);

                    return;
                }
            }
            // name the device through new Central.
            $response = $this->centralAPIHelper->postSystemInfo($this->device);
            if (is_array($response) || ! $response instanceof Response || ! $response->ok()) {
                $message = 'Failed to name '.$this->device->name;
                Log::error(is_array($response)
                    ? ($response['error'] ?? json_encode($response))
                    : ($response->json('message') ?? $message));
                $this->task->processTaskStatusLog($message, true);
                $this->release(60 * $this->wait_time);

                return;
            }
            $message = '\nNamed '.$this->device->serial.' as '.$this->device->name;
            $this->task->processTaskStatusLog($message);
            $this->task->devices()->find($this->device)?->pivot?->update(['status' => 'COMPLETED']);
        }, 'Associate site and name');
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

    public function failed(?Throwable $exception): void
    {
        $this->logFailedException($exception);
        $this->failDeviceAndTaskIfNeeded($this->device);
    }
}
