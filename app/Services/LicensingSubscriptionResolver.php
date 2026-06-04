<?php

namespace App\Services;

class LicensingSubscriptionResolver
{
    /**
     * @param  array<int, string>  $enabledServices
     * @param  array<string, array<string, mixed>>  $subscriptionsByKey
     * @param  array<int, array<string, mixed>>  $inventoryDevices
     * @return array{service_name: string}|array{error: string}
     */
    public function resolveServiceName(
        string $subscriptionKey,
        array $enabledServices,
        array $subscriptionsByKey,
        array $inventoryDevices = [],
    ): array {
        $subscriptionKey = trim($subscriptionKey);
        if ($subscriptionKey === '') {
            return ['error' => 'Subscription key is required.'];
        }

        $subscription = $subscriptionsByKey[$subscriptionKey] ?? null;
        if ($subscription === null) {
            return ['error' => 'Selected subscription was not found.'];
        }

        $fromInventory = $this->inferFromInventoryDevices($subscriptionKey, $inventoryDevices, $enabledServices);
        if ($fromInventory !== null) {
            return ['service_name' => $fromInventory];
        }

        $licenseType = (string) ($subscription['license_type'] ?? '');
        $candidates = $this->matchEnabledServicesByLicenseType($licenseType, $enabledServices);
        if (count($candidates) === 1) {
            return ['service_name' => $candidates[0]];
        }

        if (count($candidates) > 1) {
            return ['error' => 'Multiple services match this license type. Assign a device using this subscription in Central first, or contact support.'];
        }

        return ['error' => 'Could not determine which Central service to use for this license.'];
    }

    /**
     * @param  array<string, array<string, mixed>>  $subscriptionsByKey
     * @return array{error: string}|null
     */
    public function validateCapacity(string $subscriptionKey, int $deviceCount, array $subscriptionsByKey): ?array
    {
        $subscription = $subscriptionsByKey[$subscriptionKey] ?? null;
        if ($subscription === null) {
            return ['error' => 'Selected subscription was not found.'];
        }

        $available = (int) ($subscription['available'] ?? 0);
        if ($deviceCount > $available) {
            return ['error' => "Only {$available} seat(s) available on this license; you selected {$deviceCount} device(s)."];
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $inventoryDevices
     * @param  array<int, string>  $enabledServices
     */
    private function inferFromInventoryDevices(string $subscriptionKey, array $inventoryDevices, array $enabledServices): ?string
    {
        foreach ($inventoryDevices as $device) {
            if (! is_array($device)) {
                continue;
            }

            $deviceKey = (string) ($device['subscription_key'] ?? $device['subscriptionKey'] ?? '');
            if ($deviceKey !== $subscriptionKey) {
                continue;
            }

            $services = $device['services'] ?? [];
            if (! is_array($services)) {
                continue;
            }

            foreach ($services as $service) {
                if (is_string($service) && $service !== '' && in_array($service, $enabledServices, true)) {
                    return $service;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $enabledServices
     * @return array<int, string>
     */
    private function matchEnabledServicesByLicenseType(string $licenseType, array $enabledServices): array
    {
        $slug = $this->licenseTypeToSlug($licenseType);
        if ($slug === '') {
            return [];
        }

        $matches = [];
        foreach ($enabledServices as $service) {
            if ($this->serviceMatchesSlug($service, $slug)) {
                $matches[] = $service;
            }
        }

        return array_values(array_unique($matches));
    }

    private function licenseTypeToSlug(string $licenseType): string
    {
        $normalized = strtolower(trim($licenseType));
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? '';
        $normalized = trim($normalized, '_');

        return $normalized;
    }

    private function serviceMatchesSlug(string $service, string $licenseSlug): bool
    {
        $serviceSlug = strtolower(trim($service));

        if ($serviceSlug === $licenseSlug) {
            return true;
        }

        if (str_starts_with($serviceSlug, $licenseSlug.'_')) {
            return true;
        }

        if (str_starts_with($licenseSlug, $serviceSlug.'_')) {
            return true;
        }

        $licenseCore = $this->stripCommonPrefixes($licenseSlug);
        $serviceCore = $this->stripCommonPrefixes($serviceSlug);

        return $licenseCore !== '' && $licenseCore === $serviceCore;
    }

    private function stripCommonPrefixes(string $slug): string
    {
        foreach (['advanced_', 'foundation_', 'basic_'] as $prefix) {
            if (str_starts_with($slug, $prefix)) {
                return substr($slug, strlen($prefix));
            }
        }

        return $slug;
    }
}
