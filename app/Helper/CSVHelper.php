<?php

namespace App\Helper;

use App\DeviceFunction;
use App\Support\TrunkVlanRanges;
use App\SwitchSKU;
use App\VsxRole;
use Illuminate\Validation\ValidationException;

class CSVHelper
{
    private const REQUIRED_COLUMNS = ['name', 'serial', 'device_function'];

    private const KNOWN_COLUMNS = [
        'name',
        'serial',
        'device_function',
        'description',
        'interface',
        'port_list',
        'ip_address',
        'vrf_forwarding',
        'sku',
        'site',
        'group',
        'port_profile',
        'interface_mode',
        'access_vlan',
        'native_vlan',
        'trunk_vlan_all',
        'trunk_vlan_ranges',
        'admin_edge_port',
        'admin_edge_port_trunk',
        'bpdu_guard',
        'loop_guard',
        'shutdown_on_split',
        'lacp_mode',
        'lacp_rate',
        'trunk_type',
        'vsx_profile',
        'vsx_role',
        'vsx_system_mac',
        'vsx_isl_ports',
        'vsx_keepalive_ports',
        'mirror_session_id',
        'mirror_dst_ports',
        'mirror_vlans',
        'mirror_name',
        'lacp_port_id',
        'license_tag',
        'license_type',
    ];

    private const MAX_ROW_ERRORS = 50;

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

    private const ROUTED_ETHERNET_L2_CONFLICT_COLUMNS = [
        'port_profile',
        'interface_mode',
        'access_vlan',
        'native_vlan',
        'trunk_vlan_all',
        'trunk_vlan_ranges',
        'admin_edge_port',
        'admin_edge_port_trunk',
        'bpdu_guard',
        'loop_guard',
        'shutdown_on_split',
        'lacp_mode',
        'lacp_rate',
        'trunk_type',
        'port_list',
        'lacp_port_id',
    ];

    private const ROUTED_LAG_L2_CONFLICT_COLUMNS = [
        'port_profile',
        'interface_mode',
        'access_vlan',
        'native_vlan',
        'trunk_vlan_all',
        'trunk_vlan_ranges',
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
            $headers = null;
            $nameCol = null;
            while (($row = fgetcsv($file)) !== false) {
                $nonEmptyIndices = [];
                foreach ($row as $idx => $cell) {
                    if (strlen(trim((string) $cell)) > 0) {
                        $nonEmptyIndices[] = $idx;
                    }
                }

                // Preserve existing behavior: skip fully empty rows.
                if ($nonEmptyIndices === []) {
                    continue;
                }

                // First non-empty row is treated as headers.
                if ($headers === null) {
                    $headers = array_values($row);
                    $nameCol = array_search('name', $headers, true);
                    $data[] = $row;

                    continue;
                }

                // If we can identify the name column, skip rows where it is the only populated field.
                if (is_int($nameCol) && count($nonEmptyIndices) === 1 && $nonEmptyIndices[0] === $nameCol) {
                    continue;
                }

                $data[] = $row;
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
        if (empty($CSVData) || ! is_array($CSVData[0] ?? null)) {
            return [];
        }

        $headerErrors = self::validateCsvHeaders($CSVData[0]);

        if ($headerErrors !== []) {
            throw ValidationException::withMessages($headerErrors);
        }

        if (count($CSVData) === 1) {
            return [];
        }

        $headers = array_map(
            fn ($header) => self::normalizeCsvHeader((string) $header),
            $CSVData[0]
        );

        $validationMessages = [];
        $deviceArrays = [];
        $dataRows = array_slice($CSVData, 1);

        foreach ($dataRows as $i => $row) {
            $mappedRow = [];
            foreach (array_map(null, $headers, $row) as [$header, $value]) {
                $mappedRow[$header] = $value;
            }
            $csvRowNumber = $i + 2;
            self::validateRequiredRowFields($mappedRow, $csvRowNumber, $validationMessages);
            if (count($validationMessages) >= self::MAX_ROW_ERRORS) {
                break;
            }
            $normalized = self::normalizeDeviceRow($mappedRow, $csvRowNumber, $validationMessages);
            $deviceArrays[] = $normalized;
            if (count($validationMessages) >= self::MAX_ROW_ERRORS) {
                break;
            }
        }

        if ($validationMessages !== []) {
            throw ValidationException::withMessages(
                self::buildValidationErrorBag([], $validationMessages)
            );
        }

        return $deviceArrays;
    }

    public static function normalizeCsvHeader(string $raw): string
    {
        $normalized = strtolower(str_replace('-', '_', trim($raw)));

        return match ($normalized) {
            'interface_description' => 'description',
            'lag_id' => 'lacp_port_id',
            default => $normalized,
        };
    }

    /**
     * @param  list<string|int|float|null>  $rawHeaders
     * @return array<string, list<string>>
     */
    public static function validateCsvHeaders(array $rawHeaders): array
    {
        $errors = [];
        $normalizedPresent = [];
        $unrecognized = [];
        $duplicateNormalized = [];

        foreach ($rawHeaders as $rawHeader) {
            $display = trim((string) $rawHeader);
            if ($display === '') {
                $unrecognized[] = '(blank)';

                continue;
            }

            $normalized = self::normalizeCsvHeader($display);
            if (! in_array($normalized, self::KNOWN_COLUMNS, true)) {
                $unrecognized[] = $display;

                continue;
            }

            if (isset($normalizedPresent[$normalized])) {
                $duplicateNormalized[$normalized] = $normalized;
            }
            $normalizedPresent[$normalized] = true;
        }

        $missing = array_values(array_filter(
            self::REQUIRED_COLUMNS,
            fn (string $column) => ! isset($normalizedPresent[$column])
        ));

        if ($missing !== []) {
            $errors['CSV headers: missing required columns'] = [
                'Missing required column(s): '.implode(', ', $missing).'.',
            ];
        }

        if ($unrecognized !== []) {
            $errors['CSV headers: unrecognized columns'] = [
                'Unrecognized column(s): '.implode(', ', array_unique($unrecognized))
                .'. Allowed columns: '.implode(', ', self::KNOWN_COLUMNS).'.',
            ];
        }

        if ($duplicateNormalized !== []) {
            $errors['CSV headers: duplicate columns'] = [
                'Duplicate column(s): '.implode(', ', array_keys($duplicateNormalized)).'.',
            ];
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{row: int, column: string, text: string}>  $validationMessages
     */
    private static function validateRequiredRowFields(array $row, int $csvRowNumber, array &$validationMessages): void
    {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            self::addValidationMessage(
                $validationMessages,
                $csvRowNumber,
                'name',
                $row,
                'name is required on every data row.'
            );

            return;
        }

        $serial = trim((string) ($row['serial'] ?? ''));
        if ($serial === '') {
            self::addValidationMessage(
                $validationMessages,
                $csvRowNumber,
                'serial',
                $row,
                "serial is required on this row because no other row for device \"{$name}\" provides name, serial, and device_function together."
            );
        }

        $deviceFunction = trim((string) ($row['device_function'] ?? ''));
        if ($deviceFunction === '') {
            self::addValidationMessage(
                $validationMessages,
                $csvRowNumber,
                'device_function',
                $row,
                "device_function is required on this row because no other row for device \"{$name}\" provides name, serial, and device_function together."
            );
        }
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
                self::addValidationMessage(
                    $validationMessages,
                    $csvRowNumber,
                    'device_function',
                    $row,
                    'device_function must be a string matching a defined device function.'
                );
            } elseif ($trimmed !== '') {
                $resolved = self::matchUnitEnumName($trimmed, DeviceFunction::class);
                if ($resolved === null) {
                    self::addValidationMessage(
                        $validationMessages,
                        $csvRowNumber,
                        'device_function',
                        $row,
                        "device_function \"{$trimmed}\" is not a valid device function."
                    );
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
                    self::addValidationMessage(
                        $validationMessages,
                        $csvRowNumber,
                        'sku',
                        $row,
                        "sku \"{$trimmed}\" is not a valid SKU."
                    );
                } else {
                    $row['sku'] = $resolved;
                }
            }
        }

        if (array_key_exists('vsx_role', $row)) {
            $raw = $row['vsx_role'];
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                $row['vsx_role'] = is_string($raw) ? $raw : ($raw ?? '');
            } else {
                $trimmed = trim((string) $raw);
                $resolved = self::matchUnitEnumName($trimmed, VsxRole::class);
                if ($resolved === null) {
                    self::addValidationMessage(
                        $validationMessages,
                        $csvRowNumber,
                        'vsx_role',
                        $row,
                        "vsx_role \"{$trimmed}\" is not valid. Allowed values: VSX_PRIMARY, VSX_SECONDARY."
                    );
                } else {
                    $row['vsx_role'] = $resolved;
                }
            }
        }

        if (array_key_exists('vsx_system_mac', $row)) {
            $raw = $row['vsx_system_mac'];
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                $row['vsx_system_mac'] = is_string($raw) ? $raw : ($raw ?? '');
            } else {
                $trimmed = trim((string) $raw);
                $normalizedMac = self::normalizeVsxSystemMac($trimmed);
                if ($normalizedMac === null) {
                    self::addValidationMessage(
                        $validationMessages,
                        $csvRowNumber,
                        'vsx_system_mac',
                        $row,
                        "vsx_system_mac \"{$trimmed}\" must match 02:00:00:00:00:xx where xx are hex digits starting from 01."
                    );
                } else {
                    $row['vsx_system_mac'] = $normalizedMac;
                }
            }
        }

        foreach (['vsx_isl_ports', 'vsx_keepalive_ports'] as $column) {
            if (! array_key_exists($column, $row)) {
                continue;
            }

            $raw = $row[$column];
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                $row[$column] = is_string($raw) ? $raw : ($raw ?? '');

                continue;
            }

            $row[$column] = InterfaceHelper::normalizeInterfaceString(trim((string) $raw));
        }

        $hasIslPorts = filled($row['vsx_isl_ports'] ?? '');
        $hasKeepalivePorts = filled($row['vsx_keepalive_ports'] ?? '');
        if ($hasIslPorts xor $hasKeepalivePorts) {
            self::addValidationMessage(
                $validationMessages,
                $csvRowNumber,
                'vsx_isl_ports',
                $row,
                'vsx_isl_ports and vsx_keepalive_ports must both be set when overriding VSX LAG member ports.'
            );
        } elseif ($hasIslPorts && $hasKeepalivePorts) {
            foreach ([
                'vsx_isl_ports' => 'vsx_isl_ports',
                'vsx_keepalive_ports' => 'vsx_keepalive_ports',
            ] as $column => $label) {
                $expanded = InterfaceHelper::expandInterfaceRange((string) $row[$column]);
                if (count($expanded) !== 2) {
                    self::addValidationMessage(
                        $validationMessages,
                        $csvRowNumber,
                        $column,
                        $row,
                        "{$label} \"{$row[$column]}\" must expand to exactly 2 interfaces."
                    );
                }
            }
        }

        if (array_key_exists('mirror_session_id', $row)) {
            $raw = $row['mirror_session_id'];
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                $row['mirror_session_id'] = is_string($raw) ? $raw : ($raw ?? '');
            } else {
                $trimmed = trim((string) $raw);
                if (! preg_match('/^[1-4]$/', $trimmed)) {
                    self::addValidationMessage(
                        $validationMessages,
                        $csvRowNumber,
                        'mirror_session_id',
                        $row,
                        "mirror_session_id \"{$trimmed}\" must be an integer between 1 and 4."
                    );
                } else {
                    $row['mirror_session_id'] = $trimmed;
                }
            }
        }

        if (array_key_exists('mirror_dst_ports', $row)) {
            $raw = $row['mirror_dst_ports'];
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                $row['mirror_dst_ports'] = is_string($raw) ? $raw : ($raw ?? '');
            } else {
                $row['mirror_dst_ports'] = InterfaceHelper::normalizeInterfaceString(trim((string) $raw));
            }
        }

        if (array_key_exists('mirror_vlans', $row)) {
            $raw = $row['mirror_vlans'];
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                $row['mirror_vlans'] = is_string($raw) ? $raw : ($raw ?? '');
            } else {
                try {
                    $row['mirror_vlans'] = TrunkVlanRanges::normalizeForStorage(
                        trim((string) $raw),
                        'mirror_vlans'
                    ) ?? '';
                } catch (ValidationException $e) {
                    foreach ($e->errors()['mirror_vlans'] ?? [] as $message) {
                        self::addValidationMessage(
                            $validationMessages,
                            $csvRowNumber,
                            'mirror_vlans',
                            $row,
                            (string) $message
                        );
                    }
                }
            }
        }

        if (array_key_exists('mirror_name', $row)) {
            $raw = $row['mirror_name'];
            $row['mirror_name'] = is_string($raw) ? trim($raw) : (is_numeric($raw) ? trim((string) $raw) : ($raw ?? ''));
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
                self::addValidationMessage(
                    $validationMessages,
                    $csvRowNumber,
                    $column,
                    $row,
                    "{$column} \"{$trimmed}\" is not valid. Allowed values: ".implode(', ', $allowed).'.'
                );
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
                self::addValidationMessage(
                    $validationMessages,
                    $csvRowNumber,
                    $column,
                    $row,
                    "{$column} \"{$display}\" is not a valid boolean (use true/false, 1/0, yes/no)."
                );
            }
        }

        if (array_key_exists('interface', $row)) {
            $raw = $row['interface'];
            if ($raw !== null && (is_string($raw) || is_numeric($raw))) {
                $trimmed = trim((string) $raw);
                if ($trimmed === '') {
                    $row['interface'] = is_string($raw) ? $raw : '';
                } else {
                    $row['interface'] = InterfaceHelper::normalizeInterfaceString($trimmed);
                }
            }
        }

        if (InterfaceHelper::isRoutedEthernetRow($row)) {
            foreach (self::ROUTED_ETHERNET_L2_CONFLICT_COLUMNS as $column) {
                if (! self::isCsvCellPopulated($row[$column] ?? null)) {
                    continue;
                }
                self::addValidationMessage(
                    $validationMessages,
                    $csvRowNumber,
                    'ip_address',
                    $row,
                    "ip_address cannot be set together with {$column} on an ethernet interface."
                );
            }
        }

        if (InterfaceHelper::isRoutedLagRow($row)) {
            foreach (self::ROUTED_LAG_L2_CONFLICT_COLUMNS as $column) {
                if (! self::isCsvCellPopulated($row[$column] ?? null)) {
                    continue;
                }
                self::addValidationMessage(
                    $validationMessages,
                    $csvRowNumber,
                    'ip_address',
                    $row,
                    "ip_address cannot be set together with {$column} on a LAG interface."
                );
            }
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{row: int, column: string, text: string}>  $validationMessages
     */
    private static function addValidationMessage(
        array &$validationMessages,
        int $csvRowNumber,
        string $column,
        array $row,
        string $text
    ): void {
        $validationMessages[] = [
            'row' => $csvRowNumber,
            'column' => $column,
            'text' => self::appendRowContextToMessage($text, $row),
            'context' => [
                'name' => $row['name'] ?? null,
                'serial' => $row['serial'] ?? null,
                'interface' => $row['interface'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function appendRowContextToMessage(string $text, array $row): string
    {
        $suffix = self::formatRowContextSuffix($row);

        return $suffix !== '' ? "{$text} {$suffix}" : $text;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function formatRowContextLabel(array $row): string
    {
        $parts = [];
        if (self::isCsvCellPopulated($row['name'] ?? null)) {
            $parts[] = trim((string) $row['name']);
        }
        if (self::isCsvCellPopulated($row['serial'] ?? null)) {
            $parts[] = trim((string) $row['serial']);
        }
        if (self::isCsvCellPopulated($row['interface'] ?? null)) {
            $parts[] = trim((string) $row['interface']);
        }

        return $parts !== [] ? ' ('.implode(' / ', $parts).')' : '';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function formatRowContextSuffix(array $row): string
    {
        $parts = [];
        if (self::isCsvCellPopulated($row['name'] ?? null)) {
            $parts[] = 'device '.trim((string) $row['name']);
        }
        if (self::isCsvCellPopulated($row['serial'] ?? null)) {
            $parts[] = 'serial '.trim((string) $row['serial']);
        }
        if (self::isCsvCellPopulated($row['interface'] ?? null)) {
            $parts[] = 'interface '.trim((string) $row['interface']);
        }

        return $parts !== [] ? '('.implode(', ', $parts).').' : '';
    }

    private static function formatRowErrorKey(int $csvRowNumber, array $row, string $column): string
    {
        return 'Row '.$csvRowNumber.self::formatRowContextLabel($row).': '.$column;
    }

    /**
     * @param  array<string, list<string>>  $headerErrors
     * @param  list<array{row: int, column: string, text: string, context?: array<string, mixed>}>  $validationMessages
     * @return array<string, list<string>>
     */
    private static function buildValidationErrorBag(array $headerErrors, array $validationMessages): array
    {
        $bag = $headerErrors;

        foreach ($validationMessages as $message) {
            $key = self::formatRowErrorKey(
                $message['row'],
                $message['context'] ?? [],
                $message['column']
            );
            $bag[$key] = [$message['text']];
        }

        return $bag;
    }

    /**
     * Normalize VSX system MAC to 02:00:00:00:00:xx (accepts dashes, missing leading zeros).
     */
    private static function normalizeVsxSystemMac(string $value): ?string
    {
        $value = strtolower(str_replace('-', ':', trim($value)));
        $parts = explode(':', $value);
        if (count($parts) !== 6) {
            return null;
        }

        $normalizedParts = [];
        foreach ($parts as $part) {
            if (! preg_match('/^[0-9a-f]{1,2}$/', $part)) {
                return null;
            }
            $normalizedParts[] = str_pad($part, 2, '0', STR_PAD_LEFT);
        }

        if ($normalizedParts[0] !== '02'
            || $normalizedParts[1] !== '00'
            || $normalizedParts[2] !== '00'
            || $normalizedParts[3] !== '00'
            || $normalizedParts[4] !== '00') {
            return null;
        }

        if (hexdec($normalizedParts[5]) < 0x01) {
            return null;
        }

        return implode(':', $normalizedParts);
    }

    private static function isCsvCellPopulated(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_string($value) && trim($value) === '') {
            return false;
        }

        return $value !== '';
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
