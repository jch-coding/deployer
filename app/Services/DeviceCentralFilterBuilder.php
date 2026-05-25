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

            $clauses[] = $field.' eq '.$this->formatValue($value);
        }

        if ($clauses === []) {
            return null;
        }

        return implode(' and ', $clauses);
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

    private function formatValue(string $value): string
    {
        if ($this->needsQuoting($value)) {
            return "'".str_replace("'", "''", $value)."'";
        }

        return $value;
    }

    private function needsQuoting(string $value): bool
    {
        if (preg_match('/\s/', $value) === 1) {
            return true;
        }

        return preg_match('/[^A-Za-z0-9._-]/', $value) === 1;
    }
}
