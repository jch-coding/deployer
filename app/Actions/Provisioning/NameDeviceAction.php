<?php

namespace App\Actions\Provisioning;

use App\Enums\ProvisioningStep;
use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Services\Provisioning\ClassicDeviceOnlineService;
use App\Services\Provisioning\ProvisioningStepResult;
use Illuminate\Http\Client\Response;

class NameDeviceAction
{
    public function execute(
        Device $device,
        CentralAPIHelper $centralAPIHelper,
        bool $onlyUpdateDifferentNames = false,
        ?ClassicDeviceOnlineService $classicDeviceOnlineService = null,
    ): ProvisioningStepResult {
        $classicDeviceOnlineService ??= new ClassicDeviceOnlineService;

        if (ProvisioningStep::isApDevice($device) && ! ProvisioningStep::apHasExplicitName($device)) {
            return ProvisioningStepResult::skipped('No hostname provided; naming skipped for AP.');
        }

        $onlineCheck = $this->ensureDeviceOnline($device, $centralAPIHelper, $classicDeviceOnlineService);
        if ($onlineCheck !== null) {
            return $onlineCheck;
        }

        if (! $device->scope_id) {
            $scopeResult = (new ResolveDeviceScopeIdAction)->execute($device, $centralAPIHelper);
            if (! $scopeResult->isCompleted()) {
                return $scopeResult;
            }
        }

        if ($onlyUpdateDifferentNames) {
            $systemInfoResponse = $centralAPIHelper->getSystemInfo($device);
            $hostname = CentralAPIHelper::hostnameFromSystemInfoResponse($systemInfoResponse);
            if ($hostname === null) {
                return ProvisioningStepResult::retry('Failed to read system info from Central. Retrying...');
            }

            if (CentralAPIHelper::hostnameMatchesExpected($hostname, (string) $device->name)) {
                return ProvisioningStepResult::completed(
                    "Hostname for {$device->name} already matches Central; skipped update.",
                );
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

    private function ensureDeviceOnline(
        Device $device,
        CentralAPIHelper $centralAPIHelper,
        ClassicDeviceOnlineService $classicDeviceOnlineService,
    ): ?ProvisioningStepResult {
        $function = (string) $device->device_function;
        $needsSwitch = str_contains($function, 'SWITCH') || ! str_contains($function, 'AP');
        $needsAp = str_contains($function, 'AP') || ! str_contains($function, 'SWITCH');

        $switchStatuses = [];
        $apStatuses = [];

        if ($needsSwitch) {
            $switchResult = $centralAPIHelper->classic_collect_all_switches();
            if (array_key_exists('error', $switchResult)) {
                return ProvisioningStepResult::failed(
                    'Could not read Classic Central switch inventory: '.(string) $switchResult['error'],
                );
            }
            $switchStatuses = $classicDeviceOnlineService->statusesIndexedBySerial($switchResult['switches'] ?? []);
        }

        if ($needsAp) {
            $apResult = $centralAPIHelper->classic_collect_all_aps();
            if (array_key_exists('error', $apResult)) {
                return ProvisioningStepResult::failed(
                    'Could not read Classic Central AP inventory: '.(string) $apResult['error'],
                );
            }
            $apStatuses = $classicDeviceOnlineService->statusesIndexedBySerial($apResult['aps'] ?? []);
        }

        if ($classicDeviceOnlineService->isDeviceUp($device, $switchStatuses, $apStatuses)) {
            return null;
        }

        $status = $classicDeviceOnlineService->currentStatus($device, $switchStatuses, $apStatuses);

        return ProvisioningStepResult::failed(
            "Device {$device->name} is not online in Classic Central (status: {$status}); skipped naming.",
        );
    }
}
