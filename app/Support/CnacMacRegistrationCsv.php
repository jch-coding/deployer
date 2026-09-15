<?php

namespace App\Support;

use App\Helper\BooleanHelper;

class CnacMacRegistrationCsv
{
    /**
     * Parse a Central NAC MAC registration export payload.
     *
     * Supports:
     * - CSV with import headers: MAC Address, Client Name, Enabled, Static Tags
     * - CSV with API-style headers: macAddress, displayName, enable, staticTags
     * - JSON list responses (export endpoint is documented as application/json binary):
     *   { "items": [ { "macAddress", "displayName", "enable", "staticTags" } ] }
     *
     * @return array{
     *     entries: list<array{
     *         mac_address: string,
     *         client_name: string,
     *         enabled: bool|null,
     *         static_tags: list<string>
     *     }>,
     *     error: string|null
     * }
     */
    public static function parse(string $payload): array
    {
        $payload = preg_replace('/^\xEF\xBB\xBF/', '', $payload) ?? $payload;
        $payload = trim($payload);

        if ($payload === '') {
            return ['entries' => [], 'error' => null];
        }

        $jsonEntries = self::tryParseJsonPayload($payload);
        if ($jsonEntries !== null) {
            return $jsonEntries;
        }

        return self::parseCsv($payload);
    }

    /**
     * @return array{
     *     entries: list<array{
     *         mac_address: string,
     *         client_name: string,
     *         enabled: bool|null,
     *         static_tags: list<string>
     *     }>,
     *     error: string|null
     * }|null
     */
    private static function tryParseJsonPayload(string $payload): ?array
    {
        if (
            ! (
                (str_starts_with($payload, '{') && str_ends_with($payload, '}'))
                || (str_starts_with($payload, '[') && str_ends_with($payload, ']'))
                || (str_starts_with($payload, '"') && str_ends_with($payload, '"'))
            )
        ) {
            return null;
        }

        $decoded = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        if (is_string($decoded)) {
            return self::parse($decoded);
        }

        if (! is_array($decoded)) {
            return null;
        }

        // JSON-encoded CSV wrapped in a field.
        foreach (['csv', 'content', 'file', 'body'] as $key) {
            if (isset($decoded[$key]) && is_string($decoded[$key]) && $decoded[$key] !== '') {
                return self::parse($decoded[$key]);
            }
        }

        if (isset($decoded['data']) && is_string($decoded['data']) && $decoded['data'] !== '') {
            $value = $decoded['data'];
            if (preg_match('/^[A-Za-z0-9+\/=]+$/', $value) === 1 && strlen($value) > 64) {
                $binary = base64_decode($value, true);
                if (is_string($binary) && $binary !== '') {
                    return self::parse($binary);
                }
            }

            return self::parse($value);
        }

        $items = null;
        if (array_is_list($decoded)) {
            $items = $decoded;
        } elseif (isset($decoded['items']) && is_array($decoded['items'])) {
            $items = $decoded['items'];
        } elseif (isset($decoded['macs']) && is_array($decoded['macs'])) {
            $items = $decoded['macs'];
        }

        if ($items === null) {
            if (isset($decoded['job_id']) || isset($decoded['jobid'])) {
                return [
                    'entries' => [],
                    'error' => 'MAC registration export returned an async job id instead of the registration table.',
                ];
            }

            // Recognized JSON object but not a MAC list / CSV wrapper — not CSV either.
            $keys = implode(', ', array_keys($decoded));

            return [
                'entries' => [],
                'error' => 'MAC registration export returned JSON without a recognizable items list or CSV body.'
                    .($keys !== '' ? " Keys: {$keys}" : ''),
            ];
        }

        return [
            'entries' => self::entriesFromJsonItems($items),
            'error' => null,
        ];
    }

    /**
     * @param  list<mixed>  $items
     * @return list<array{
     *     mac_address: string,
     *     client_name: string,
     *     enabled: bool|null,
     *     static_tags: list<string>
     * }>
     */
    public static function entriesFromJsonItems(array $items): array
    {
        $entries = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $macRaw = (string) (
                $item['macAddress']
                ?? $item['mac_address']
                ?? $item['mac']
                ?? $item['MAC Address']
                ?? ''
            );
            $mac = MacAddress::normalize($macRaw);
            if ($mac === null) {
                continue;
            }

            $clientName = trim((string) (
                $item['displayName']
                ?? $item['clientName']
                ?? $item['client_name']
                ?? $item['Client Name']
                ?? ''
            ));

            $enabledRaw = $item['enable'] ?? $item['enabled'] ?? $item['Enabled'] ?? null;
            $enabled = null;
            if ($enabledRaw !== null && $enabledRaw !== '') {
                try {
                    $parsed = BooleanHelper::parseCsvBoolean($enabledRaw);
                    $enabled = $parsed === '' ? null : $parsed;
                } catch (\InvalidArgumentException) {
                    $enabled = is_bool($enabledRaw) ? $enabledRaw : null;
                }
            }

            $tagsRaw = $item['staticTags'] ?? $item['static_tags'] ?? $item['Static Tags'] ?? [];
            if (is_array($tagsRaw)) {
                $staticTags = array_values(array_filter(
                    array_map(static fn ($tag): string => trim((string) $tag), $tagsRaw),
                    static fn (string $tag): bool => $tag !== '',
                ));
            } else {
                $staticTags = self::parseStaticTags((string) $tagsRaw);
            }

            $entries[] = [
                'mac_address' => $mac,
                'client_name' => $clientName,
                'enabled' => $enabled,
                'static_tags' => $staticTags,
            ];
        }

        return $entries;
    }

    /**
     * @return array{
     *     entries: list<array{
     *         mac_address: string,
     *         client_name: string,
     *         enabled: bool|null,
     *         static_tags: list<string>
     *     }>,
     *     error: string|null
     * }
     */
    private static function parseCsv(string $csvContents): array
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return ['entries' => [], 'error' => 'Unable to open temporary stream for MAC CSV.'];
        }

        fwrite($handle, $csvContents);
        rewind($handle);

        $header = fgetcsv($handle, length: 0, separator: ',', enclosure: '"', escape: '\\');
        if ($header === false || $header === [null] || $header === []) {
            fclose($handle);

            return ['entries' => [], 'error' => 'MAC registration CSV is empty or missing a header row.'];
        }

        $headerMap = self::mapHeaders($header);
        if (! array_key_exists('mac_address', $headerMap)) {
            fclose($handle);
            $preview = implode(', ', array_map(
                static fn ($label): string => trim((string) $label),
                $header,
            ));

            return [
                'entries' => [],
                'error' => 'MAC registration CSV is missing the "MAC Address" column.'
                    .($preview !== '' ? " Found headers: {$preview}" : ''),
            ];
        }

        $entries = [];
        while (($row = fgetcsv($handle, length: 0, separator: ',', enclosure: '"', escape: '\\')) !== false) {
            if ($row === [null] || $row === []) {
                continue;
            }

            $macRaw = (string) ($row[$headerMap['mac_address']] ?? '');
            $mac = MacAddress::normalize($macRaw);
            if ($mac === null) {
                continue;
            }

            $clientName = '';
            if (array_key_exists('client_name', $headerMap)) {
                $clientName = trim((string) ($row[$headerMap['client_name']] ?? ''));
            }

            $enabled = null;
            if (array_key_exists('enabled', $headerMap)) {
                try {
                    $parsed = BooleanHelper::parseCsvBoolean($row[$headerMap['enabled']] ?? '');
                    $enabled = $parsed === '' ? null : $parsed;
                } catch (\InvalidArgumentException) {
                    $enabled = null;
                }
            }

            $staticTags = [];
            if (array_key_exists('static_tags', $headerMap)) {
                $staticTags = self::parseStaticTags((string) ($row[$headerMap['static_tags']] ?? ''));
            }

            $entries[] = [
                'mac_address' => $mac,
                'client_name' => $clientName,
                'enabled' => $enabled,
                'static_tags' => $staticTags,
            ];
        }

        fclose($handle);

        return ['entries' => $entries, 'error' => null];
    }

    /**
     * @param  list<string|null>  $header
     * @return array<string, int>
     */
    private static function mapHeaders(array $header): array
    {
        $map = [];
        foreach ($header as $index => $label) {
            $normalized = strtolower(trim((string) $label));
            $normalized = preg_replace('/^\xEF\xBB\xBF/', '', $normalized) ?? $normalized;
            $normalized = trim($normalized, "\"' \t\n\r\0\x0B");
            $compact = preg_replace('/[\s_\-]+/', '', $normalized) ?? $normalized;

            $key = match (true) {
                in_array($compact, ['macaddress', 'mac', 'macaddr'], true) => 'mac_address',
                in_array($compact, ['clientname', 'displayname', 'name'], true) => 'client_name',
                in_array($compact, ['enabled', 'enable'], true) => 'enabled',
                in_array($compact, ['statictags', 'tags'], true) => 'static_tags',
                default => null,
            };
            if ($key !== null) {
                $map[$key] = $index;
            }
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    public static function parseStaticTags(string $value): array
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }

        if (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']')) {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                return array_values(array_filter(
                    array_map(
                        static fn ($tag): string => trim((string) $tag),
                        $decoded,
                    ),
                    static fn (string $tag): bool => $tag !== '',
                ));
            }
        }

        return array_values(array_filter(
            array_map(fn (string $tag): string => trim($tag), explode(',', $trimmed)),
            fn (string $tag): bool => $tag !== '',
        ));
    }
}
