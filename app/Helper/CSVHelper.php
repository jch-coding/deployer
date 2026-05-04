<?php

namespace App\Helper;

use App\DeviceFunction;
use App\SwitchSKU;
use Illuminate\Validation\ValidationException;

class CSVHelper
{
    private const INTERFACE_MODES = ['ACCESS', 'TRUNK'];

    private const TRUNK_TYPES = ['LACP', 'TRUNK', 'DT_TRUNK', 'MULTI_CHASSIS', 'MULTI_CHASSIS_STATIC'];

    private const LACP_MODES = ['ACTIVE', 'PASSIVE', 'AUTO'];

    private const LACP_RATES = ['FAST', 'SLOW'];

    private const BOOLEAN_KEYS = [
        'trunk_vlan_all',
        'admin_edge_port',
        'admin_edge_port_trunk',
        'bpdu_guard',
        'loop_guard',
        'shutdown_on_split',
    ];

    public static function processCSVFile($handle)
    {
        if (($file = fopen($handle, 'r')) !== false) {
            $data = [];
            while (($row = fgetcsv($file)) !== false) {
                $hasValue = array_filter($row, fn ($cell) => strlen(trim((string) $cell)) > 0);
                if ($hasValue !== []) {
                    array_push($data, $row);
                }
            }
            fclose($file);
            $data = self::fillInDeviceSerialAndDeviceFunction($data);

            return $data;
        } else {
            return [];
        }
    }

    public static function fillInDeviceSerialAndDeviceFunction($CSVData)
    {
        if (empty($CSVData) || ! is_array($CSVData[0] ?? null)) {
            return $CSVData;
        }

        $headers = array_values($CSVData[0]);
        $headerIndex = array_flip($headers);

        foreach (['name', 'serial', 'device_function'] as $column) {
            if (! array_key_exists($column, $headerIndex)) {
                return $CSVData;
            }
        }

        $nameCol = $headerIndex['name'];
        $serialCol = $headerIndex['serial'];
        $deviceFunctionCol = $headerIndex['device_function'];

        $nameToSerialAndFunction = [];

        foreach (array_slice($CSVData, 1) as $row) {
            $row = array_values(is_array($row) ? $row : []);
            $row = array_pad($row, count($headers), '');
            $name = trim((string) ($row[$nameCol] ?? ''));
            $serial = trim((string) ($row[$serialCol] ?? ''));
            $deviceFunction = trim((string) ($row[$deviceFunctionCol] ?? ''));

            if ($name === '' || $serial === '' || $deviceFunction === '') {
                continue;
            }

            $nameToSerialAndFunction[$name] = [
                'serial' => $serial,
                'device_function' => $deviceFunction,
            ];
        }

        for ($i = 1, $n = count($CSVData); $i < $n; $i++) {
            $row = array_values(is_array($CSVData[$i]) ? $CSVData[$i] : []);
            $row = array_pad($row, count($headers), '');
            $name = trim((string) ($row[$nameCol] ?? ''));

            if ($name === '' || ! isset($nameToSerialAndFunction[$name])) {
                continue;
            }

            $from = $nameToSerialAndFunction[$name];

            if (trim((string) ($row[$serialCol] ?? '')) === '') {
                $row[$serialCol] = $from['serial'];
            }
            if (trim((string) ($row[$deviceFunctionCol] ?? '')) === '') {
                $row[$deviceFunctionCol] = $from['device_function'];
            }

            $CSVData[$i] = $row;
        }

        return $CSVData;
    }

    public static function createDeviceArrays($CSVData)
    {
        if (empty($CSVData) || count($CSVData) == 1) {
            return [];
        }
        $headers = array_map(function ($header) {
            $normalized = str_replace('-', '_', trim((string) $header));

            return match ($normalized) {
                'interface_description' => 'description',
                'lag_id' => 'lacp_port_id',
                default => $normalized,
            };
        }, $CSVData[0]);

        $validationMessages = [];
        $deviceArrays = [];
        $dataRows = array_slice($CSVData, 1);

        foreach ($dataRows as $i => $row) {
            $mappedRow = [];
            foreach (array_map(null, $headers, $row) as [$header, $value]) {
                $mappedRow[$header] = $value;
            }
            $csvRowNumber = $i + 2;
            $normalized = self::normalizeDeviceRow($mappedRow, $csvRowNumber, $validationMessages);
            $deviceArrays[] = $normalized;
        }

        if ($validationMessages !== []) {
            $bag = [];
            foreach ($validationMessages as $message) {
                $key = "Row {$message['row']}: {$message['column']}";
                $bag[$key] = [$message['text']];
            }
            throw ValidationException::withMessages($bag);
        }

        return $deviceArrays;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{row: int, column: string, text: string}>  $validationMessages
     * @return array<string, mixed>
     */
    private static function normalizeDeviceRow(array $row, int $csvRowNumber, array &$validationMessages): array
    {
        if (array_key_exists('device_function', $row)) {
            $raw = $row['device_function'];
            $trimmed = is_string($raw) || is_numeric($raw) ? trim((string) $raw) : '';
            if ($raw !== null && ! is_string($raw) && ! is_numeric($raw) && $raw !== '') {
                $validationMessages[] = [
                    'row' => $csvRowNumber,
                    'column' => 'device_function',
                    'text' => 'device_function must be a string matching a defined device function.',
                ];
            } elseif ($trimmed === '') {
                $validationMessages[] = [
                    'row' => $csvRowNumber,
                    'column' => 'device_function',
                    'text' => 'device_function is required and cannot be empty.',
                ];
            } else {
                $resolved = self::matchUnitEnumName($trimmed, DeviceFunction::class);
                if ($resolved === null) {
                    $validationMessages[] = [
                        'row' => $csvRowNumber,
                        'column' => 'device_function',
                        'text' => "device_function \"{$trimmed}\" is not a valid device function.",
                    ];
                } else {
                    $row['device_function'] = $resolved;
                }
            }
        }

        if (array_key_exists('sku', $row)) {
            $raw = $row['sku'];
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                $row['sku'] = is_string($raw) ? $raw : ($raw ?? '');
            } else {
                $trimmed = trim((string) $raw);
                $resolved = self::matchUnitEnumName($trimmed, SwitchSKU::class);
                if ($resolved === null) {
                    $validationMessages[] = [
                        'row' => $csvRowNumber,
                        'column' => 'sku',
                        'text' => "sku \"{$trimmed}\" is not a valid SKU.",
                    ];
                } else {
                    $row['sku'] = $resolved;
                }
            }
        }

        foreach (['interface_mode' => self::INTERFACE_MODES, 'trunk_type' => self::TRUNK_TYPES, 'lacp_mode' => self::LACP_MODES, 'lacp_rate' => self::LACP_RATES] as $column => $allowed) {
            if (! array_key_exists($column, $row)) {
                continue;
            }
            $raw = $row[$column];
            if ($raw === null || (is_string($raw) && trim($raw) === '') || $raw === '') {
                continue;
            }
            $trimmed = trim((string) $raw);
            $resolved = self::matchStringUnion($trimmed, $allowed);
            if ($resolved === null) {
                $validationMessages[] = [
                    'row' => $csvRowNumber,
                    'column' => $column,
                    'text' => "{$column} \"{$trimmed}\" is not valid. Allowed values: ".implode(', ', $allowed).'.',
                ];
            } else {
                $row[$column] = $resolved;
            }
        }

        foreach (self::BOOLEAN_KEYS as $column) {
            if (! array_key_exists($column, $row)) {
                continue;
            }
            try {
                $parsed = BooleanHelper::parseCsvBoolean($row[$column]);
                if ($parsed === '') {
                    $row[$column] = '';
                } else {
                    $row[$column] = $parsed;
                }
            } catch (\InvalidArgumentException) {
                $display = is_scalar($row[$column]) ? (string) $row[$column] : json_encode($row[$column]);
                $validationMessages[] = [
                    'row' => $csvRowNumber,
                    'column' => $column,
                    'text' => "{$column} \"{$display}\" is not a valid boolean (use true/false, 1/0, yes/no).",
                ];
            }
        }

        return $row;
    }

    /**
     * @param  class-string<\UnitEnum>  $enumClass
     */
    private static function matchUnitEnumName(string $trimmed, string $enumClass): ?string
    {
        foreach ($enumClass::cases() as $case) {
            if (strcasecmp($case->name, $trimmed) === 0) {
                return $case->name;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function matchStringUnion(string $trimmed, array $allowed): ?string
    {
        foreach ($allowed as $candidate) {
            if (strcasecmp($candidate, $trimmed) === 0) {
                return $candidate;
            }
        }

        return null;
    }
}
