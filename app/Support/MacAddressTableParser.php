<?php

namespace App\Support;

class MacAddressTableParser
{
    /**
     * @return array{
     *     rows: list<array{mac: string, vlan: string, type: string, port: string}>,
     *     ageTime: string|null,
     *     count: int|null,
     *     raw: string
     * }
     */
    public static function parse(string $output): array
    {
        $raw = $output;
        $lines = preg_split("/\r?\n/", $output) ?: [];

        $ageTime = null;
        if (preg_match('/MAC age-time\s*:\s*(.+)/i', $output, $ageMatch) === 1) {
            $ageTime = trim((string) $ageMatch[1]);
            if ($ageTime === '') {
                $ageTime = null;
            }
        }

        $count = null;
        if (preg_match('/Number of MAC addresses\s*:\s*(\d+)/i', $output, $countMatch) === 1) {
            $count = (int) $countMatch[1];
        }

        $headerLine = null;
        foreach ($lines as $line) {
            if (
                str_contains($line, 'MAC Address')
                && str_contains($line, 'VLAN')
                && str_contains($line, 'Type')
                && str_contains($line, 'Port')
            ) {
                $headerLine = $line;
                break;
            }
        }

        if ($headerLine === null) {
            return ['rows' => [], 'ageTime' => $ageTime, 'count' => $count, 'raw' => $raw];
        }

        $macStart = strpos($headerLine, 'MAC Address');
        $vlanStart = strpos($headerLine, 'VLAN');
        $typeStart = strpos($headerLine, 'Type');
        $portStart = strpos($headerLine, 'Port');

        if ($macStart === false || $vlanStart === false || $typeStart === false || $portStart === false) {
            return ['rows' => [], 'ageTime' => $ageTime, 'count' => $count, 'raw' => $raw];
        }

        $headerIndex = array_search($headerLine, $lines, true);
        if ($headerIndex === false) {
            return ['rows' => [], 'ageTime' => $ageTime, 'count' => $count, 'raw' => $raw];
        }

        $rows = [];
        for ($index = $headerIndex + 1; $index < count($lines); $index++) {
            $line = (string) ($lines[$index] ?? '');
            $row = self::parseDataRow($line, [
                'mac' => $macStart,
                'vlan' => $vlanStart,
                'type' => $typeStart,
                'port' => $portStart,
            ]);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return ['rows' => $rows, 'ageTime' => $ageTime, 'count' => $count, 'raw' => $raw];
    }

    /**
     * @param  array{mac: int, vlan: int, type: int, port: int}  $columnStarts
     * @return array{mac: string, vlan: string, type: string, port: string}|null
     */
    private static function parseDataRow(string $line, array $columnStarts): ?array
    {
        $trimmed = trim($line);
        if ($trimmed === '' || preg_match('/^[-=\s]+$/', $trimmed) === 1) {
            return null;
        }

        $mac = self::sliceColumn($line, $columnStarts['mac'], $columnStarts['vlan']);
        $vlan = self::sliceColumn($line, $columnStarts['vlan'], $columnStarts['type']);
        $type = self::sliceColumn($line, $columnStarts['type'], $columnStarts['port']);
        $port = self::sliceColumn($line, $columnStarts['port'], null);

        if ($mac === '' || $vlan === '' || $type === '' || $port === '') {
            return null;
        }

        return [
            'mac' => $mac,
            'vlan' => $vlan,
            'type' => $type,
            'port' => $port,
        ];
    }

    private static function sliceColumn(string $line, int $start, ?int $end): string
    {
        $slice = $end === null ? substr($line, $start) : substr($line, $start, $end - $start);

        return trim((string) $slice);
    }
}
