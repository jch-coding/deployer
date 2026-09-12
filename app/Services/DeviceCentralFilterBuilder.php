<?php

namespace App\Services;

class DeviceCentralFilterBuilder
{
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
        $normalized = [];

        foreach ($values as $value) {
            $trimmed = trim((string) $value);
            if ($trimmed === '' || in_array($trimmed, $normalized, true)) {
                continue;
            }

            $normalized[] = $trimmed;
        }

        if ($normalized === []) {
            return null;
        }

        if (! in_array($field, $this->fieldOrder(), true)) {
            return null;
        }

        $quoted = array_map(fn (string $value): string => $this->quoteValue($value), $normalized);

        return $field.' in ('.implode(', ', $quoted).')';
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

    private function quoteValue(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
