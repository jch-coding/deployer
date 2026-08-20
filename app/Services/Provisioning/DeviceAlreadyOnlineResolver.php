<?php

namespace App\Services\Provisioning;

use App\Helper\CentralAPIHelper;
use App\Models\CentralWebhookEvent;
use App\Models\Client;
use App\Models\Device;
use Illuminate\Support\Collection;
use Throwable;

class DeviceAlreadyOnlineResolver
{
    public function __construct(
        private readonly ClassicDeviceOnlineService $classicDeviceOnlineService,
    ) {}

    /**
     * @param  Collection<int, Device>  $devices
     * @return array<int, string> device id => skip reason
     */
    public function resolve(Client $client, Collection $devices, bool $queryCentral): array
    {
        if ($devices->isEmpty()) {
            return [];
        }

        $reasons = $this->reasonsFromAcceptedWebhooks($client, $devices);

        if ($queryCentral) {
            foreach ($this->reasonsFromCentral($client, $devices) as $deviceId => $reason) {
                if (! isset($reasons[$deviceId])) {
                    $reasons[$deviceId] = $reason;
                }
            }
        }

        return $reasons;
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @return array<int, string>
     */
    private function reasonsFromAcceptedWebhooks(Client $client, Collection $devices): array
    {
        $serials = $devices
            ->map(fn (Device $device) => trim((string) $device->serial))
            ->filter(fn (string $serial) => $serial !== '')
            ->unique()
            ->values()
            ->all();

        if ($serials === []) {
            return [];
        }

        $acceptedSerials = CentralWebhookEvent::query()
            ->where('client_id', $client->id)
            ->where('disposition', 'accepted')
            ->whereIn('serial', $serials)
            ->pluck('serial')
            ->map(fn ($serial) => (string) $serial)
            ->unique()
            ->all();

        if ($acceptedSerials === []) {
            return [];
        }

        $acceptedLookup = array_fill_keys($acceptedSerials, true);
        $reasons = [];

        foreach ($devices as $device) {
            $serial = trim((string) $device->serial);
            if ($serial !== '' && isset($acceptedLookup[$serial])) {
                $reasons[(int) $device->id] = 'Already online (webhook).';
            }
        }

        return $reasons;
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @return array<int, string>
     */
    private function reasonsFromCentral(Client $client, Collection $devices): array
    {
        try {
            $centralAPIHelper = new CentralAPIHelper($client);

            $needsSwitch = $devices->contains(
                fn (Device $device) => str_contains((string) $device->device_function, 'SWITCH'),
            );
            $needsAp = $devices->contains(
                fn (Device $device) => str_contains((string) $device->device_function, 'AP'),
            );

            $switchStatuses = $needsSwitch
                ? $this->classicDeviceOnlineService->fetchSwitchStatuses($centralAPIHelper)
                : [];
            $apStatuses = $needsAp
                ? $this->classicDeviceOnlineService->fetchApStatuses($centralAPIHelper)
                : [];
        } catch (Throwable) {
            return [];
        }

        $reasons = [];
        foreach ($devices as $device) {
            if ($this->classicDeviceOnlineService->isDeviceUp($device, $switchStatuses, $apStatuses)) {
                $reasons[(int) $device->id] = 'Already online in Central (Up).';
            }
        }

        return $reasons;
    }
}
