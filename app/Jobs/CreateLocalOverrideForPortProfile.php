<?php

namespace App\Jobs;

use App\Events\DeploymentEvent;
use App\Events\FailureEvent;
use App\Helper\CentralAPIHelper;
use App\Models\Task;
use DateTime;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreateLocalOverrideForPortProfile implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * Create a new job instance.
     * $portProfileInfo = [
     *     'sw_profile' => string (port profile name as it is in Central),
     *     'device_function' => string
     *     'site' => site model with name and possibly scope_id
     * ]
     */
    public int $deployment_time;

    public function __construct(public array $portProfileInfo, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->deployment_time = $task->deployment_time > 0 ? $task->deployment_time : 10;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
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
            $this->fail('failed to retrieve port profile:'.$this->portProfileInfo['sw_profile'].' from central');
        }
        $newStatusLog = $statusLog."\nPort profile override for ".$this->portProfileInfo['sw_profile'].' at site '.$this->portProfileInfo['site']->name." completed\n";
        $this->task->update(['status_log' => $newStatusLog]);
        DeploymentEvent::dispatch([
            'deployment_name' => $this->task->deployment->name,
            'item_name' => $this->portProfileInfo['sw_profile'],
            'task_type' => $this->task->task_type,
            'message' => 'Port profile override for '.$this->portProfileInfo['sw_profile'].' at site '.$this->portProfileInfo['site']->name.' completed',
            'event_type' => 'deployment_event',
            'item_type' => 'port_profile',
        ]);
    }

    public function retryUntil(): DateTime
    {
        return now()->addMinutes($this->deployment_time)->toDateTime();
    }

    public function failed(?Throwable $exception)
    {
        Log::error($exception);
        $statusLog = $this->task->status_log;
        $newStatusLog = $statusLog."\nFailed Configuring Port Profile".$this->portProfileInfo['sw_profile'].' at site '.$this->portProfileInfo['site']->name."\n";
        $this->task->update(['status_log' => $newStatusLog]);
    }
}
