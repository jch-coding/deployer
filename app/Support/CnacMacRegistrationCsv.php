<?php

namespace App\Support;

use App\Helper\BooleanHelper;

class CnacMacRegistrationCsv
{
    /**
     * Parse a Central NAC MAC registration CSV export.
     *
     * Expected columns: MAC Address, Client Name, Enabled, Static Tags
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
    public static function parse(string $csvContents): array
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

            return ['entries' => [], 'error' => 'MAC registration CSV is missing the "MAC Address" column.'];
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
                $parsed = BooleanHelper::parseCsvBoolean($row[$headerMap['enabled']] ?? '');
                $enabled = $parsed === '' ? null : $parsed;
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
            $key = match ($normalized) {
                'mac address', 'mac_address', 'macaddress' => 'mac_address',
                'client name', 'client_name', 'clientname' => 'client_name',
                'enabled' => 'enabled',
                'static tags', 'static_tags', 'statictags' => 'static_tags',
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

        return array_values(array_filter(
            array_map(fn (string $tag): string => trim($tag), explode(',', $trimmed)),
            fn (string $tag): bool => $tag !== '',
        ));
    }
}
