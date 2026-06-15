<?php

namespace App\Actions\Provisioning;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Services\Provisioning\ProvisioningStepResult;
use Illuminate\Http\Client\Response;

class PreprovisionDeviceToGroupAction
{
    public function execute(Device $device, CentralAPIHelper $centralAPIHelper): ProvisioningStepResult
    {
        $group = trim((string) $device->group);
        if ($group === '') {
            return ProvisioningStepResult::failed('Device has no group configured.');
        }

        $groupsResult = $centralAPIHelper->classic_collect_all_group_names();
        if (isset($groupsResult['error'])) {
            return ProvisioningStepResult::failed('Could not load groups from Central.');
        }

        if (! in_array($group, $groupsResult['names'], true)) {
            return ProvisioningStepResult::failed("Group \"{$group}\" not found in Central.");
        }

        $serial = (string) $device->serial;
        $response = $centralAPIHelper->preprovision_devices_to_group($group, [$serial]);
        $ok = ! is_array($response) && $response instanceof Response && $response->status() === 201;
        $fallback = $response instanceof Response
            && $response->status() === 400
            && str_contains((string) $response->json('description'), 'Following Devices are already connected to Central');

        if ($fallback) {
            $moveResponse = $centralAPIHelper->move_devices_to_group($group, [$serial]);
            $ok = ! is_array($moveResponse) && $moveResponse instanceof Response && $moveResponse->ok();
        }

        if (! $ok) {
            return ProvisioningStepResult::failed("Failed to preprovision device {$serial} to group {$group}.");
        }

        $message = $fallback
            ? "Device {$serial} moved to group {$group} (fallback from preprovision)."
            : "Device {$serial} preprovisioned to group {$group}.";

        return ProvisioningStepResult::completed($message);
    }
}
