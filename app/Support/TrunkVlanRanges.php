<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class TrunkVlanRanges
{
    public const MIN_VLAN = 1;

    public const MAX_VLAN = 4094;

    /**
     * Parse comma/ampersand-separated VLAN ids and ranges into canonical storage: segments joined by '&',
     * each segment one VLAN or 'start-end' for contiguous ids after collapsing.
     *
     * @param  string|list<string|int>|null  $input
     */
    public static function normalizeForStorage(string|array|null $input, ?string $messageKey = null): ?string
    {
        if ($input === null) {
            return null;
        }

        if (is_array($input)) {
            $pieces = [];
            foreach ($input as $item) {
                $s = trim((string) $item);
                if ($s !== '') {
                    $pieces[] = $s;
                }
            }
            $input = implode(',', $pieces);
        }

        $input = trim((string) $input);
        if ($input === '') {
            return null;
        }

        $tokens = preg_split('/[,;&]+/', $input, -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false) {
            throw ValidationException::withMessages([
                self::key($messageKey) => 'Invalid trunk VLAN ranges format.',
            ]);
        }

        $tokens = array_values(array_filter(array_map(static fn ($t) => trim((string) $t), $tokens), fn ($t) => $t !== ''));

        $vlanIds = [];
        foreach ($tokens as $token) {
            self::expandToken($token, $vlanIds, $messageKey);
        }

        if ($vlanIds === []) {
            return null;
        }

        sort($vlanIds);
        $vlanIds = array_values(array_unique($vlanIds));

        return self::collapseToCanonical($vlanIds);
    }

    /**
     * Human-friendly display: comma-space separated segments (canonical is already collapsed).
     */
    public static function toDisplayString(?string $canonical): string
    {
        if ($canonical === null || $canonical === '') {
            return '';
        }

        return implode(', ', explode('&', $canonical));
    }

    /**
     * Expand canonical or raw VLAN range input into sorted unique VLAN ids.
     *
     * @return list<int>
     */
    public static function expandToVlanIds(?string $input, ?string $messageKey = null): array
    {
        if ($input === null || trim($input) === '') {
            return [];
        }

        $tokens = preg_split('/[,;&]+/', trim($input), -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false) {
            throw ValidationException::withMessages([
                self::key($messageKey) => 'Invalid trunk VLAN ranges format.',
            ]);
        }

        $tokens = array_values(array_filter(array_map(static fn ($t) => trim((string) $t), $tokens), fn ($t) => $t !== ''));

        $vlanIds = [];
        foreach ($tokens as $token) {
            self::expandToken($token, $vlanIds, $messageKey);
        }

        if ($vlanIds === []) {
            return [];
        }

        sort($vlanIds);

        return array_values(array_unique($vlanIds));
    }

    /**
     * @param  list<int>  $vlanIds
     */
    private static function collapseToCanonical(array $vlanIds): string
    {
        $segments = [];
        $start = $vlanIds[0];
        $prev = $start;
        $n = count($vlanIds);
        for ($i = 1; $i < $n; $i++) {
            $current = $vlanIds[$i];
            if ($current === $prev + 1) {
                $prev = $current;

                continue;
            }
            $segments[] = $start === $prev ? (string) $start : "{$start}-{$prev}";
            $start = $current;
            $prev = $current;
        }
        $segments[] = $start === $prev ? (string) $start : "{$start}-{$prev}";

        return implode('&', $segments);
    }

    /**
     * @param  list<int>  $vlanIds
     */
    private static function expandToken(string $token, array &$vlanIds, ?string $messageKey): void
    {
        if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $token, $m) === 1) {
            $low = (int) $m[1];
            $high = (int) $m[2];
            if ($low > $high) {
                throw ValidationException::withMessages([
                    self::key($messageKey) => "Invalid VLAN range \"{$token}\": start must be less than or equal to end.",
                ]);
            }
            if ($low < self::MIN_VLAN || $high > self::MAX_VLAN) {
                throw ValidationException::withMessages([
                    self::key($messageKey) => 'VLAN IDs must be between '.self::MIN_VLAN.' and '.self::MAX_VLAN.'.',
                ]);
            }
            for ($v = $low; $v <= $high; $v++) {
                $vlanIds[] = $v;
            }

            return;
        }

        if (preg_match('/^\d+$/', $token) === 1) {
            $v = (int) $token;
            if ($v < self::MIN_VLAN || $v > self::MAX_VLAN) {
                throw ValidationException::withMessages([
                    self::key($messageKey) => 'VLAN IDs must be between '.self::MIN_VLAN.' and '.self::MAX_VLAN.'.',
                ]);
            }
            $vlanIds[] = $v;

            return;
        }

        throw ValidationException::withMessages([
            self::key($messageKey) => "Invalid trunk VLAN range token \"{$token}\".",
        ]);
    }

    private static function key(?string $messageKey): string
    {
        return $messageKey ?? 'trunk_vlan_ranges';
    }
}
