<?php

namespace App\Actions\Provisioning;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Services\Provisioning\ProvisioningStepResult;

class WaitForVsfStackScopeAction
{
    public function execute(Device $device, CentralAPIHelper $centralAPIHelper, ?string $previousScopeId = null): ProvisioningStepResult
    {
        if (! $device->sku) {
            return ProvisioningStepResult::skipped('Not a VSF device.');
        }

        $scopeResponse = $centralAPIHelper->getScopeIdFromCentral($device);
        if (array_key_exists('error', $scopeResponse)) {
            return ProvisioningStepResult::retry('Waiting for VSF stack scope ID...');
        }

        $scopeEntries = array_values($scopeResponse);
        $newScopeId = $scopeEntries[0]['scopeId'] ?? null;
        if ($newScopeId === null) {
            return ProvisioningStepResult::retry('Stack scope ID not available yet.');
        }

        $device->refresh();
        if ($previousScopeId !== null && $newScopeId === $previousScopeId && ! $device->stack_id) {
            $device->scope_id = $newScopeId;
            $device->save();

            return ProvisioningStepResult::retry('Waiting for stack ID after VSF profile creation...');
        }

        $device->scope_id = $newScopeId;
        $device->save();

        if (! $device->stack_id) {
            $switches = $centralAPIHelper->get_all_switches(['serial' => $device->serial]);
            if (is_array($switches) && isset($switches['error'])) {
                return ProvisioningStepResult::retry('Waiting for stack ID...');
            }
            $switchList = $switches['switch'] ?? $switches['switches'] ?? [];
            if (is_array($switchList)) {
                $match = array_find($switchList, fn ($row) => ($row['serial'] ?? null) === $device->serial);
                if (is_array($match) && ! empty($match['stack-id'])) {
                    $device->stack_id = (string) $match['stack-id'];
                    $device->save();
                }
            }
        }

        if (! $device->stack_id) {
            return ProvisioningStepResult::retry('Waiting for stack ID...');
        }

        return ProvisioningStepResult::completed('VSF stack scope and stack ID resolved.');
    }
}
