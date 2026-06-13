<?php

namespace App\Actions\Provisioning;

use App\Helper\CentralAPIHelper;
use App\InterfaceKind;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Services\Provisioning\ProvisioningStepResult;

class ConfigureDeviceLagInterfacesAction
{
    public function execute(Device $device, CentralAPIHelper $centralAPIHelper): ProvisioningStepResult
    {
        $device->loadMissing('interfaces');
        $interfaces = $device->interfaces->filter(
            fn (DeviceInterface $iface) => $iface->interface_kind === InterfaceKind::LAG
        );

        if ($interfaces->isEmpty()) {
            return ProvisioningStepResult::skipped('No LAG interfaces to configure.');
        }

        if (! $device->scope_id) {
            $scopeResult = (new ResolveDeviceScopeIdAction)->execute($device, $centralAPIHelper);
            if (! $scopeResult->isCompleted()) {
                return $scopeResult;
            }
        }

        foreach ($interfaces as $deviceInterface) {
            $result = $this->configureLag($deviceInterface, $centralAPIHelper);
            if (! $result->isCompleted()) {
                return $result;
            }
        }

        return ProvisioningStepResult::completed('LAG interfaces configured.');
    }

    private function configureLag(DeviceInterface $deviceInterface, CentralAPIHelper $centralAPIHelper): ProvisioningStepResult
    {
        $isRoutedLag = CentralAPIHelper::is_routed_lag_interface($deviceInterface);

        if ($isRoutedLag) {
            $deviceInterface->loadMissing('device.site');
            $vrfResult = $centralAPIHelper->ensureVrfForRoutedInterface($deviceInterface);
            if (isset($vrfResult['error'])) {
                return ProvisioningStepResult::retry('Failed to ensure VRF for LAG: '.$vrfResult['error']);
            }
        }

        $response = $centralAPIHelper->post_interface_portchannel($deviceInterface);
        if (! $response->ok()) {
            return ProvisioningStepResult::retry('Failed to create LAG: '.$response->json('message'));
        }

        if ($isRoutedLag || $deviceInterface->sw_profile) {
            $patchResponse = $centralAPIHelper->patch_interface_portchannel($deviceInterface);
            if (! $patchResponse->ok()) {
                return ProvisioningStepResult::retry('Failed to patch LAG: '.$patchResponse->json('message'));
            }
        }

        return ProvisioningStepResult::completed("LAG {$deviceInterface->interface} configured.");
    }
}
