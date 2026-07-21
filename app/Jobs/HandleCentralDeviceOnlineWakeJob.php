<?php

namespace App\Jobs;

use App\Enums\OnlineDetectionMode;
use App\Models\Client;
use App\Services\Provisioning\MarkDeviceOnlineIfWaiting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class HandleCentralDeviceOnlineWakeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $clientId,
        public string $serial,
        public string $mode = 'webhook',
    ) {}

    public function handle(MarkDeviceOnlineIfWaiting $markDeviceOnlineIfWaiting): void
    {
        $client = Client::query()->find($this->clientId);
        if ($client === null || $this->serial === '') {
            return;
        }

        $mode = OnlineDetectionMode::tryFrom($this->mode);
        if ($mode === null || ! $mode->waitsForExternalWake()) {
            return;
        }

        $markDeviceOnlineIfWaiting->forSerial($this->clientId, $this->serial, $mode);
    }
}
