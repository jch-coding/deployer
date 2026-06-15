<?php

namespace App\Actions\Provisioning;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Services\Provisioning\ProvisioningStepResult;
use Illuminate\Http\Client\Response;

class AssociateDeviceToSiteAction
{
    public function execute(Device $device, CentralAPIHelper $centralAPIHelper): ProvisioningStepResult
    {
        $device->loadMissing('site');
        if ($device->site === null) {
            return ProvisioningStepResult::failed('Device has no site configured.');
        }

        if (! $this->ensureClassicSiteId($device, $centralAPIHelper)) {
            return ProvisioningStepResult::failed('Could not resolve Classic Central site ID.');
        }

        $body = [
            'site_id' => $device->site->classic_id,
            'device_type' => $this->classicDeviceType($device),
            'device_id' => $device->serial,
        ];

        $response = $centralAPIHelper->classic_associate_device_to_site($body);
        if (is_array($response) || ! $response instanceof Response || ! $response->ok()) {
            return ProvisioningStepResult::retry('Failed to associate device to site. Retrying...');
        }

        return ProvisioningStepResult::completed("Associated {$device->name} to site {$device->site->name}.");
    }

    private function ensureClassicSiteId(Device $device, CentralAPIHelper $centralAPIHelper): bool
    {
        if ($device->site->classic_id) {
            return true;
        }

        $sitesResult = $centralAPIHelper->classic_collect_all_sites();
        if (isset($sitesResult['error'])) {
            return false;
        }

        $classicSite = array_find($sitesResult['sites'], fn ($site) => $site['site_name'] === $device->site->name);
        if (! $classicSite) {
            return false;
        }

        $device->site->update(['classic_id' => $classicSite['site_id']]);
        $device->site->refresh();

        return true;
    }

    private function classicDeviceType(Device $device): string
    {
        $function = (string) $device->device_function;
        if (str_contains($function, 'SWITCH')) {
            return 'SWITCH';
        }
        if (str_contains($function, 'AP')) {
            return 'IAP';
        }

        return 'CONTROLLER';
    }
}
