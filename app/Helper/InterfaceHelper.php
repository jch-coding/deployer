<?php

namespace App\Helper;

/**
 * Normalizes switch interface identifiers for storage (e.g. CSV import).
 * Numeric components must not retain leading zeros; they are stripped.
 */
final class InterfaceHelper
{
    /**
     * Normalize an interface value: strip leading zeros from slash-separated numeric segments.
     * Supports lag-style single numbers, stack/module/port paths, and compound ranges
     * (hyphen ranges and ampersand-separated segments) used by expandInterfaceRange.
     */
    public static function normalizeInterfaceString(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return $value;
        }

        $chunks = explode('&', $value);
        $normalizedChunks = [];
        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            if (str_contains($chunk, '-')) {
                [$left, $right] = explode('-', $chunk, 2);
                $normalizedChunks[] = self::normalizePathToken($left).'-'.self::normalizePathToken($right);
            } else {
                $normalizedChunks[] = self::normalizePathToken($chunk);
            }
        }

        return implode('&', $normalizedChunks);
    }

    /**
     * @return list<string>
     */
    public static function expandInterfaceRange(string $range): array
    {
        $range = self::normalizeInterfaceString($range);
        $interfacePairs = array_map(fn ($pair) => explode('-', $pair), explode('&', $range));
        $expandedRanges = [];
        foreach ($interfacePairs as $pair) {
            if (count($pair) === 2) {
                $interfaceParts = explode('/', $pair[0]);
                $prefix = $interfaceParts[0].'/'.$interfaceParts[1].'/';
                $start = (int) $interfaceParts[2];
                $end = (int) explode('/', $pair[1])[2];
                $expandedRanges = array_merge($expandedRanges, array_map(fn ($i) => $prefix.$i, range($start, $end)));
            } else {
                $expandedRanges[] = $pair[0];
            }
        }

        return $expandedRanges;
    }

    /**
     * Normalize LAG port-list values for comparison (order-insensitive member sets).
     *
     * @return list<string>
     */
    public static function normalizePortListMembers(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $segments = is_array($value)
            ? array_map(static fn ($item) => (string) $item, $value)
            : [(string) $value];

        $members = [];
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            $members = array_merge($members, self::expandInterfaceRange($segment));
        }

        $members = array_values(array_unique($members));
        sort($members);

        return $members;
    }

    private static function normalizePathToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return $token;
        }

        $parts = explode('/', $token);
        $normalized = array_map(function (string $part): string {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                return (string) (int) $part;
            }

            return $part;
        }, $parts);

        return implode('/', $normalized);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function isRoutedEthernetRow(array $row): bool
    {
        $ipAddress = $row['ip_address'] ?? null;
        if ($ipAddress === null || trim((string) $ipAddress) === '') {
            return false;
        }

        $iface = isset($row['interface']) ? (string) $row['interface'] : '';

        return str_contains($iface, '/');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function isLagRow(array $row): bool
    {
        $iface = isset($row['interface']) ? (string) $row['interface'] : '';
        if ($iface === '' || str_contains($iface, '/')) {
            return false;
        }

        $lacpPortId = $row['lacp_port_id'] ?? null;
        if ($lacpPortId !== null && trim((string) $lacpPortId) !== '') {
            return true;
        }

        $portList = $row['port_list'] ?? null;

        return $portList !== null && trim((string) $portList) !== '';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function isRoutedLagRow(array $row): bool
    {
        $ipAddress = $row['ip_address'] ?? null;
        if ($ipAddress === null || trim((string) $ipAddress) === '') {
            return false;
        }

        return self::isLagRow($row);
    }
}
