<?php

namespace App\Actions\Provisioning;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Services\Provisioning\ProvisioningStepResult;

class ClearLocalOverridesAction
{
    public function execute(Device $device, CentralAPIHelper $centralAPIHelper): ProvisioningStepResult
    {
        if (! $device->sku) {
            return ProvisioningStepResult::skipped('Local override cleanup applies to VSF (SKU) devices only.');
        }

        if (! $device->scope_id) {
            $scopeResult = (new ResolveDeviceScopeIdAction)->execute($device, $centralAPIHelper);
            if (! $scopeResult->isCompleted()) {
                return $scopeResult;
            }
        }

        $queryParameters = [
            'view-type' => 'LOCAL',
            'object-type' => 'LOCAL',
            'scope-id' => $device->scope_id,
            'device-function' => $device->device_function,
        ];

        $steps = [
            fn () => $this->clearLocalVlans($device, $centralAPIHelper),
            fn () => $this->clearDnsProfiles($centralAPIHelper, $queryParameters),
            fn () => $this->clearStaticRoutes($centralAPIHelper, $queryParameters),
            fn () => $this->clearNtpProfiles($centralAPIHelper, $queryParameters),
            fn () => $this->clearLocalManagementProfiles($centralAPIHelper, $queryParameters),
        ];

        foreach ($steps as $step) {
            $result = $step();
            if ($result->isFailed() || $result->isRetry()) {
                return $result;
            }
        }

        return ProvisioningStepResult::completed('Local overrides cleared.');
    }

    private function clearLocalVlans(Device $device, CentralAPIHelper $centralAPIHelper): ProvisioningStepResult
    {
        $queryParameters = [
            'view-type' => 'LOCAL',
            'object-type' => 'LOCAL',
            'scope-id' => $device->scope_id,
            'device-function' => $device->device_function,
        ];

        $response = $centralAPIHelper->get_l2_vlans($queryParameters);
        if (! $response->ok()) {
            return ProvisioningStepResult::completed('No local VLAN overrides to remove.');
        }

        $vlans = $response->json()['l2-vlan'] ?? [];
        if (! is_array($vlans)) {
            $vlans = [];
        }

        foreach ($vlans as $vlan) {
            if (($vlan['vlan'] ?? null) == 1) {
                continue;
            }
            $deleteResponse = $centralAPIHelper->delete_l2_vlan($device, (string) $vlan['vlan']);
            if (! $deleteResponse->ok()) {
                return ProvisioningStepResult::retry('Failed to delete local VLAN override.');
            }
        }

        return ProvisioningStepResult::completed('Local VLAN overrides removed.');
    }

    /**
     * @param  array<string, string>  $queryParameters
     */
    private function clearDnsProfiles(CentralAPIHelper $centralAPIHelper, array $queryParameters): ProvisioningStepResult
    {
        $response = $centralAPIHelper->get_dns_profiles($queryParameters);
        if (! $response->ok() || ! array_key_exists('profile', $response->json())) {
            return ProvisioningStepResult::completed('No local DNS overrides to remove.');
        }

        $profiles = $response->json()['profile'];
        if (! is_array($profiles)) {
            return ProvisioningStepResult::completed('No local DNS overrides to remove.');
        }

        foreach ($profiles as $profile) {
            $name = $profile['name'] ?? null;
            if ($name === null) {
                continue;
            }
            $deleteResponse = $centralAPIHelper->delete_dns_profile((string) $name, $queryParameters);
            if (! $deleteResponse->ok()) {
                return ProvisioningStepResult::retry('Failed to delete local DNS profile.');
            }
        }

        return ProvisioningStepResult::completed('Local DNS overrides removed.');
    }

    /**
     * @param  array<string, string>  $queryParameters
     */
    private function clearStaticRoutes(CentralAPIHelper $centralAPIHelper, array $queryParameters): ProvisioningStepResult
    {
        $response = $centralAPIHelper->get_static_route($queryParameters);
        if (! $response->ok() || ! array_key_exists('profile', $response->json())) {
            return ProvisioningStepResult::completed('No local static routes to remove.');
        }

        $profiles = $response->json()['profile'];
        if (! is_array($profiles)) {
            return ProvisioningStepResult::completed('No local static routes to remove.');
        }

        foreach ($profiles as $profile) {
            $name = $profile['name'] ?? null;
            if ($name === null) {
                continue;
            }
            $deleteResponse = $centralAPIHelper->delete_static_route((string) $name, $queryParameters);
            if (! $deleteResponse->ok()) {
                return ProvisioningStepResult::retry('Failed to delete local static route.');
            }
        }

        return ProvisioningStepResult::completed('Local static routes removed.');
    }

    /**
     * @param  array<string, string>  $queryParameters
     */
    private function clearNtpProfiles(CentralAPIHelper $centralAPIHelper, array $queryParameters): ProvisioningStepResult
    {
        $response = $centralAPIHelper->get_ntp_profiles($queryParameters);
        if (! $response->ok() || ! array_key_exists('profile', $response->json())) {
            return ProvisioningStepResult::completed('No local NTP overrides to remove.');
        }

        $profiles = $response->json()['profile'];
        if (! is_array($profiles)) {
            return ProvisioningStepResult::completed('No local NTP overrides to remove.');
        }

        foreach ($profiles as $profile) {
            $name = $profile['name'] ?? null;
            if ($name === null) {
                continue;
            }
            $deleteResponse = $centralAPIHelper->delete_ntp_profile((string) $name, $queryParameters);
            if (! $deleteResponse->ok()) {
                return ProvisioningStepResult::retry('Failed to delete local NTP profile.');
            }
        }

        return ProvisioningStepResult::completed('Local NTP overrides removed.');
    }

    /**
     * @param  array<string, string>  $queryParameters
     */
    private function clearLocalManagementProfiles(CentralAPIHelper $centralAPIHelper, array $queryParameters): ProvisioningStepResult
    {
        $response = $centralAPIHelper->get_local_management_profiles($queryParameters);
        if (! $response->ok()) {
            return ProvisioningStepResult::completed('No local management overrides to remove.');
        }

        $profiles = $response->json()['profile'] ?? [];
        if (! is_array($profiles)) {
            return ProvisioningStepResult::completed('No local management overrides to remove.');
        }

        foreach ($profiles as $profile) {
            $name = $profile['name'] ?? null;
            if ($name === null || $name === '') {
                continue;
            }
            $deleteResponse = $centralAPIHelper->delete_local_management_profile((string) $name, $queryParameters);
            if (! $deleteResponse->ok()) {
                return ProvisioningStepResult::retry('Failed to delete local management profile.');
            }
        }

        return ProvisioningStepResult::completed('Local management overrides removed.');
    }
}
