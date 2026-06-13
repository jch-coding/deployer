<?php

namespace App\Actions\Provisioning;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Services\Provisioning\ProvisioningStepResult;
use Illuminate\Http\Client\Response;

class AssignDeviceFunctionAction
{
    public function execute(Device $device, CentralAPIHelper $centralAPIHelper): ProvisioningStepResult
    {
        $deviceFunction = (string) $device->device_function;
        if ($deviceFunction === '') {
            return ProvisioningStepResult::failed('Device has no device function configured.');
        }

        $response = $centralAPIHelper->assignDeviceFunction([(string) $device->serial], $deviceFunction);
        if (is_array($response) || ! $response instanceof Response || ! $response->ok()) {
            $detail = is_array($response)
                ? ($response['error'] ?? json_encode($response))
                : ($response instanceof Response ? ($response->json('message') ?? $response->body()) : 'unknown error');

            return ProvisioningStepResult::failed('Assign device function failed: '.$detail);
        }

        return ProvisioningStepResult::completed("Device {$device->name} assigned to {$deviceFunction}.");
    }
}
