<?php

namespace App\Services;

use App\Helper\GreenLakeAPIHelper;
use App\LicenseType;

class LicensingPoolResolver
{
    /**
     * @param  array<int, array<string, mixed>>  $subscriptions
     * @return array<int, array<string, mixed>>
     */
    public function matchingSubscriptions(array $subscriptions, string $tag, LicenseType $licenseType): array
    {
        $tag = trim($tag);
        if ($tag === '') {
            return [];
        }

        $matches = [];
        foreach ($subscriptions as $subscription) {
            if (! is_array($subscription)) {
                continue;
            }

            if (! GreenLakeAPIHelper::subscriptionIsAssignable((string) ($subscription['status'] ?? ''))) {
                continue;
            }

            $tierDescription = (string) ($subscription['license_type'] ?? '');
            if (! $licenseType->matchesTierDescription($tierDescription)) {
                continue;
            }

            $tagKeys = GreenLakeAPIHelper::normalizeTagKeys($subscription['tags'] ?? []);
            if (! in_array($tag, $tagKeys, true)) {
                continue;
            }

            $available = (int) ($subscription['available'] ?? 0);
            if ($available <= 0) {
                continue;
            }

            $matches[] = $subscription;
        }

        usort($matches, fn (array $a, array $b): int => ((int) ($b['available'] ?? 0)) <=> ((int) ($a['available'] ?? 0)));

        return $matches;
    }

    /**
     * @param  array<int, array<string, mixed>>  $matching
     */
    public function poolAvailableSeats(array $matching): int
    {
        $total = 0;
        foreach ($matching as $subscription) {
            $total += (int) ($subscription['available'] ?? 0);
        }

        return $total;
    }

    /**
     * @param  array<int, array<string, mixed>>  $subscriptions
     * @return array{error: string}|null
     */
    public function validatePoolCapacity(
        string $tag,
        LicenseType $licenseType,
        int $deviceCount,
        array $subscriptions,
    ): ?array {
        $matching = $this->matchingSubscriptions($subscriptions, $tag, $licenseType);
        if ($matching === []) {
            return [
                'error' => "No available licenses found for tag \"{$tag}\" with type {$licenseType->value}. Renew licensing or choose a different tag/type.",
            ];
        }

        $available = $this->poolAvailableSeats($matching);
        if ($deviceCount > $available) {
            return [
                'error' => "Only {$available} {$licenseType->value} seat(s) available for tag \"{$tag}\"; you selected {$deviceCount} device(s). Renew licensing or choose a different tag/type.",
            ];
        }

        return null;
    }

    /**
     * @param  array<int, int>  $deviceIds
     * @param  array<int, array<string, mixed>>  $subscriptions
     * @return array<int, string> deviceId => greenlake_subscription_id
     */
    public function allocateDevices(array $deviceIds, string $tag, LicenseType $licenseType, array $subscriptions): array
    {
        $matching = $this->matchingSubscriptions($subscriptions, $tag, $licenseType);
        if ($matching === []) {
            return [];
        }

        $remainingByKey = [];
        foreach ($matching as $subscription) {
            $key = trim((string) ($subscription['subscription_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $remainingByKey[$key] = [
                'available' => (int) ($subscription['available'] ?? 0),
                'greenlake_subscription_id' => trim((string) ($subscription['greenlake_subscription_id'] ?? '')),
            ];
        }

        $allocations = [];
        foreach ($deviceIds as $deviceId) {
            $assigned = false;
            foreach ($remainingByKey as $subscriptionKey => $pool) {
                if ($pool['available'] <= 0 || $pool['greenlake_subscription_id'] === '') {
                    continue;
                }

                $allocations[$deviceId] = $pool['greenlake_subscription_id'];
                $remainingByKey[$subscriptionKey]['available']--;
                $assigned = true;
                break;
            }

            if (! $assigned) {
                break;
            }
        }

        return $allocations;
    }
}
