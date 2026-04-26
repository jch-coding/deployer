<?php

namespace App\Jobs;

use App\Events\DeploymentEvent;
use App\Events\FailureEvent;
use App\Helper\CentralAPIHelper;
use App\Models\Task;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreateLocalOverrideForPortProfile extends BaseTaskJob
{
    /**
     * Create a new job instance.
     * $portProfileInfo = [
     *     'sw_profile' => string (port profile name as it is in Central),
     *     'device_function' => string
     *     'site' => site model with name and possibly scope_id
     * ]
     */
    public function __construct(public array $portProfileInfo, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->initTaskTiming($task, defaultDeploymentMinutes: 3, defaultWaitMinutes: 1);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->handleSafely(function (): void {
            $statusLog = $this->task->status_log;
            if (! $this->portProfileInfo['site']->scope_id) {
                $site_scope_id = $this->centralAPIHelper->get_site_scope_id($this->portProfileInfo['site']);
                if (! $site_scope_id) {
                    $newStatusLog = $statusLog."\nfailed to retrieve scope ID for site ".$this->portProfileInfo['site']->name."\n";
                    $this->task->update(['status_log' => $newStatusLog]);
                    Log::error('failed to retrieve scope ID for site '.$this->portProfileInfo['site']->name);

                    return;
                }
                $this->portProfileInfo['site']->scope_id = $site_scope_id;
                $this->portProfileInfo['site']->save();
            }
            $query_parameters = [
                'object-type' => 'LOCAL',
                'scope-id' => $this->portProfileInfo['site']->scope_id,
                'device-function' => $this->portProfileInfo['device_function'],
            ];
            // check whether the port profile is already an override at the scope
            $response = $this->centralAPIHelper->get_sw_port_profile($this->portProfileInfo['sw_profile']);
            if ($response->status() == 200) {
                $post_response = $this->centralAPIHelper->post_sw_port_profile($response->json(), $query_parameters);
                if (! $post_response->ok()) {
                    if (str_contains($post_response->json()['message'], 'Cannot create duplicate config')) {
                        Log::info('Port profile override already exists for '.$this->portProfileInfo['sw_profile'].' at site '.$this->portProfileInfo['site']->name);
                    } else {
                        $newStatusLog = $statusLog."\nfailed to override port profile at the site level profile:".$this->portProfileInfo['sw_profile'].' site:'.$this->portProfileInfo['site']->name."\n";
                        $this->task->update(['status_log' => $newStatusLog]);
                        Log::error('failed to override port profile at the site level profile:'.$this->portProfileInfo['sw_profile'].' site:'.$this->portProfileInfo['site']->name);
                    }
                }
            } else {
                $newStatusLog = $statusLog."\nfailed to retrieve port profile: ".$this->portProfileInfo['sw_profile']." from central\n";
                $this->task->update(['status_log' => $newStatusLog]);
                Log::error('failed to retrieve port profile:'.$this->portProfileInfo['sw_profile'].' from central');

                return;
            }
            $newStatusLog = $statusLog."\nPort profile override for ".$this->portProfileInfo['sw_profile'].' at site '.$this->portProfileInfo['site']->name." completed\n";
            $this->task->update(['status_log' => $newStatusLog]);
        }, 'Create local override for port profile');
    }

    public function failed(?Throwable $exception)
    {
        $this->logFailedException($exception);
        $message = 'Failed configuring port profile '.$this->portProfileInfo['sw_profile'].' at site '.$this->portProfileInfo['site']->name;
        $this->task->processTaskStatusLog($message, true);
    }
}
