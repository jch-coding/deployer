<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Site;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GetSitesJob implements ShouldQueue
{
    use Queueable, Batchable;

    /**
     *  $sites = [
     *              [
     *                'name' => 'SITE_NAME',
     *                'devices' => [ SERIALS ]
     *              ],
     *            ]
     */
    public function __construct(public CentralAPIHelper $centralAPIHelper, public array $sites)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (!$this->centralAPIHelper->client->handleBearerTokenAuth()) {
            Log::error('Access Token Renewal failed');
            throw new \Exception('Access Token Renewal failed');
        }

        $central_sites = $this->centralAPIHelper->get_sites();
        if(!$central_sites->ok()) {
            Log::error('Failed to get sites from Central');
            throw new \Exception('Failed to get sites from Central');
        }

        $returned_sites = $central_sites->json()['items'] ?? [];
        $returned_sites_corresponding_to_sites_passed = array_map(fn($site) => array_find($returned_sites, fn($s) => $s['scopeName'] === $site['name']), $this->sites);
        if(count($returned_sites_corresponding_to_sites_passed) !== count($this->sites)) {
            Log::error('Not all sites are configured in Central');
        }
        $updated_sites = array_map(function ($returned_site) {
            $found = Site::where('name', $returned_site['scopeName'])->get()->first();
            if($found) {
                $found->scope_id = $returned_site['scopeId'];
                $found->save();
            }
        }, $returned_sites_corresponding_to_sites_passed);
        $succeeded_updates = array_filter($updated_sites, fn($update) => $update);
        if(count($succeeded_updates) !== count($returned_sites_corresponding_to_sites_passed)) {
            Log::error('Failed to update scope_id for some sites');
        }
    }
}
