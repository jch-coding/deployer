<?php

namespace App\Actions\Provisioning;

use App\Helper\CentralAPIHelper;
use App\InterfaceKind;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Services\Provisioning\ProvisioningStepResult;

class ConfigureDeviceEthernetInterfacesAction
{
    public function execute(Device $device, CentralAPIHelper $centralAPIHelper): ProvisioningStepResult
    {
        $device->loadMissing('interfaces');
        $interfaces = $device->interfaces->filter(
            fn (DeviceInterface $iface) => $iface->interface_kind === InterfaceKind::ETHERNET
        );

        if ($interfaces->isEmpty()) {
            return ProvisioningStepResult::skipped('No ethernet interfaces to configure.');
        }

        if (! $device->scope_id) {
            $scopeResult = (new ResolveDeviceScopeIdAction)->execute($device, $centralAPIHelper);
            if (! $scopeResult->isCompleted()) {
                return $scopeResult;
            }
        }

        foreach ($interfaces as $deviceInterface) {
            $result = $this->configureEthernet($deviceInterface, $centralAPIHelper);
            if (! $result->isCompleted()) {
                return $result;
            }
        }

        return ProvisioningStepResult::completed('Ethernet interfaces configured.');
    }

    private function configureEthernet(DeviceInterface $deviceInterface, CentralAPIHelper $centralAPIHelper): ProvisioningStepResult
    {
        $getResponse = $centralAPIHelper->get_ethernet_interface($deviceInterface);
        if ($getResponse->ok() && array_key_exists('sw-profile', $getResponse->json())) {
            $patchBody = ['sw-profile' => null];
            $removeProfileResponse = $centralAPIHelper->patch_ethernet_interface($deviceInterface, $patchBody);
            if (! $removeProfileResponse->ok()) {
                return ProvisioningStepResult::failed('Failed to remove port profile from ethernet interface.');
            }
        }

        $deviceInterface->loadMissing('device.site');
        $vrfResult = $centralAPIHelper->ensureVrfForRoutedInterface($deviceInterface);
        if (isset($vrfResult['error'])) {
            return ProvisioningStepResult::failed('Failed to ensure VRF: '.$vrfResult['error']);
        }

        $patchResponse = $centralAPIHelper->patch_ethernet_interface($deviceInterface);
        if (! $patchResponse->ok()) {
            return ProvisioningStepResult::retry('Failed to patch ethernet interface: '.$patchResponse->json('message'));
        }

        return ProvisioningStepResult::completed("Ethernet {$deviceInterface->interface} configured.");
    }
}
