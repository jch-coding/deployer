<?php

namespace App\Support;

class CsvImportMergeHelper
{
    /**
     * Whether an incoming CSV value is considered empty (missing, null, or blank string).
     */
    public static function isCsvValueEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value) && trim($value) === '') {
            return true;
        }

        return false;
    }

    /**
     * Apply null-safe CSV merge rules for an existing database value:
     * - allow null -> non-null
     * - allow non-null -> non-null when changed
     * - block non-null -> null
     * - no-op when equal
     */
    public static function mergeCsvValue(mixed $existing, mixed $incoming): mixed
    {
        if (self::isCsvValueEmpty($incoming)) {
            return $existing;
        }

        return $incoming;
    }

    /**
     * Merge a set of optional CSV fields onto an existing attribute bag.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public static function mergeOptionalFields(array $existing, array $incoming, array $fields): array
    {
        $merged = $incoming;

        foreach ($fields as $field) {
            $merged[$field] = self::mergeCsvValue(
                $existing[$field] ?? null,
                $incoming[$field] ?? null
            );
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function rowHasPopulatedValue(array $row, string $key): bool
    {
        if (! array_key_exists($key, $row)) {
            return false;
        }

        return ! self::isCsvValueEmpty($row[$key]);
    }
}
