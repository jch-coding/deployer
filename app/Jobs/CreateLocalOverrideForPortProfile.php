<?php

namespace App\Jobs;

use App\Events\DeploymentEvent;
use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\Task;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CreateLocalOverrideForPortProfile implements ShouldQueue
{
    use Queueable, Batchable;

    /**
     * Create a new job instance.
     */
    public function __construct(public Device $device, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        //$device->interfaces only has the interfaces with a sw_profile
        $site = $this->device->site;
        if (!$site) {
            Log::error('device does not have a site. Device:'.$this->device->name);
            return;
        }
        if(!$site->scope_id) {
            $sites_response = $this->centralAPIHelper->get_sites();
            if ($sites_response->status() == 200) {
                $central_site = array_find($sites_response->json()['items'], fn($item) => $item['scopeName'] === $site->name);
                if(!$central_site) {
                    Log::error('failed to retrieve scope ID for site ' . $site->name);
                    return;
                }
                $site->scope_id = $central_site['scopeId'];
                $site->save();
            } else {
                Log::error('failed to retrieve scope ID for site ' . $site->name);
                return;
            }
        }
        $query_parameters = [
            'object-type' => 'LOCAL',
            'scope-id' => $site->scope_id,
            'device-function' => $this->device->device_function,
        ];
        $sw_port_profiles_pushed = [];
        $this->device->interfaces->each(function ($interface) use ($sw_port_profiles_pushed,$query_parameters, $site) {
            if (!in_array($interface->sw_profile->name, $sw_port_profiles_pushed)) {
                $response = $this->centralAPIHelper->get_sw_port_profile($interface->sw_profile);
                if ($response->status() == 200) {
                    $patch_response = $this->centralAPIHelper->post_sw_port_profile($response->json(), $query_parameters);
                    if (!$patch_response->ok()) {
                        Log::error('failed to override port profile at the site level profile:' . $interface->sw_profile . ' site:' . $site->name);
                    }
                }
                array_push($sw_port_profiles_pushed, $interface->sw_profile->name);
                DeploymentEvent::dispatch([
                    'deployment_name' => $this->task->deployment->name,
                    'device_name' => $this->device->name,
                    'task_type' => $this->task->task_type,
                    'message' => 'Port profile override for ' . $interface->sw_profile->name . ' at site ' . $site->name . ' completed'
                ]);
            }
        });
    }
}
