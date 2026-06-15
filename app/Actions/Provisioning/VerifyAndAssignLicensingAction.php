<?php

namespace App\Actions\Provisioning;

use App\Helper\CentralAPIHelper;
use App\Helper\GreenLakeAPIHelper;
use App\LicenseType;
use App\Models\Client;
use App\Models\Device;
use App\Models\LicensingInventoryDevice;
use App\Services\LicensingInventoryService;
use App\Services\LicensingPoolResolver;
use App\Services\LicensingSyncService;
use App\Services\Provisioning\ProvisioningStepResult;

class VerifyAndAssignLicensingAction
{
    public function __construct(
        private readonly LicensingSyncService $licensingSyncService,
        private readonly LicensingInventoryService $licensingInventoryService,
        private readonly LicensingPoolResolver $licensingPoolResolver,
    ) {}

    /**
     * @param  array{license_tag?: string, license_type?: string}  $resolvedLicense
     */
    public function execute(Device $device, Client $client, array $resolvedLicense): ProvisioningStepResult
    {
        $centralHelper = new CentralAPIHelper($client);
        $greenLakeHelper = new GreenLakeAPIHelper($client);

        if ($this->licensingSyncService->needsInitialSync($client)) {
            try {
                $this->licensingSyncService->syncFromCentral($client, $centralHelper, $greenLakeHelper);
                $client->refresh();
            } catch (\Throwable $e) {
                return ProvisioningStepResult::failed('Licensing sync failed: '.$e->getMessage());
            }
        }

        $inventoryRow = $client->licensingInventoryDevices()
            ->where('serial', $device->serial)
            ->first();

        if (! $inventoryRow instanceof LicensingInventoryDevice) {
            return ProvisioningStepResult::failed("Device {$device->serial} is not in GreenLake inventory. Renew licensing and try again.");
        }

        if ($inventoryRow->licensed && trim((string) $inventoryRow->subscription_key) !== '') {
            return ProvisioningStepResult::completed('Device already has an active subscription.');
        }

        $licenseTag = trim((string) ($resolvedLicense['license_tag'] ?? $device->license_tag ?? ''));
        $licenseTypeValue = trim((string) ($resolvedLicense['license_type'] ?? $device->license_type ?? ''));

        if ($licenseTag === '' || $licenseTypeValue === '') {
            return ProvisioningStepResult::failed('License tag and type are required to assign a subscription.');
        }

        $licenseType = LicenseType::tryFromValue($licenseTypeValue);
        if ($licenseType === null) {
            return ProvisioningStepResult::failed("Invalid license type \"{$licenseTypeValue}\".");
        }

        $licensingOptions = $this->licensingInventoryService->buildLicensingOptionsFromCache($client);
        if (($licensingOptions['central_error'] ?? null) !== null) {
            return ProvisioningStepResult::failed((string) $licensingOptions['central_error']);
        }

        $availableSubscriptions = $licensingOptions['available_subscriptions'];
        $capacityError = $this->licensingPoolResolver->validatePoolCapacity(
            $licenseTag,
            $licenseType,
            1,
            $availableSubscriptions,
        );
        if ($capacityError !== null) {
            return ProvisioningStepResult::failed($capacityError['error']);
        }

        $allocations = $this->licensingPoolResolver->allocateDevices(
            [$device->id],
            $licenseTag,
            $licenseType,
            $availableSubscriptions,
        );
        if (! isset($allocations[$device->id])) {
            return ProvisioningStepResult::failed('Could not allocate a subscription from the selected tag/type pool.');
        }

        $greenlakeDeviceId = trim((string) $inventoryRow->greenlake_device_id);
        if ($greenlakeDeviceId === '') {
            return ProvisioningStepResult::failed("Device {$device->serial} is not linked in GreenLake.");
        }

        $result = $greenLakeHelper->assignSubscriptionToDevices([$greenlakeDeviceId], $allocations[$device->id]);
        if ($result['error'] !== null) {
            return ProvisioningStepResult::failed('GreenLake assign failed: '.$result['error']);
        }

        $failedResponses = array_filter(
            $result['responses'],
            fn ($response) => ! $response->ok(),
        );
        if ($failedResponses !== []) {
            return ProvisioningStepResult::failed('GreenLake assign returned an error for one or more devices.');
        }

        return ProvisioningStepResult::completed("Assigned subscription ({$allocations[$device->id]}) to device {$device->serial}.");
    }
}
