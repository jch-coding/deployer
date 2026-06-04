<?php

namespace App\Services;

use App\Helper\CentralAPIHelper;
use App\Models\Client;
use App\Models\Device;
use Carbon\Carbon;

class LicensingInventoryService
{
    /**
     * @param  array<string, string>  $filters
     * @return array{
     *     devices: array<int, array<string, mixed>>,
     *     enabled_services: array<int, string>,
     *     subscription_summary: array<string, mixed>,
     *     filter_options: array<string, mixed>,
     *     central_error: string|null
     * }
     */
    public function build(Client $client, CentralAPIHelper $helper, array $filters = []): array
    {
        $enabledResult = $helper->classic_parse_enabled_services($helper->classic_get_enabled_services());
        if (isset($enabledResult['error'])) {
            return $this->emptyPayload($enabledResult['error']);
        }

        $subscriptionsResult = $helper->classic_parse_subscriptions($helper->classic_get_subscriptions());
        if (isset($subscriptionsResult['error'])) {
            return $this->emptyPayload($subscriptionsResult['error'], $enabledResult['services']);
        }

        $inventoryResult = $helper->classic_collect_device_inventory();
        if (array_key_exists('error', $inventoryResult)) {
            return $this->emptyPayload((string) $inventoryResult['error'], $enabledResult['services']);
        }

        $subscriptionsByKey = $this->indexSubscriptions($subscriptionsResult['subscriptions']);
        $deviceSkusBySerial = Device::query()
            ->where('client_id', $client->id)
            ->whereNotNull('sku')
            ->pluck('sku', 'serial')
            ->map(fn ($sku) => $sku instanceof \BackedEnum ? $sku->name : (string) $sku)
            ->all();
        $deviceIdsBySerial = Device::query()
            ->where('client_id', $client->id)
            ->pluck('id', 'serial')
            ->map(fn ($id) => (int) $id)
            ->all();

        $devices = array_map(
            fn (array $item): array => $this->enrichDeviceRow($item, $subscriptionsByKey, $deviceSkusBySerial, $deviceIdsBySerial),
            $inventoryResult,
        );

        $filterOptions = $this->buildFilterOptions($devices, $subscriptionsResult['subscriptions']);
        $devices = $this->applyFilters($devices, $filters);
        $summary = $this->buildSummary($devices, $subscriptionsResult['subscriptions']);

        return [
            'devices' => array_values($devices),
            'enabled_services' => $enabledResult['services'],
            'subscription_summary' => $summary,
            'filter_options' => $filterOptions,
            'central_error' => null,
        ];
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

            $indexed[$key] = $this->normalizeSubscription($subscription);
        }

        return $indexed;
    }

    /**
     * @param  array<string, mixed>  $subscription
     * @return array<string, mixed>
     */
    private function normalizeSubscription(array $subscription): array
    {
        return [
            'subscription_key' => (string) ($subscription['subscription_key'] ?? ''),
            'subscription_sku' => (string) ($subscription['sku'] ?? ''),
            'license_type' => (string) ($subscription['license_type'] ?? ''),
            'start_date' => $this->normalizeEpoch($subscription['start_date'] ?? null),
            'end_date' => $this->normalizeEpoch($subscription['end_date'] ?? null),
            'status' => (string) ($subscription['status'] ?? ''),
            'subscription_type' => (string) ($subscription['subscription_type'] ?? ''),
            'available' => (int) ($subscription['available'] ?? 0),
            'acpapp_name' => (string) ($subscription['acpapp_name'] ?? ''),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $subscriptionsByKey
     * @param  array<string, string>  $deviceSkusBySerial
     * @param  array<string, int>  $deviceIdsBySerial
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function enrichDeviceRow(array $item, array $subscriptionsByKey, array $deviceSkusBySerial, array $deviceIdsBySerial): array
    {
        $serial = (string) ($item['serial'] ?? '');
        $subscriptionKey = (string) ($item['subscription_key'] ?? $item['subscriptionKey'] ?? '');
        $services = $item['services'] ?? [];
        if (! is_array($services)) {
            $services = [];
        }
        $services = array_values(array_filter($services, fn ($service) => is_string($service) && $service !== ''));

        $subscription = $subscriptionsByKey[$subscriptionKey] ?? null;

        return [
            'serial' => $serial,
            'model' => (string) ($item['model'] ?? ''),
            'mac' => (string) ($item['mac'] ?? $item['macaddr'] ?? ''),
            'device_type' => (string) ($item['device_type'] ?? ''),
            'name' => (string) ($item['name'] ?? $serial),
            'licensed' => (bool) ($item['licensed'] ?? ($services !== [])),
            'assigned_services' => $services,
            'subscription_key' => $subscriptionKey,
            'subscription_sku' => (string) ($subscription['subscription_sku'] ?? ''),
            'license_type' => (string) ($subscription['license_type'] ?? ''),
            'start_date' => $subscription['start_date'] ?? null,
            'end_date' => $subscription['end_date'] ?? null,
            'subscription_status' => (string) ($subscription['status'] ?? ''),
            'subscription_type' => (string) ($subscription['subscription_type'] ?? ''),
            'acpapp_name' => (string) ($subscription['acpapp_name'] ?? ''),
            'device_sku' => (string) ($deviceSkusBySerial[$serial] ?? ''),
            'deployer_device_id' => $deviceIdsBySerial[$serial] ?? null,
        ];
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
            if (! is_array($subscription)) {
                continue;
            }
            $licenseType = (string) ($subscription['license_type'] ?? '');
            if ($licenseType !== '') {
                $licenseTypes[$licenseType] = true;
            }
            $subscriptionSku = (string) ($subscription['sku'] ?? '');
            if ($subscriptionSku !== '') {
                $subscriptionSkus[$subscriptionSku] = true;
            }
        }

        return [
            'license_types' => array_values(array_keys($licenseTypes)),
            'subscription_skus' => array_values(array_keys($subscriptionSkus)),
        ];
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
            if (! is_array($subscription)) {
                continue;
            }
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

    /**
     * @param  array<int, string>  $enabledServices
     * @return array{
     *     devices: array<int, array<string, mixed>>,
     *     enabled_services: array<int, string>,
     *     subscription_summary: array<string, mixed>,
     *     filter_options: array<string, mixed>,
     *     central_error: string|null
     * }
     */
    private function emptyPayload(string $error, array $enabledServices = []): array
    {
        return [
            'devices' => [],
            'enabled_services' => $enabledServices,
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
            ],
            'central_error' => $error,
        ];
    }
}
