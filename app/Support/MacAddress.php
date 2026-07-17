<?php

namespace App\Support;

class MacAddress
{
    /**
     * Normalize a MAC address to AA:BB:CC:DD:EE:FF (lowercase hex).
     * Accepts colon, dash, or bare hex input.
     */
    public static function normalize(string $value): ?string
    {
        $trimmed = strtolower(trim($value));
        if ($trimmed === '') {
            return null;
        }

        $hex = preg_replace('/[^0-9a-f]/', '', $trimmed);
        if (! is_string($hex) || strlen($hex) !== 12) {
            return null;
        }

        $parts = str_split($hex, 2);

        return implode(':', $parts);
    }

    public static function isValid(string $value): bool
    {
        return self::normalize($value) !== null;
    }
}
