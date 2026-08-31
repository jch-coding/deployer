<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Client;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RebootAccessPointJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $clientId,
        public string $serial,
    ) {}

    public function handle(): void
    {
        $client = Client::query()->find($this->clientId);
        if ($client === null) {
            Log::warning('RebootAccessPointJob: client not found.', [
                'client_id' => $this->clientId,
                'serial' => $this->serial,
            ]);

            return;
        }

        $helper = new CentralAPIHelper($client);
        $result = $helper->reboot_ap($this->serial);

        if (! ($result['ok'] ?? false)) {
            Log::error('RebootAccessPointJob: failed to reboot access point.', [
                'client_id' => $this->clientId,
                'serial' => $this->serial,
                'status' => $result['status'] ?? null,
                'error' => $result['error'] ?? 'unknown error',
            ]);
        }
    }
}
