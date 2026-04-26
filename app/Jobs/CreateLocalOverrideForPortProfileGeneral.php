<?php

namespace App\Jobs;

use App\Events\DeploymentEvent;
use App\Events\FailureEvent;
use App\Helper\CentralAPIHelper;
use App\Models\Task;
use DateTime;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreateLocalOverrideForPortProfileGeneral extends BaseTaskJob
{
    /**
     * Create a new job instance.
     * $portProfileInfo = [
     *     'sw_profile' => string (port profile name as it is in Central),
     *     'device_function' => string
     *     'container' => site/device group/collection model with name and possibly scope_id
     *     'container_type' => string
     * ]
     */
    public int $deployment_time;
    public int $wait_time;

    public function __construct(public array $portProfileInfo, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->deployment_time = $task->deployment_time > 0 ? $task->deployment_time : 3;
        $this->wait_time = $task->wait_time ?? 3;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->handleSafely(function (): void {
            $container_to_url = [
                'site_collection' => fn () => $this->centralAPIHelper->get_site_collections(),
                'site' => fn () => $this->centralAPIHelper->get_sites(),
                'device_group' => fn () => $this->centralAPIHelper->get_device_groups(),
            ];
            $statusLog = $this->task->status_log;
            if (! $this->portProfileInfo['container']['scope_id']) {
                $container_response = $container_to_url[$this->portProfileInfo['container_type']]();
                if (! $container_response->ok()) {
                    $newStatusLog = $statusLog."\nfailed to retrieve container: ".$this->portProfileInfo['container']['name']." from central\n";
                    $this->task->update(['status_log' => $newStatusLog]);
                    Log::error('failed to retrieve container:'.$this->portProfileInfo['container']['name'].' from central');
                    $this->release($this->wait_time * 60);
                } else {
                    $container_item = collect($container_response->json('items'))->filter(fn ($item) => $item['scopeName'] === $this->portProfileInfo['container']['name']);
                    if ($container_item->isEmpty()) {
                        $newStatusLog = $statusLog."\nfailed to retrieve container: ".$this->portProfileInfo['container']['name']." from central\n";
                        $this->task->update(['status_log' => $newStatusLog]);
                        Log::error('failed to retrieve container:'.$this->portProfileInfo['container']['name'].' from central');
                        $this->release($this->wait_time * 60);
                    } else {
                        $this->portProfileInfo['container']['scope_id'] = $container_item->first()['scopeId'];
                    }
                }
            }
            $query_parameters = [
                'object-type' => 'LOCAL',
                'scope-id' => $this->portProfileInfo['container']['scope_id'],
                'device-function' => $this->portProfileInfo['device_function'],
            ];
            // check whether the port profile is already an override at the scope
            $response = $this->centralAPIHelper->get_sw_port_profile($this->portProfileInfo['sw_profile']);
            if ($response->status() == 200) {
                $post_response = $this->centralAPIHelper->post_sw_port_profile($response->json(), $query_parameters);
                if (! $post_response->ok()) {
                    if (str_contains($post_response->json()['message'], 'Cannot create duplicate config')) {
                        Log::info('Port profile override already exists for '.$this->portProfileInfo['sw_profile'].' at site '.$this->portProfileInfo['site']->name);

                        return;
                    } else {
                        $newStatusLog = $statusLog."\nfailed to override port profile at the site level profile:".$this->portProfileInfo['sw_profile'].' site:'.$this->portProfileInfo['site']->name."\n";
                        $this->task->update(['status_log' => $newStatusLog]);
                        Log::error('failed to override port profile at the site level profile:'.$this->portProfileInfo['sw_profile'].' site:'.$this->portProfileInfo['site']->name);
                        $this->release($this->wait_time * 60);
                    }
                }
            } else {
                $this->fail();
            }
            $newStatusLog = $statusLog."\nPort profile override for ".$this->portProfileInfo['sw_profile'].' at site '.$this->portProfileInfo['site']->name." completed\n";
            $this->task->update(['status_log' => $newStatusLog]);
        }, 'Create generic local override for port profile');
    }

    public function retryUntil(): DateTime
    {
        return now()->addMinutes($this->deployment_time)->toDateTime();
    }

    public function failed(?Throwable $exception)
    {
        $this->logFailedException($exception);
        $message = 'Failed configuring port profile '.$this->portProfileInfo['sw_profile'].' at site '.$this->portProfileInfo['site']->name;
        $this->task->processTaskStatusLog($message, true);
    }
}
