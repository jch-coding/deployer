<?php

namespace App\Actions\Provisioning;

use App\Helper\CentralAPIHelper;
use App\InterfaceKind;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Services\Provisioning\ProvisioningStepResult;

class ConfigureDeviceVlanInterfacesAction
{
    public function execute(Device $device, CentralAPIHelper $centralAPIHelper): ProvisioningStepResult
    {
        $device->loadMissing('interfaces');
        $interfaces = $device->interfaces->filter(
            fn (DeviceInterface $iface) => $iface->interface_kind === InterfaceKind::VLAN
        );

        if ($interfaces->isEmpty()) {
            return ProvisioningStepResult::skipped('No VLAN interfaces to configure.');
        }

        if (! $device->scope_id) {
            $scopeResult = (new ResolveDeviceScopeIdAction)->execute($device, $centralAPIHelper);
            if (! $scopeResult->isCompleted()) {
                return $scopeResult;
            }
        }

        foreach ($interfaces as $deviceInterface) {
            $result = $this->configureInterface($device, $deviceInterface, $centralAPIHelper);
            if (! $result->isCompleted()) {
                return $result;
            }
        }

        return ProvisioningStepResult::completed('VLAN interfaces configured.');
    }

    private function configureInterface(Device $device, DeviceInterface $deviceInterface, CentralAPIHelper $centralAPIHelper): ProvisioningStepResult
    {
        $l2Response = $centralAPIHelper->get_l2_vlans();
        if ($l2Response->ok()) {
            $l2Vlans = $l2Response->json('l2-vlan') ?? [];
            $foundVlan = array_find($l2Vlans, fn ($vlan) => ($vlan['vlan'] ?? null) === (int) $deviceInterface->interface);
            if ($foundVlan) {
                $queryParams = [
                    'view-type' => 'LOCAL',
                    'object-type' => 'LOCAL',
                    'scope-id' => $device->scope_id,
                    'device-function' => $device->device_function,
                ];
                $overrideResponse = $centralAPIHelper->post_l2_vlan($queryParams, ['vlan' => $deviceInterface->interface]);
                if (! $overrideResponse->ok() && ! str_contains((string) $overrideResponse->json('message'), 'Cannot create duplicate config')) {
                    return ProvisioningStepResult::failed('Failed to override VLAN: '.$overrideResponse->json('message'));
                }
            }
        }

        $vlanResponse = $centralAPIHelper->post_vlan_interface($deviceInterface);
        if ($vlanResponse->ok()) {
            return ProvisioningStepResult::completed("VLAN interface {$deviceInterface->interface} configured.");
        }

        if (str_contains((string) $vlanResponse->json('message'), 'Cannot create duplicate config')) {
            $patchResponse = $centralAPIHelper->patch_vlan_interface($deviceInterface);
            if ($patchResponse->ok()) {
                return ProvisioningStepResult::completed("VLAN interface {$deviceInterface->interface} patched.");
            }

            return ProvisioningStepResult::retry('Failed to patch existing VLAN interface.');
        }

        return ProvisioningStepResult::retry('Failed to post VLAN interface.');
    }
}
