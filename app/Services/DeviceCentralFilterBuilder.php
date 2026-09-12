<?php

namespace App\Services;

class DeviceCentralFilterBuilder
{
    /**
     * Central device filter query strings are capped at this length.
     */
    public const FILTER_MAX_LENGTH = 256;

    /**
     * @param  array<string, string|null>  $criteria  Keys: siteId, siteName, serialNumber, deviceName, deviceType, status, model, firmwareVersion, deployment
     */
    public function build(array $criteria): ?string
    {
        $clauses = [];

        foreach ($this->fieldOrder() as $field) {
            $value = trim((string) ($criteria[$field] ?? ''));
            if ($value === '') {
                continue;
            }

            $clauses[] = $field.' eq '.$this->quoteValue($value);
        }

        if ($clauses === []) {
            return null;
        }

        return implode(' and ', $clauses);
    }

    /**
     * Build an OData `in` filter for a single field.
     *
     * @param  list<string>  $values
     */
    public function buildIn(string $field, array $values): ?string
    {
        $normalized = $this->normalizeValues($values);

        if ($normalized === [] || ! in_array($field, $this->fieldOrder(), true)) {
            return null;
        }

        return $this->formatInClause($field, $normalized);
    }

    /**
     * Build one or more `in` filters so each stays within Central's filter max length.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    public function buildInChunks(string $field, array $values, int $maxLength = self::FILTER_MAX_LENGTH): array
    {
        $normalized = $this->normalizeValues($values);

        if ($normalized === [] || ! in_array($field, $this->fieldOrder(), true)) {
            return [];
        }

        $chunks = [];
        $current = [];

        foreach ($normalized as $value) {
            $candidate = [...$current, $value];
            $filter = $this->formatInClause($field, $candidate);

            if (strlen($filter) <= $maxLength) {
                $current = $candidate;

                continue;
            }

            if ($current !== []) {
                $chunks[] = $this->formatInClause($field, $current);
                $current = [];
            }

            $single = $this->formatInClause($field, [$value]);
            if (strlen($single) <= $maxLength) {
                $current = [$value];
            }
            // Drop values that cannot fit even alone; caller still has serial fallbacks.
        }

        if ($current !== []) {
            $chunks[] = $this->formatInClause($field, $current);
        }

        return $chunks;
    }

    /**
     * @return list<string>
     */
    private function fieldOrder(): array
    {
        return [
            'siteId',
            'siteName',
            'serialNumber',
            'deviceName',
            'deviceType',
            'status',
            'model',
            'firmwareVersion',
            'deployment',
        ];
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function normalizeValues(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            $trimmed = trim((string) $value);
            if ($trimmed === '' || in_array($trimmed, $normalized, true)) {
                continue;
            }

            $normalized[] = $trimmed;
        }

        return $normalized;
    }

    /**
     * @param  list<string>  $values
     */
    private function formatInClause(string $field, array $values): string
    {
        $quoted = array_map(fn (string $value): string => $this->quoteValue($value), $values);

        return $field.' in ('.implode(', ', $quoted).')';
    }

    private function quoteValue(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
