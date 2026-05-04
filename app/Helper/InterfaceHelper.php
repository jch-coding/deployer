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
}
