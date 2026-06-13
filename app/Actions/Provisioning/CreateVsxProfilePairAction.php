<?php

namespace App\Actions\Provisioning;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Services\Provisioning\ProvisioningStepResult;
use App\VsxRole;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;

class CreateVsxProfilePairAction
{
    /**
     * @param  Collection<int, Device>  $devices
     */
    public function execute(string $vsxProfileName, Collection $devices, CentralAPIHelper $centralAPIHelper): ProvisioningStepResult
    {
        $validationError = $this->validatePeerPair($vsxProfileName, $devices);
        if ($validationError !== null) {
            return ProvisioningStepResult::failed($validationError);
        }

        $primary = $devices->first(fn (Device $device) => CentralAPIHelper::deviceVsxRole($device) === VsxRole::VSX_PRIMARY);
        $secondary = $devices->first(fn (Device $device) => CentralAPIHelper::deviceVsxRole($device) === VsxRole::VSX_SECONDARY);

        if ($primary === null || $secondary === null) {
            return ProvisioningStepResult::failed('VSX profile requires one VSX_PRIMARY and one VSX_SECONDARY device.');
        }

        $siteScopeId = $this->resolveSiteScopeId($primary, $centralAPIHelper);
        if ($siteScopeId === null) {
            return ProvisioningStepResult::retry('Could not resolve site scope ID for VSX profile creation.');
        }

        foreach ($devices as $device) {
            if (! $device->scope_id) {
                $scopeIdResponse = $centralAPIHelper->getScopeIdFromCentral($device);
                if (array_key_exists('error', $scopeIdResponse)) {
                    return ProvisioningStepResult::retry('Failed to get scope id for device '.$device->name);
                }
                $scopeEntries = array_values($scopeIdResponse);
                if ($scopeEntries === [] || ! isset($scopeEntries[0]['scopeId'])) {
                    return ProvisioningStepResult::retry('Failed to get scope id for device '.$device->name);
                }
                $device->scope_id = $scopeEntries[0]['scopeId'];
                $device->save();
            }
        }

        foreach ($devices as $device) {
            $peerDevice = $device->is($primary) ? $secondary : $primary;
            $role = CentralAPIHelper::deviceVsxRole($device);
            if ($role === null) {
                return ProvisioningStepResult::failed('Missing VSX role for device '.$device->name);
            }

            $portSelections = CentralAPIHelper::getVsxPortSelections($device);
            if (array_key_exists('error', $portSelections)) {
                return ProvisioningStepResult::failed((string) $portSelections['error']);
            }

            [$islPorts, $keepalivePorts] = $portSelections;

            $vrfResult = $centralAPIHelper->ensureVsxKeepAliveVrf($device);
            if (array_key_exists('error', $vrfResult)) {
                return ProvisioningStepResult::failed($vrfResult['error']);
            }

            $islResult = $centralAPIHelper->ensureVsxIslLag($device, $peerDevice, $islPorts);
            if (array_key_exists('error', $islResult)) {
                return ProvisioningStepResult::failed($islResult['error']);
            }

            $keepaliveResult = $centralAPIHelper->ensureVsxKeepaliveLag($device, $peerDevice, $role, $keepalivePorts);
            if (array_key_exists('error', $keepaliveResult)) {
                return ProvisioningStepResult::failed($keepaliveResult['error']);
            }
        }

        $payload = CentralAPIHelper::buildVsxProfilePayload($primary, $secondary);
        $response = $centralAPIHelper->post_vsx_profile($payload, $siteScopeId);

        if (is_array($response) && array_key_exists('error', $response)) {
            return ProvisioningStepResult::retry($response['error']);
        }

        if (! $response instanceof Response || ! $response->ok()) {
            $errorMessage = $response instanceof Response
                ? (string) ($response->json('message') ?? $response->body())
                : 'Invalid response';

            return ProvisioningStepResult::retry('VSX profile creation failed: '.$errorMessage);
        }

        return ProvisioningStepResult::completed('VSX profile '.$vsxProfileName.' created.');
    }

    /**
     * @param  Collection<int, Device>  $devices
     */
    private function validatePeerPair(string $vsxProfileName, Collection $devices): ?string
    {
        if ($devices->count() !== 2) {
            return 'VSX profile '.$vsxProfileName.' requires exactly 2 devices.';
        }

        $roles = $devices->map(fn (Device $device) => CentralAPIHelper::deviceVsxRole($device));
        if ($roles->contains(null)) {
            return 'All devices in VSX profile must have vsx_profile, vsx_role, and vsx_system_mac set.';
        }

        if ($roles->filter(fn (?VsxRole $role) => $role === VsxRole::VSX_PRIMARY)->count() !== 1
            || $roles->filter(fn (?VsxRole $role) => $role === VsxRole::VSX_SECONDARY)->count() !== 1) {
            return 'VSX profile requires exactly one VSX_PRIMARY and one VSX_SECONDARY device.';
        }

        $siteIds = $devices->pluck('site_id')->unique();
        if ($siteIds->count() !== 1 || $siteIds->first() === null) {
            return 'VSX profile peers must belong to the same site.';
        }

        foreach ($devices as $device) {
            if (! filled($device->group)) {
                return 'Device '.$device->name.' has no group set (required for VRF ensure).';
            }
        }

        return null;
    }

    private function resolveSiteScopeId(Device $device, CentralAPIHelper $centralAPIHelper): ?string
    {
        $site = $device->site;
        if ($site === null) {
            return null;
        }

        if (! filled($site->scope_id)) {
            $siteScopeId = $centralAPIHelper->get_site_scope_id($site);
            if ($siteScopeId === null || $siteScopeId === '') {
                return null;
            }
            $site->scope_id = $siteScopeId;
            $site->save();
        }

        return (string) $site->scope_id;
    }
}
