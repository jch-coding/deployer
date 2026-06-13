<?php

namespace App\Actions\Provisioning;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Services\Provisioning\ProvisioningStepResult;

class ResolveDeviceScopeIdAction
{
    public function execute(Device $device, CentralAPIHelper $centralAPIHelper): ProvisioningStepResult
    {
        if ($device->scope_id) {
            return ProvisioningStepResult::completed('Scope ID already present.');
        }

        $response = $centralAPIHelper->getScopeIdFromCentral($device);
        if (array_key_exists('error', $response)) {
            return ProvisioningStepResult::retry('Waiting for scope ID from Central...');
        }

        $scopeEntries = array_values($response);
        if ($scopeEntries === [] || ! isset($scopeEntries[0]['scopeId'])) {
            return ProvisioningStepResult::retry('Scope ID not yet available. Retrying...');
        }

        $device->scope_id = $scopeEntries[0]['scopeId'];
        $device->save();

        return ProvisioningStepResult::completed('Scope ID resolved.');
    }
}
