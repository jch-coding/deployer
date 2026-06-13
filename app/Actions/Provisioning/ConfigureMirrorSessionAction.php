<?php

namespace App\Actions\Provisioning;

use App\Enums\ProvisioningStep;
use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Services\Provisioning\ProvisioningStepResult;
use Illuminate\Http\Client\Response;

class ConfigureMirrorSessionAction
{
    public function execute(Device $device, CentralAPIHelper $centralAPIHelper, bool $fallbackMode = false): ProvisioningStepResult
    {
        if (! ProvisioningStep::deviceHasMirrorConfig($device)) {
            return ProvisioningStepResult::skipped('No mirror session configured.');
        }

        if (! $device->scope_id) {
            $scopeResult = (new ResolveDeviceScopeIdAction)->execute($device, $centralAPIHelper);
            if (! $scopeResult->isCompleted()) {
                return $scopeResult;
            }
        }

        $settings = $centralAPIHelper->resolveMirrorSettings($device, $fallbackMode);
        if (array_key_exists('error', $settings)) {
            return ProvisioningStepResult::failed($settings['error']);
        }

        $payload = CentralAPIHelper::buildMirrorPayload(
            $device,
            $settings['name'],
            $settings['session_id'],
            $settings['dst_ports'],
            $settings['vlan_ids'],
        );

        $queryParameters = [
            'object-type' => 'LOCAL',
            'scope-id' => $device->scope_id,
            'device-function' => CentralAPIHelper::deviceFunctionQueryValue($device),
        ];

        $response = $centralAPIHelper->post_mirror($payload, $queryParameters);
        if ($response instanceof Response && $response->successful()) {
            return ProvisioningStepResult::completed('Mirror session '.$settings['name'].' created.');
        }

        $patchResponse = $centralAPIHelper->patch_mirror($payload, $queryParameters);
        if ($patchResponse instanceof Response && $patchResponse->successful()) {
            return ProvisioningStepResult::completed('Mirror session '.$settings['name'].' updated.');
        }

        return ProvisioningStepResult::retry('Failed to configure mirror session.');
    }
}
