<?php

namespace App\Helper;

/**
 * Boolean coercion for API / domain code (lenient) and strict CSV cell parsing.
 */
final class BooleanHelper
{
    /**
     * Lenient boolean coercion (matches prior DeviceController::toBoolean behavior, plus yes/no).
     */
    public static function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['true', '1', 'yes'], true)) {
                return true;
            }
            if (in_array($normalized, ['false', '0', 'no', ''], true)) {
                return false;
            }
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return (bool) $value;
    }

    /**
     * CSV boolean cell: null/whitespace-only -> empty string; non-empty must match the allowlist.
     *
     * @return bool|'' Empty string means a blank cell.
     */
    public static function parseCsvBoolean(mixed $value): bool|string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value) || is_numeric($value)) {
            $s = trim((string) $value);
            if ($s === '') {
                return '';
            }
            $n = strtolower($s);
            if (in_array($n, ['true', '1', 'yes'], true)) {
                return true;
            }
            if (in_array($n, ['false', '0', 'no'], true)) {
                return false;
            }
        }

        throw new \InvalidArgumentException('Invalid boolean value');
    }
}
