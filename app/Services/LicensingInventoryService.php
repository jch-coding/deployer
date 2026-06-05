<?php

namespace App\Services;

use App\Helper\CentralAPIHelper;
use App\Helper\GreenLakeAPIHelper;
use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\Device;
use App\Models\LicensingInventoryDevice;
use Carbon\Carbon;

class LicensingInventoryService
{
    public function __construct(
        private readonly LicensingSyncService $licensingSyncService,
    ) {}

    /**
     * @param  array<string, string>  $filters
     * @return array{
     *     devices: array<int, array<string, mixed>>,
     *     enabled_services: array<int, string>,
     *     available_subscriptions: array<int, array<string, mixed>>,
     *     subscriptions_by_key: array<string, array<string, mixed>>,
     *     subscription_summary: array<string, mixed>,
     *     filter_options: array<string, mixed>,
     *     central_error: string|null,
     *     licensing_synced_at: string|null
     * }
     */
    public function build(
        Client $client,
        CentralAPIHelper $centralHelper,
        GreenLakeAPIHelper $greenLakeHelper,
        array $filters = [],
    ): array {
        if ($this->licensingSyncService->needsInitialSync($client)) {
            try {
                $this->licensingSyncService->syncFromCentral($client, $centralHelper, $greenLakeHelper);
                $client->refresh();
            } catch (LicensingSyncException $e) {
                return $this->emptyPayload($e->getMessage(), licensingSyncedAt: null);
            }
        }

        return $this->buildFromCache($client, $filters);
    }

    /**
     * @return array{
     *     enabled_services: array<int, string>,
     *     available_subscriptions: array<int, array<string, mixed>>,
     *     subscriptions_by_key: array<string, array<string, mixed>>,
     *     central_error: string|null,
     *     licensing_synced_at: string|null
     * }
     */
    /**
     * @return array{
     *     enabled_services: array<int, string>,
     *     available_subscriptions: array<int, array<string, mixed>>,
     *     subscriptions_by_key: array<string, array<string, mixed>>,
     *     central_error: string|null,
     *     licensing_synced_at: string|null
     * }
     */
    public function resolveLicensingOptions(
        Client $client,
        CentralAPIHelper $centralHelper,
        GreenLakeAPIHelper $greenLakeHelper,
    ): array {
        if ($this->licensingSyncService->needsInitialSync($client)) {
            try {
                $this->licensingSyncService->syncFromCentral($client, $centralHelper, $greenLakeHelper);
                $client->refresh();
            } catch (LicensingSyncException $e) {
                return [
                    'enabled_services' => [],
                    'available_subscriptions' => [],
                    'subscriptions_by_key' => [],
                    'license_tags' => [],
                    'central_error' => $e->getMessage(),
                    'licensing_synced_at' => null,
                ];
            }
        }

        return $this->buildLicensingOptionsFromCache($client);
    }

    public function buildLicensingOptionsFromCache(Client $client): array
    {
        if ($client->licensing_synced_at === null) {
            return [
                'enabled_services' => [],
                'available_subscriptions' => [],
                'subscriptions_by_key' => [],
                'license_tags' => [],
                'central_error' => $client->licensing_sync_error ?? 'Licensing has not been synced yet. Use Renew licensing to fetch from Central.',
                'licensing_synced_at' => null,
            ];
        }

        $subscriptions = $client->clientSubscriptions()->get();
        $subscriptionsArray = $subscriptions->map(fn (ClientSubscription $s) => $s->toNormalizedArray())->all();
        $subscriptionsByKey = $this->indexNormalizedSubscriptions($subscriptionsArray);

        $inventoryForAvailable = $client->licensingInventoryDevices()
            ->get(['subscription_key'])
            ->map(fn (LicensingInventoryDevice $row): array => [
                'subscription_key' => $row->subscription_key,
            ])
            ->all();

        return [
            'enabled_services' => $client->licensing_enabled_services ?? [],
            'available_subscriptions' => $this->buildAvailableSubscriptions($subscriptionsArray, $inventoryForAvailable),
            'subscriptions_by_key' => $subscriptionsByKey,
            'license_tags' => $this->collectLicenseTags($subscriptionsArray),
            'central_error' => $client->licensing_sync_error,
            'licensing_synced_at' => $client->licensing_synced_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, string>  $filters
     * @return array{
     *     devices: array<int, array<string, mixed>>,
     *     enabled_services: array<int, string>,
     *     available_subscriptions: array<int, array<string, mixed>>,
     *     subscriptions_by_key: array<string, array<string, mixed>>,
     *     subscription_summary: array<string, mixed>,
     *     filter_options: array<string, mixed>,
     *     central_error: string|null,
     *     licensing_synced_at: string|null
     * }
     */
    public function buildFromCache(Client $client, array $filters = []): array
    {
        if ($client->licensing_synced_at === null) {
            return $this->emptyPayload(
                $client->licensing_sync_error ?? 'Licensing has not been synced yet. Use Renew licensing to refresh data.',
                licensingSyncedAt: null,
            );
        }

        $enabledServices = $client->licensing_enabled_services ?? [];
        $subscriptions = $client->clientSubscriptions()->get();
        $subscriptionsArray = $subscriptions->map(fn (ClientSubscription $s) => $s->toNormalizedArray())->all();
        $subscriptionsByKey = $this->indexNormalizedSubscriptions($subscriptionsArray);

        $deviceSkusBySerial = Device::query()
            ->where('client_id', $client->id)
            ->whereNotNull('sku')
            ->pluck('sku', 'serial')
            ->map(fn ($sku) => $sku instanceof \BackedEnum ? $sku->name : (string) $sku)
            ->all();

        $inventoryRows = $client->licensingInventoryDevices()->get();
        $rawInventoryForAvailable = [];

        $devices = $inventoryRows->map(function (LicensingInventoryDevice $row) use (
            $subscriptionsByKey,
            $deviceSkusBySerial,
            &$rawInventoryForAvailable,
        ): array {
            $rawInventoryForAvailable[] = [
                'serial' => $row->serial,
                'subscription_key' => $row->subscription_key,
                'services' => $row->assigned_services ?? [],
            ];

            return $this->enrichInventoryRow($row, $subscriptionsByKey, $deviceSkusBySerial);
        })->all();

        $filterOptions = $this->buildFilterOptions($devices, $subscriptionsArray);
        $devices = $this->applyFilters($devices, $filters);
        $summary = $this->buildSummary($devices, $subscriptionsArray);
        $availableSubscriptions = $this->buildAvailableSubscriptions($subscriptionsArray, $rawInventoryForAvailable);

        return [
            'devices' => array_values($devices),
            'enabled_services' => $enabledServices,
            'available_subscriptions' => $availableSubscriptions,
            'subscriptions_by_key' => $subscriptionsByKey,
            'subscription_summary' => $summary,
            'filter_options' => $filterOptions,
            'central_error' => $client->licensing_sync_error,
            'licensing_synced_at' => $client->licensing_synced_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $subscriptionsByKey
     * @param  array<string, string>  $deviceSkusBySerial
     * @return array<string, mixed>
     */
    private function enrichInventoryRow(
        LicensingInventoryDevice $row,
        array $subscriptionsByKey,
        array $deviceSkusBySerial,
    ): array {
        $serial = $row->serial;
        $subscriptionKey = $row->subscription_key;
        $services = $row->assigned_services ?? [];
        $subscription = $subscriptionsByKey[$subscriptionKey] ?? null;

        return [
            'serial' => $serial,
            'model' => $row->model,
            'mac' => $row->mac,
            'device_type' => $row->device_type,
            'name' => $row->name !== '' ? $row->name : $serial,
            'licensed' => $row->licensed,
            'assigned_services' => $services,
            'subscription_key' => $subscriptionKey,
            'subscription_sku' => (string) ($subscription['subscription_sku'] ?? ''),
            'license_type' => (string) ($subscription['license_type'] ?? ''),
            'start_date' => $subscription['start_date'] ?? null,
            'end_date' => $subscription['end_date'] ?? null,
            'subscription_status' => (string) ($subscription['status'] ?? ''),
            'subscription_type' => (string) ($subscription['subscription_type'] ?? ''),
            'acpapp_name' => (string) ($subscription['acpapp_name'] ?? ''),
            'tags' => GreenLakeAPIHelper::normalizeTagKeys($subscription['tags'] ?? []),
            'greenlake_device_id' => $row->greenlake_device_id,
            'device_sku' => (string) ($deviceSkusBySerial[$serial] ?? ''),
            'deployer_device_id' => $row->deployer_device_id,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $subscriptions
     * @return array<string, array<string, mixed>>
     */
    private function indexNormalizedSubscriptions(array $subscriptions): array
    {
        $indexed = [];

        foreach ($subscriptions as $subscription) {
            $key = (string) ($subscription['subscription_key'] ?? '');
            if ($key === '') {
                continue;
            }

            $indexed[$key] = $subscription;
        }

        return $indexed;
    }

    /**
     * @param  array<int, array<string, mixed>>  $devices
     * @param  array<int, array<string, mixed>>  $subscriptions
     * @return array<string, mixed>
     */
    private function buildFilterOptions(array $devices, array $subscriptions): array
    {
        $licenseTypes = [];
        $subscriptionSkus = [];

        foreach ($devices as $device) {
            if (($device['license_type'] ?? '') !== '') {
                $licenseTypes[$device['license_type']] = true;
            }
            if (($device['subscription_sku'] ?? '') !== '') {
                $subscriptionSkus[$device['subscription_sku']] = true;
            }
        }

        foreach ($subscriptions as $subscription) {
            $licenseType = (string) ($subscription['license_type'] ?? '');
            if ($licenseType !== '') {
                $licenseTypes[$licenseType] = true;
            }
            $subscriptionSku = (string) ($subscription['subscription_sku'] ?? '');
            if ($subscriptionSku !== '') {
                $subscriptionSkus[$subscriptionSku] = true;
            }
        }

        return [
            'license_types' => array_values(array_keys($licenseTypes)),
            'subscription_skus' => array_values(array_keys($subscriptionSkus)),
            'license_tags' => $this->collectLicenseTags($subscriptions),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $subscriptions
     * @return array<int, string>
     */
    private function collectLicenseTags(array $subscriptions): array
    {
        $tags = [];

        foreach ($subscriptions as $subscription) {
            if (! is_array($subscription)) {
                continue;
            }

            foreach (GreenLakeAPIHelper::normalizeTagKeys($subscription['tags'] ?? []) as $tag) {
                $tags[$tag] = true;
            }
        }

        $tagList = array_keys($tags);
        sort($tagList);

        return $tagList;
    }

    /**
     * @param  array<int, array<string, mixed>>  $devices
     * @param  array<string, string>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function applyFilters(array $devices, array $filters): array
    {
        return array_values(array_filter($devices, function (array $device) use ($filters): bool {
            if (($filters['license_type'] ?? '') !== '' && ($device['license_type'] ?? '') !== $filters['license_type']) {
                return false;
            }

            if (($filters['subscription_sku'] ?? '') !== '' && ($device['subscription_sku'] ?? '') !== $filters['subscription_sku']) {
                return false;
            }

            if (($filters['service'] ?? '') !== '' && ! in_array($filters['service'], $device['assigned_services'] ?? [], true)) {
                return false;
            }

            if (! $this->dateInRange($device['start_date'] ?? null, $filters['start_date_from'] ?? '', $filters['start_date_to'] ?? '')) {
                return false;
            }

            if (! $this->dateInRange($device['end_date'] ?? null, $filters['end_date_from'] ?? '', $filters['end_date_to'] ?? '')) {
                return false;
            }

            return true;
        }));
    }

    private function dateInRange(?int $epochMs, string $from, string $to): bool
    {
        if ($from === '' && $to === '') {
            return true;
        }

        if ($epochMs === null) {
            return false;
        }

        if ($from !== '') {
            $fromMs = Carbon::parse($from)->startOfDay()->getTimestampMs();
            if ($epochMs < $fromMs) {
                return false;
            }
        }

        if ($to !== '') {
            $toMs = Carbon::parse($to)->endOfDay()->getTimestampMs();
            if ($epochMs > $toMs) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $devices
     * @param  array<int, array<string, mixed>>  $subscriptions
     * @return array<string, mixed>
     */
    private function buildSummary(array $devices, array $subscriptions): array
    {
        $licensedCount = count(array_filter($devices, fn (array $device): bool => (bool) ($device['licensed'] ?? false)));
        $availablePool = 0;

        foreach ($subscriptions as $subscription) {
            $availablePool += (int) ($subscription['available'] ?? 0);
        }

        return [
            'total_devices' => count($devices),
            'licensed_devices' => $licensedCount,
            'unlicensed_devices' => count($devices) - $licensedCount,
            'available_subscriptions' => $availablePool,
            'subscription_keys' => count($subscriptions),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $subscriptions
     * @param  array<int, array<string, mixed>>  $inventoryDevices
     * @return array<int, array<string, mixed>>
     */
    private function buildAvailableSubscriptions(array $subscriptions, array $inventoryDevices): array
    {
        $assignedByKey = [];

        foreach ($inventoryDevices as $device) {
            if (! is_array($device)) {
                continue;
            }

            $key = trim((string) ($device['subscription_key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $assignedByKey[$key] = ($assignedByKey[$key] ?? 0) + 1;
        }

        $available = [];

        foreach ($subscriptions as $subscription) {
            if (! GreenLakeAPIHelper::subscriptionIsAssignable((string) ($subscription['status'] ?? ''))) {
                continue;
            }

            $subscriptionKey = trim((string) ($subscription['subscription_key'] ?? ''));
            $poolAvailable = $this->resolvePoolAvailableSeats($subscription, $assignedByKey);

            if ($poolAvailable <= 0) {
                continue;
            }

            $normalized = $subscription;
            $normalized['available'] = $poolAvailable;
            $normalized['device_categories'] = $this->inferDeviceCategories((string) ($normalized['license_type'] ?? ''));
            $normalized['tags'] = GreenLakeAPIHelper::normalizeTagKeys($normalized['tags'] ?? []);
            $available[] = $normalized;
        }

        usort($available, fn (array $a, array $b): int => strcmp(
            (string) ($a['license_type'] ?? ''),
            (string) ($b['license_type'] ?? ''),
        ));

        return $available;
    }

    /**
     * @param  array<string, int>  $assignedByKey
     */
    private function resolvePoolAvailableSeats(array $subscription, array $assignedByKey): int
    {
        $subscriptionKey = trim((string) ($subscription['subscription_key'] ?? ''));
        $fromApi = (int) ($subscription['available'] ?? 0);
        $quantity = (int) ($subscription['quantity'] ?? 0);
        $assigned = $subscriptionKey !== '' ? ($assignedByKey[$subscriptionKey] ?? 0) : 0;
        $fromInventory = $quantity > 0 ? max(0, $quantity - $assigned) : 0;

        return max($fromApi, $fromInventory);
    }

    /**
     * @return array<int, string>
     */
    private function inferDeviceCategories(string $licenseType): array
    {
        $categories = [];
        $upper = strtoupper($licenseType);

        if (str_contains($upper, 'AP') || str_contains($upper, 'ACCESS POINT')) {
            $categories[] = 'ap';
        }

        if (str_contains($upper, 'SWITCH')) {
            $categories[] = 'switch';
        }

        return $categories === [] ? ['ap', 'switch'] : array_values(array_unique($categories));
    }

    /**
     * @param  array<int, string>  $enabledServices
     * @return array{
     *     devices: array<int, array<string, mixed>>,
     *     enabled_services: array<int, string>,
     *     available_subscriptions: array<int, array<string, mixed>>,
     *     subscriptions_by_key: array<string, array<string, mixed>>,
     *     subscription_summary: array<string, mixed>,
     *     filter_options: array<string, mixed>,
     *     central_error: string|null,
     *     licensing_synced_at: string|null
     * }
     */
    private function emptyPayload(
        string $error,
        array $enabledServices = [],
        ?string $licensingSyncedAt = null,
    ): array {
        return [
            'devices' => [],
            'enabled_services' => $enabledServices,
            'available_subscriptions' => [],
            'subscriptions_by_key' => [],
            'subscription_summary' => [
                'total_devices' => 0,
                'licensed_devices' => 0,
                'unlicensed_devices' => 0,
                'available_subscriptions' => 0,
                'subscription_keys' => 0,
            ],
            'filter_options' => [
                'license_types' => [],
                'subscription_skus' => [],
                'license_tags' => [],
            ],
            'central_error' => $error,
            'licensing_synced_at' => $licensingSyncedAt,
        ];
    }
}
