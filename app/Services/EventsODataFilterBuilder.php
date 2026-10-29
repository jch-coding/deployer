<?php

namespace App\Services;

class EventsODataFilterBuilder
{
    public const FILTER_MAX_LENGTH = 512;

    public const CATEGORIES = ['Clients', 'WLAN', 'Security'];

    public const SOURCE_TYPES = [
        'Access Point',
        'Switch',
        'Gateway',
        'Wireless Client',
        'Wired Client',
        'Bridge',
    ];

    /**
     * Build an OData filter for EventListV1 (eventId, category, sourceType).
     * Uses `eq` for a single value and `in` for multiple; clauses joined with `and`.
     *
     * @param  array{
     *     eventId?: list<string>|null,
     *     category?: list<string>|null,
     *     sourceType?: list<string>|null
     * }  $criteria
     * @return array{filter: string|null, error: string|null}
     */
    public function build(array $criteria): array
    {
        $clauses = [];

        foreach (['eventId', 'category', 'sourceType'] as $field) {
            $clause = $this->clauseForField($field, $criteria[$field] ?? null);
            if ($clause !== null) {
                $clauses[] = $clause;
            }
        }

        if ($clauses === []) {
            return [
                'filter' => null,
                'error' => null,
            ];
        }

        $filter = implode(' and ', $clauses);

        if (strlen($filter) > self::FILTER_MAX_LENGTH) {
            return [
                'filter' => null,
                'error' => 'The OData filter exceeds the 512 character limit. Remove some selected values.',
            ];
        }

        return [
            'filter' => $filter,
            'error' => null,
        ];
    }

    /**
     * @param  list<string>|null  $values
     */
    private function clauseForField(string $field, ?array $values): ?string
    {
        $normalized = $this->normalizeValues($values ?? []);

        if ($normalized === []) {
            return null;
        }

        if (count($normalized) === 1) {
            return $field.' eq '.$this->quoteValue($normalized[0]);
        }

        $quoted = array_map(fn (string $value): string => $this->quoteValue($value), $normalized);

        return $field.' in ('.implode(', ', $quoted).')';
    }

    /**
     * @param  list<mixed>  $values
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

    private function quoteValue(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
