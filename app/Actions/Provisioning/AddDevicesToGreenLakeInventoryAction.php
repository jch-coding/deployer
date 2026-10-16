<?php

namespace App\Actions\Provisioning;

use App\Helper\GreenLakeAPIHelper;
use App\Models\Device;
use App\Services\Provisioning\ProvisioningStepResult;
use Illuminate\Support\Facades\Log;

class AddDevicesToGreenLakeInventoryAction
{
    /**
     * @param  array{
     *     location_id?: string|null,
     *     location_name?: string|null,
     *     application_id?: string|null,
     *     application_region?: string|null,
     *     application_name?: string|null,
     *     tags?: array<string, string>|null
     * }  $config
     */
    public function execute(Device $device, GreenLakeAPIHelper $greenLakeAPIHelper, array $config = []): ProvisioningStepResult
    {
        $serial = trim((string) $device->serial);
        $mac = trim((string) ($device->mac_address ?? ''));
        if ($serial === '' || $mac === '') {
            return ProvisioningStepResult::failed('Device is missing serial or mac_address.');
        }

        $tags = $config['tags'] ?? null;
        $tags = is_array($tags) && $tags !== [] ? $tags : null;

        $entry = [
            'serial' => $serial,
            'mac_address' => $mac,
        ];
        if ($tags !== null) {
            $entry['tags'] = $tags;
        }

        $result = $greenLakeAPIHelper->addNetworkDevices([$entry], sleepBetweenPolls: false);
        $perSerial = $result['results'] ?? [];
        $ok = $result['success'] === true;
        if (array_key_exists($serial, $perSerial)) {
            $ok = $perSerial[$serial] === true;
        }

        if (! $ok) {
            $detail = (string) ($result['error'] ?? 'GreenLake add failed.');
            Log::error('Failed to add device to GreenLake inventory', [
                'serial' => $serial,
                'error' => $detail,
            ]);

            return ProvisioningStepResult::failed("Failed to add device {$serial} to GreenLake inventory: {$detail}");
        }

        $messages = ["Device {$serial} added to GreenLake inventory."];

        $locationId = trim((string) ($config['location_id'] ?? ''));
        $locationName = trim((string) ($config['location_name'] ?? ''));
        if ($locationName === '') {
            $locationName = $locationId;
        }

        $applicationId = trim((string) ($config['application_id'] ?? ''));
        $applicationRegion = trim((string) ($config['application_region'] ?? ''));
        $applicationName = trim((string) ($config['application_name'] ?? ''));
        if ($applicationName === '') {
            $applicationName = $applicationId !== '' && $applicationRegion !== ''
                ? "{$applicationId} ({$applicationRegion})"
                : $applicationId;
        }

        if ($tags === null && $locationId === '' && ($applicationId === '' || $applicationRegion === '')) {
            return ProvisioningStepResult::completed(implode(' ', $messages));
        }

        $greenlakeId = $this->resolveGreenLakeId($greenLakeAPIHelper, $serial);
        if ($greenlakeId === null) {
            return ProvisioningStepResult::failed(
                "Device {$serial} was added to inventory, but its GreenLake device id could not be resolved for post-add metadata."
            );
        }

        if ($tags !== null) {
            $assignResult = $greenLakeAPIHelper->assignTagsToDevices([$greenlakeId], $tags);
            if (($assignResult['success'] ?? false) !== true) {
                $detail = (string) ($assignResult['error'] ?? 'GreenLake tag update failed.');

                return ProvisioningStepResult::failed(
                    "Device {$serial} was added to inventory, but tag update failed: {$detail}"
                );
            }
            $messages[] = 'GreenLake tags applied.';
        }

        if ($locationId !== '') {
            $assignResult = $greenLakeAPIHelper->assignLocationToDevices([$greenlakeId], $locationId);
            if (($assignResult['success'] ?? false) !== true) {
                $detail = (string) ($assignResult['error'] ?? 'GreenLake location assignment failed.');

                return ProvisioningStepResult::failed(
                    "Device {$serial} was added to inventory, but location assignment failed: {$detail}"
                );
            }
            $messages[] = "Location \"{$locationName}\" assigned.";
        }

        if ($applicationId !== '' && $applicationRegion !== '') {
            $assignResult = $greenLakeAPIHelper->assignDevicesToApplication(
                [$greenlakeId],
                $applicationId,
                $applicationRegion,
            );
            if (($assignResult['success'] ?? false) !== true) {
                $detail = (string) ($assignResult['error'] ?? 'GreenLake service assignment failed.');

                return ProvisioningStepResult::failed(
                    "Device {$serial} was added to inventory, but service assignment failed: {$detail}"
                );
            }
            $messages[] = "Service \"{$applicationName}\" assigned.";
        }

        return ProvisioningStepResult::completed(implode(' ', $messages));
    }

    private function resolveGreenLakeId(GreenLakeAPIHelper $greenLakeAPIHelper, string $serial): ?string
    {
        foreach ([0, 2, 5] as $delaySeconds) {
            if ($delaySeconds > 0) {
                sleep($delaySeconds);
            }

            $idsBySerial = $greenLakeAPIHelper->deviceIdsBySerial([$serial]);
            if (GreenLakeAPIHelper::isCollectError($idsBySerial)) {
                return null;
            }

            $greenlakeId = trim((string) (
                $idsBySerial[$serial]
                ?? $idsBySerial[strtoupper($serial)]
                ?? ''
            ));
            if ($greenlakeId !== '') {
                return $greenlakeId;
            }
        }

        return null;
    }
}
