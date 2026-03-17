<?php

namespace App\Jobs;

use App\Events\DeploymentEvent;
use App\Helper\CentralAPIHelper;
use App\Models\Task;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

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
    public function __construct(public array $portProfileInfo, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (! $this->portProfileInfo['site']->scope_id) {
            $sites_response = $this->centralAPIHelper->get_sites();
            if ($sites_response->status() == 200) {
                $central_site = array_find($sites_response->json()['items'], fn ($item) => $item['scopeName'] === $this->portProfileInfo['site']->name);
                if (! $central_site) {
                    Log::error('failed to retrieve scope ID for site '.$this->portProfileInfo['site']->name);

                    return;
                }
                $this->portProfileInfo['site']->scope_id = $central_site['scopeId'];
                $this->portProfileInfo['site']->save();
            } else {
                Log::error('failed to retrieve scope ID for site '.$this->portProfileInfo['site']->name);

                return;
            }
        }
        $query_parameters = [
            'object-type' => 'LOCAL',
            'scope-id' => $this->portProfileInfo['site']->scope_id,
            'device-function' => $this->portProfileInfo['device_function'],
        ];
        $response = $this->centralAPIHelper->get_sw_port_profile($this->portProfileInfo['sw_profile']);
        if ($response->status() == 200) {
            $patch_response = $this->centralAPIHelper->post_sw_port_profile($response->json(), $query_parameters);
            if (! $patch_response->ok()) {
                Log::error('failed to override port profile at the site level profile:'.$this->portProfileInfo['sw_profile'].' site:'.$this->portProfileInfo['site']->name);
            }
        }
        else {
            Log::error('failed to retrieve port profile:'.$this->portProfileInfo['sw_profile'].' from central');
            $this->fail('failed to retrieve port profile:'.$this->portProfileInfo['sw_profile'].' from central');
        }
        DeploymentEvent::dispatch([
            'deployment_name' => $this->task->deployment->name,
            'device_name' => $this->portProfileInfo['sw_profile'],
            'task_type' => $this->task->task_type,
            'message' => 'Port profile override for '.$this->portProfileInfo['sw_profile'].' at site '.$this->portProfileInfo['site']->name.' completed',
        ]);
    }
}
