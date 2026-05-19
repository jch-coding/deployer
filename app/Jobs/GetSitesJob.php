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
    use Batchable, Queueable;

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
        if (! $this->centralAPIHelper->client->handleBearerTokenAuth()) {
            Log::error('Access Token Renewal failed');
            throw new \Exception('Access Token Renewal failed');
        }

        $limit = 100;
        $offset = 0;
        $returned_sites = [];

        while (true) {
            $central_sites = $this->centralAPIHelper->get_sites([
                'offset' => $offset,
                'limit' => $limit,
            ]);

            if (! $central_sites->ok()) {
                Log::error('Failed to get sites from Central');
                throw new \Exception('Failed to get sites from Central');
            }

            $page_items = $central_sites->json('items', []);
            $returned_sites = array_merge($returned_sites, $page_items);

            if (count($page_items) < $limit) {
                break;
            }

            $offset += $limit;
        }

        $returned_sites_corresponding_to_sites_passed = array_values(array_filter(array_map(
            fn ($site) => array_find($returned_sites, fn ($s) => ($s['scopeName'] ?? null) === ($site['name'] ?? null)),
            $this->sites
        )));

        if (count($returned_sites_corresponding_to_sites_passed) !== count($this->sites)) {
            Log::error('Not all sites are configured in Central');
        }

        $succeeded_updates = 0;
        foreach ($returned_sites_corresponding_to_sites_passed as $returned_site) {
            if (! isset($returned_site['scopeName'], $returned_site['scopeId'])) {
                continue;
            }

            $found = Site::query()
                ->where('name', $returned_site['scopeName'])
                ->where('client_id', $this->centralAPIHelper->client->id)
                ->first();
            if ($found) {
                $found->scope_id = $returned_site['scopeId'];
                $found->save();
                $succeeded_updates++;
            }
        }

        if ($succeeded_updates !== count($returned_sites_corresponding_to_sites_passed)) {
            Log::error('Failed to update scope_id for some sites');
        }
    }
}
