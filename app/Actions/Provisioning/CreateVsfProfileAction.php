<?php

namespace App\Actions\Provisioning;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Services\Provisioning\ProvisioningStepResult;
use Illuminate\Support\Sleep;

class CreateVsfProfileAction
{
    public function execute(Device $device, CentralAPIHelper $centralAPIHelper): ProvisioningStepResult
    {
        if (! $device->sku) {
            return ProvisioningStepResult::skipped('No SKU; VSF profile not required.');
        }

        if (! $device->scope_id) {
            $scopeResult = (new ResolveDeviceScopeIdAction)->execute($device, $centralAPIHelper);
            if (! $scopeResult->isCompleted()) {
                return $scopeResult;
            }
        }

        $device->loadMissing('site');
        if ($device->site && ! $device->site->scope_id) {
            $siteScopeId = $centralAPIHelper->get_site_scope_id($device->site);
            if ($siteScopeId === null || $siteScopeId === '') {
                return ProvisioningStepResult::retry('Waiting for site scope ID...');
            }
            $device->site->scope_id = $siteScopeId;
            $device->site->save();
        }

        $response = $centralAPIHelper->post_vsf_profile($device);
        if (! $response->ok()) {
            return ProvisioningStepResult::retry('VSF profile creation failed: '.$response->json('message'));
        }

        Sleep::for(10)->seconds();
        $scopeResponse = $centralAPIHelper->getScopeIdFromCentral($device);
        if (array_key_exists('error', $scopeResponse)) {
            return ProvisioningStepResult::retry('VSF profile created; waiting for scope ID refresh...');
        }

        $scopeEntries = array_values($scopeResponse);
        if ($scopeEntries !== [] && isset($scopeEntries[0]['scopeId'])) {
            $device->scope_id = $scopeEntries[0]['scopeId'];
            $device->save();
        }

        return ProvisioningStepResult::completed('VSF profile created.');
    }
}
