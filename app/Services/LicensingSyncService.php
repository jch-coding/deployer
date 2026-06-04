<?php

namespace App\Services;

use App\Helper\CentralAPIHelper;
use App\Helper\GreenLakeAPIHelper;
use App\Models\Client;
use App\Models\Device;
use App\Models\LicensingInventoryDevice;
use Illuminate\Support\Facades\DB;

class LicensingSyncService
{
    public function needsInitialSync(Client $client): bool
    {
        return $client->licensing_synced_at === null;
    }

    /**
     * @throws LicensingSyncException
     */
    public function syncFromCentral(
        Client $client,
        CentralAPIHelper $centralHelper,
        ?GreenLakeAPIHelper $greenLakeHelper = null,
    ): void {
        $greenLakeHelper ??= new GreenLakeAPIHelper($client);

        $enabledResult = $centralHelper->classic_parse_enabled_services($centralHelper->classic_get_enabled_services());
        if (isset($enabledResult['error'])) {
            $this->recordSyncError($client, $enabledResult['error']);
            throw new LicensingSyncException($enabledResult['error']);
        }

        $greenLakeSubscriptionItems = $greenLakeHelper->collectSubscriptions();
        if (GreenLakeAPIHelper::isCollectError($greenLakeSubscriptionItems)) {
            $error = (string) $greenLakeSubscriptionItems['error'];
            $this->recordSyncError($client, $error);
            throw new LicensingSyncException($error);
        }

        $greenLakeSubscriptionsResult = $greenLakeHelper->parseSubscriptionsFromItems($greenLakeSubscriptionItems);

        $centralSubscriptionsResult = $centralHelper->classic_parse_subscriptions($centralHelper->classic_get_subscriptions());
        $centralSubscriptions = [];
        if (! isset($centralSubscriptionsResult['error'])) {
            $centralSubscriptions = $centralSubscriptionsResult['subscriptions'];
        }

        $mergedSubscriptions = $this->mergeSubscriptions(
            $greenLakeSubscriptionsResult['subscriptions'],
            $centralSubscriptions,
        );

        $greenLakeDevicesResult = $greenLakeHelper->collectDevices();
        if (GreenLakeAPIHelper::isCollectError($greenLakeDevicesResult)) {
            $error = (string) $greenLakeDevicesResult['error'];
            $this->recordSyncError($client, $error);
            throw new LicensingSyncException($error);
        }

        $subscriptionsByGreenLakeId = $this->indexSubscriptionsByGreenLakeId($mergedSubscriptions);
        $subscriptionsByKey = $this->indexSubscriptions($mergedSubscriptions);

        $inventoryDevices = [];
        foreach ($greenLakeDevicesResult as $device) {
            if (! is_array($device)) {
                continue;
            }

            $inventoryDevices[] = $greenLakeHelper->normalizeGreenLakeDevice(
                $device,
                $subscriptionsByGreenLakeId,
                $subscriptionsByKey,
            );
        }

        $this->persist($client, $enabledResult['services'], $mergedSubscriptions, $inventoryDevices);
    }

    /**
     * @param  array<int, array<string, mixed>>  $greenLakeSubscriptions
     * @param  array<int, array<string, mixed>>  $centralSubscriptions
     * @return array<int, array<string, mixed>>
     */
    private function mergeSubscriptions(array $greenLakeSubscriptions, array $centralSubscriptions): array
    {
        $mergedByKey = [];

        foreach ($greenLakeSubscriptions as $subscription) {
            if (! is_array($subscription)) {
                continue;
            }

            $key = (string) ($subscription['subscription_key'] ?? '');
            if ($key === '') {
                continue;
            }

            $mergedByKey[$key] = $subscription;
        }

        foreach ($centralSubscriptions as $subscription) {
            if (! is_array($subscription)) {
                continue;
            }

            $normalized = $this->normalizeCentralSubscription($subscription);
            $key = (string) ($normalized['subscription_key'] ?? '');
            if ($key === '') {
                continue;
            }

            if (! isset($mergedByKey[$key])) {
                $mergedByKey[$key] = $normalized;

                continue;
            }

            $mergedByKey[$key] = $this->overlayCentralSubscription($mergedByKey[$key], $normalized);
        }

        return array_values($mergedByKey);
    }

    /**
     * @param  array<string, mixed>  $greenLake
     * @param  array<string, mixed>  $central
     * @return array<string, mixed>
     */
    private function overlayCentralSubscription(array $greenLake, array $central): array
    {
        $overlay = $greenLake;

        foreach (['acpapp_name', 'subscription_type'] as $field) {
            if (($overlay[$field] ?? '') === '' && ($central[$field] ?? '') !== '') {
                $overlay[$field] = $central[$field];
            }
        }

        if (($overlay['subscription_sku'] ?? '') === '' && ($central['subscription_sku'] ?? '') !== '') {
            $overlay['subscription_sku'] = $central['subscription_sku'];
        }

        return $overlay;
    }

    /**
     * @param  array<int, string>  $enabledServices
     * @param  array<int, array<string, mixed>>  $subscriptions
     * @param  array<int, array<string, mixed>>  $inventoryDevices
     */
    private function persist(Client $client, array $enabledServices, array $subscriptions, array $inventoryDevices): void
    {
        $subscriptionsByKey = $this->indexSubscriptions($subscriptions);
        $deployerDevicesBySerial = Device::query()
            ->where('client_id', $client->id)
            ->get()
            ->keyBy('serial');
        $syncedAt = now();

        DB::transaction(function () use (
            $client,
            $enabledServices,
            $subscriptions,
            $inventoryDevices,
            $subscriptionsByKey,
            $deployerDevicesBySerial,
            $syncedAt,
        ): void {
            $client->clientSubscriptions()->delete();

            foreach ($subscriptions as $subscription) {
                if (! is_array($subscription)) {
                    continue;
                }

                $normalized = $this->normalizePersistedSubscription($subscription);
                if ($normalized['subscription_key'] === '') {
                    continue;
                }

                $client->clientSubscriptions()->create([
                    'subscription_key' => $normalized['subscription_key'],
                    'greenlake_subscription_id' => $normalized['greenlake_subscription_id'],
                    'subscription_sku' => $normalized['subscription_sku'],
                    'license_type' => $normalized['license_type'],
                    'start_date' => $normalized['start_date'],
                    'end_date' => $normalized['end_date'],
                    'status' => $normalized['status'],
                    'subscription_type' => $normalized['subscription_type'],
                    'available' => $normalized['available'],
                    'quantity' => $normalized['quantity'],
                    'acpapp_name' => $normalized['acpapp_name'],
                    'tags' => $normalized['tags'],
                ]);
            }

            $client->licensingInventoryDevices()->delete();

            foreach ($inventoryDevices as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $serial = (string) ($item['serial'] ?? '');
                if ($serial === '') {
                    continue;
                }

                $subscriptionKey = (string) ($item['subscription_key'] ?? $item['subscriptionKey'] ?? '');
                $services = $item['assigned_services'] ?? $item['services'] ?? [];
                if (! is_array($services)) {
                    $services = [];
                }
                $services = array_values(array_filter($services, fn ($service) => is_string($service) && $service !== ''));

                $deployerDevice = $deployerDevicesBySerial->get($serial);

                LicensingInventoryDevice::create([
                    'client_id' => $client->id,
                    'serial' => $serial,
                    'greenlake_device_id' => (string) ($item['greenlake_device_id'] ?? ''),
                    'model' => (string) ($item['model'] ?? ''),
                    'mac' => (string) ($item['mac'] ?? $item['macaddr'] ?? ''),
                    'device_type' => (string) ($item['device_type'] ?? ''),
                    'name' => (string) ($item['name'] ?? $serial),
                    'licensed' => (bool) ($item['licensed'] ?? ($services !== [] || $subscriptionKey !== '')),
                    'assigned_services' => $services,
                    'subscription_key' => $subscriptionKey,
                    'deployer_device_id' => $deployerDevice?->id,
                ]);

                if ($deployerDevice !== null) {
                    $subscription = $subscriptionsByKey[$subscriptionKey] ?? null;
                    $deployerDevice->update([
                        'licensing_subscription_key' => $subscriptionKey !== '' ? $subscriptionKey : null,
                        'licensing_assigned_services' => $services,
                        'licensing_licensed' => (bool) ($item['licensed'] ?? ($services !== [] || $subscriptionKey !== '')),
                        'licensing_subscription_sku' => (string) ($subscription['subscription_sku'] ?? ''),
                        'licensing_license_type' => (string) ($subscription['license_type'] ?? ''),
                        'licensing_start_date' => $subscription['start_date'] ?? null,
                        'licensing_end_date' => $subscription['end_date'] ?? null,
                        'licensing_subscription_status' => (string) ($subscription['status'] ?? ''),
                        'licensing_synced_at' => $syncedAt,
                    ]);
                }
            }

            $client->update([
                'licensing_enabled_services' => $enabledServices,
                'licensing_synced_at' => $syncedAt,
                'licensing_sync_error' => null,
            ]);
        });
    }

    private function recordSyncError(Client $client, string $error): void
    {
        $client->update([
            'licensing_sync_error' => $error,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $subscriptions
     * @return array<string, array<string, mixed>>
     */
    private function indexSubscriptions(array $subscriptions): array
    {
        $indexed = [];

        foreach ($subscriptions as $subscription) {
            if (! is_array($subscription)) {
                continue;
            }

            $key = (string) ($subscription['subscription_key'] ?? '');
            if ($key === '') {
                continue;
            }

            $indexed[$key] = $this->normalizePersistedSubscription($subscription);
        }

        return $indexed;
    }

    /**
     * @param  array<int, array<string, mixed>>  $subscriptions
     * @return array<string, array<string, mixed>>
     */
    private function indexSubscriptionsByGreenLakeId(array $subscriptions): array
    {
        $indexed = [];

        foreach ($subscriptions as $subscription) {
            if (! is_array($subscription)) {
                continue;
            }

            $id = (string) ($subscription['greenlake_subscription_id'] ?? '');
            if ($id === '') {
                continue;
            }

            $indexed[$id] = $this->normalizePersistedSubscription($subscription);
        }

        return $indexed;
    }

    /**
     * @param  array<string, mixed>  $subscription
     * @return array<string, mixed>
     */
    private function normalizeCentralSubscription(array $subscription): array
    {
        return [
            'subscription_key' => (string) ($subscription['subscription_key'] ?? ''),
            'greenlake_subscription_id' => (string) ($subscription['greenlake_subscription_id'] ?? ''),
            'subscription_sku' => (string) ($subscription['sku'] ?? $subscription['subscription_sku'] ?? ''),
            'license_type' => (string) ($subscription['license_type'] ?? ''),
            'start_date' => $this->normalizeEpoch($subscription['start_date'] ?? null),
            'end_date' => $this->normalizeEpoch($subscription['end_date'] ?? null),
            'status' => (string) ($subscription['status'] ?? ''),
            'subscription_type' => (string) ($subscription['subscription_type'] ?? ''),
            'available' => (int) ($subscription['available'] ?? 0),
            'quantity' => (int) ($subscription['quantity'] ?? 0),
            'acpapp_name' => (string) ($subscription['acpapp_name'] ?? ''),
            'tags' => GreenLakeAPIHelper::normalizeTagKeys($subscription['tags'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $subscription
     * @return array<string, mixed>
     */
    private function normalizePersistedSubscription(array $subscription): array
    {
        return [
            'subscription_key' => (string) ($subscription['subscription_key'] ?? ''),
            'greenlake_subscription_id' => (string) ($subscription['greenlake_subscription_id'] ?? ''),
            'subscription_sku' => (string) ($subscription['subscription_sku'] ?? $subscription['sku'] ?? ''),
            'license_type' => (string) ($subscription['license_type'] ?? ''),
            'start_date' => $this->normalizeEpoch($subscription['start_date'] ?? null),
            'end_date' => $this->normalizeEpoch($subscription['end_date'] ?? null),
            'status' => (string) ($subscription['status'] ?? ''),
            'subscription_type' => (string) ($subscription['subscription_type'] ?? ''),
            'available' => (int) ($subscription['available'] ?? 0),
            'quantity' => (int) ($subscription['quantity'] ?? 0),
            'acpapp_name' => (string) ($subscription['acpapp_name'] ?? ''),
            'tags' => GreenLakeAPIHelper::normalizeTagKeys($subscription['tags'] ?? []),
        ];
    }

    private function normalizeEpoch(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}
