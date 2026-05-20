<?php

namespace App\Services;

class ConfigurationDetailRowsBuilder
{
    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     * @return list<array{path: string, expected: mixed, actual: mixed}>
     */
    public static function fromExpectedAndActual(array $expected, array $actual): array
    {
        $rows = [];
        static::collectRows($expected, $actual, '', $rows);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     * @param  list<array{path: string, expected: mixed, actual: mixed}>  $rows
     */
    protected static function collectRows(array $expected, array $actual, string $prefix, array &$rows): void
    {
        foreach ($expected as $key => $expectedValue) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $actualValue = $actual[$key] ?? null;

            if (is_array($expectedValue) && static::isAssociativeArray($expectedValue)) {
                $actualNested = is_array($actualValue) ? $actualValue : [];
                static::collectRows($expectedValue, $actualNested, $path, $rows);

                continue;
            }

            $rows[] = [
                'path' => $path,
                'expected' => $expectedValue,
                'actual' => $actualValue,
            ];
        }
    }

    protected static function isAssociativeArray(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }
}
