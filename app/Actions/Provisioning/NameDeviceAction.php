<?php

namespace App\Actions\Provisioning;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Services\Provisioning\ProvisioningStepResult;
use Illuminate\Http\Client\Response;

class NameDeviceAction
{
    public function execute(Device $device, CentralAPIHelper $centralAPIHelper): ProvisioningStepResult
    {
        if (! $device->scope_id) {
            $scopeResult = (new ResolveDeviceScopeIdAction)->execute($device, $centralAPIHelper);
            if (! $scopeResult->isCompleted()) {
                return $scopeResult;
            }
        }

        $response = $centralAPIHelper->updateSystemInfo($device);
        if ($response instanceof Response && $response->successful()) {
            return ProvisioningStepResult::completed("System info updated for {$device->name}.");
        }

        $createResponse = $centralAPIHelper->postSystemInfo($device);
        if ($createResponse instanceof Response && $createResponse->successful()) {
            return ProvisioningStepResult::completed("System info created for {$device->name}.");
        }

        return ProvisioningStepResult::retry('Failed to name device. Retrying...');
    }
}
