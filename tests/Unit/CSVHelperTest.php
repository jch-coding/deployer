<?php

use App\Helper\CSVHelper;
use Illuminate\Validation\ValidationException;

it('processes a CSV file and returns an array of device data', function () {
    $result = CSVHelper::processCSVFile('tests/Unit/testcsvs/test.csv');
    expect($result)->toBeArray()
        ->and($result)->toHaveCount(6);
});

it('creates device arrays from CSV data', function () {
    $csvData = CSVHelper::processCSVFile('tests/Unit/testcsvs/test.csv');
    $deviceArrays = CSVHelper::createDeviceArrays($csvData);
    expect($deviceArrays)
        ->toBeArray()
        ->and($deviceArrays)->toHaveCount(5)
        ->and($deviceArrays[0])->toHaveCount(3)
        ->and($deviceArrays[0])->toHaveKeys(['serial', 'name', 'device_function']);
});

it('returns an empty array if the CSV file is empty', function () {
    $result = CSVHelper::processCSVFile('tests/Unit/testcsvs/empty.csv');
    expect($result)->toBeArray()->and($result)->toHaveCount(0);
});

it('returns a header row if the CSV file contains a header row with no data', function () {
    $result = CSVHelper::processCSVFile('tests/Unit/testcsvs/header.csv');
    expect($result)->toBeArray()->and($result)->toHaveCount(1)->and($result[0])->toBe(['name', 'serial', 'device_function']);
});

it('fills missing serial and device_function from an earlier row with the same name', function () {
    $csvData = [
        ['name', 'serial', 'device_function', 'interface'],
        ['ACC-SWITCH-1', 'SN0000000001', 'ACCESS_SWITCH', '1/1/1'],
        ['ACC-SWITCH-1', '', '', '1/1/2'],
        ['OTHER', 'SN0000000002', 'CORE_SWITCH', '1/0/1'],
    ];

    $result = CSVHelper::fillInDeviceSerialAndDeviceFunction($csvData);

    expect($result[0])->toBe($csvData[0])
        ->and($result[1])->toBe(['ACC-SWITCH-1', 'SN0000000001', 'ACCESS_SWITCH', '1/1/1'])
        ->and($result[2])->toBe(['ACC-SWITCH-1', 'SN0000000001', 'ACCESS_SWITCH', '1/1/2'])
        ->and($result[3])->toBe(['OTHER', 'SN0000000002', 'CORE_SWITCH', '1/0/1']);
});

it('does not overwrite serial or device_function when already present', function () {
    $csvData = [
        ['name', 'serial', 'device_function'],
        ['dev-a', 'SN11111111111', 'ACCESS_SWITCH'],
        ['dev-a', '', 'AGG_SWITCH'],
        ['dev-a', 'SN22222222222', ''],
    ];

    $result = CSVHelper::fillInDeviceSerialAndDeviceFunction($csvData);

    expect($result[1])->toBe(['dev-a', 'SN11111111111', 'ACCESS_SWITCH'])
        ->and($result[2])->toBe(['dev-a', 'SN11111111111', 'AGG_SWITCH'])
        ->and($result[3])->toBe(['dev-a', 'SN22222222222', 'ACCESS_SWITCH']);
});

it('returns CSV data unchanged when required columns are missing', function () {
    $csvData = [
        ['name', 'serial'],
        ['a', 'SN11111111111'],
    ];

    expect(CSVHelper::fillInDeviceSerialAndDeviceFunction($csvData))->toBe($csvData);
});

it('only adds non-empty rows to the data array when processing a CSV file', function () {
    $path = tempnam(sys_get_temp_dir(), 'csv_helper_');
    file_put_contents($path, "col1,col2\n\n   ,   \nx,y\n");

    try {
        $result = CSVHelper::processCSVFile($path);
    } finally {
        unlink($path);
    }

    expect($result)->toBeArray()
        ->and($result)->toHaveCount(2)
        ->and($result[0])->toBe(['col1', 'col2'])
        ->and($result[1])->toBe(['x', 'y']);
});

it('skips rows where only the name column is populated when processing a CSV file', function () {
    $path = tempnam(sys_get_temp_dir(), 'csv_helper_');
    file_put_contents($path, "name,serial,device_function\nonly-hostname,,\nhost2,SN0000000001,ACCESS_SWITCH\n");

    try {
        $result = CSVHelper::processCSVFile($path);
    } finally {
        unlink($path);
    }

    expect($result)->toBeArray()
        ->and($result)->toHaveCount(2)
        ->and($result[0])->toBe(['name', 'serial', 'device_function'])
        ->and($result[1])->toBe(['host2', 'SN0000000001', 'ACCESS_SWITCH']);
});

it('returns an empty array if the CSVData is empty', function () {
    $result = CSVHelper::createDeviceArrays([]);
    expect($result)->toBeArray()->and($result)->toHaveCount(0);
});

it('returns an empty array if the CSVData only has a header row', function () {
    $result = CSVHelper::createDeviceArrays([['name', 'serial', 'device_function']]);
    expect($result)->toBeArray()->and($result)->toHaveCount(0);
});

it('processes portchannel and non-portchannel interfaces from one CSV file', function () {
    $result = CSVHelper::createDeviceArrays([
        ['name', 'serial', 'device_function', 'interface', 'interface_mode', 'access_vlan', 'native_vlan', 'trunk_vlan_all', 'trunk_vlan_ranges', 'port_list', 'trunk_type'],
        [
            'name' => 'ACC-SWITCH-1',
            'serial' => 'SN0000000001',
            'device_function' => 'ACCESS_SWITCH',
            'interface' => '1',
            'interface_mode' => 'TRUNK',
            'access_vlan' => null,
            'native_vlan' => '10',
            'trunk_vlan_all' => 'true',
            'trunk_vlan_ranges' => null,
            'port_list' => '1/1/1-1/1/2&2/1/1-2/1/2',
            'trunk_type' => 'LACP',
        ],
        [
            'name' => 'ACC-SWITCH-1',
            'serial' => 'SN0000000001',
            'device_function' => 'ACCESS_SWITCH',
            'interface' => '1/1/3',
            'interface_mode' => 'TRUNK',
            'access_vlan' => null,
            'native_vlan' => '10',
            'trunk_vlan_all' => null,
            'trunk_vlan_ranges' => '10-20',
        ],
    ]);

    expect($result)->toHaveCount(2)
        ->and($result[0])->toMatchArray([
            'name' => 'ACC-SWITCH-1',
            'serial' => 'SN0000000001',
            'device_function' => 'ACCESS_SWITCH',
            'interface' => '1',
            'interface_mode' => 'TRUNK',
            'native_vlan' => '10',
            'trunk_vlan_all' => true,
            'trunk_type' => 'LACP',
            'port_list' => '1/1/1-1/1/2&2/1/1-2/1/2',
        ])
        ->and($result[1])->toMatchArray([
            'name' => 'ACC-SWITCH-1',
            'serial' => 'SN0000000001',
            'device_function' => 'ACCESS_SWITCH',
            'interface' => '1/1/3',
            'interface_mode' => 'TRUNK',
            'native_vlan' => '10',
            'trunk_vlan_ranges' => '10-20',
            'port_list' => null,
            'trunk_type' => null,
        ])
        ->and($result[1]['port_list'])->toBeNull()
        ->and($result[1]['trunk_type'])->toBeNull();
});

it('maps device optional columns when creating device arrays', function () {
    $csvData = [
        ['name', 'serial', 'device_function', 'group', 'sku', 'site'],
        ['SW-1', 'SN0000000001', 'ACCESS_SWITCH', 'Branch-Switches', 'JL660A', 'Site A'],
    ];

    $deviceArrays = CSVHelper::createDeviceArrays($csvData);

    expect($deviceArrays)->toHaveCount(1)
        ->and($deviceArrays[0])->toMatchArray([
            'name' => 'SW-1',
            'serial' => 'SN0000000001',
            'device_function' => 'ACCESS_SWITCH',
            'group' => 'Branch-Switches',
            'sku' => 'JL660A',
            'site' => 'Site A',
        ]);
});

it('maps interface, stp, and lacp optional columns when present', function () {
    $csvData = [
        [
            'name', 'serial', 'device_function', 'interface', 'description',
            'interface_mode', 'access_vlan', 'native_vlan', 'trunk_vlan_all', 'trunk_vlan_ranges',
            'admin_edge_port', 'admin_edge_port_trunk', 'bpdu_guard', 'loop_guard',
            'lacp_mode', 'trunk_type', 'port_list', 'lacp_rate',
        ],
        [
            'SW-1', 'SN0000000001', 'ACCESS_SWITCH', '1/1/1', 'to AP',
            'TRUNK', '', '10', 'false', '10-20',
            'true', '', 'true', 'false',
            'ACTIVE', 'LACP', '1/1/1-1/1/2', 'FAST',
        ],
    ];

    $deviceArrays = CSVHelper::createDeviceArrays($csvData);

    expect($deviceArrays)->toHaveCount(1)
        ->and($deviceArrays[0])->toMatchArray([
            'interface' => '1/1/1',
            'description' => 'to AP',
            'interface_mode' => 'TRUNK',
            'native_vlan' => '10',
            'trunk_vlan_all' => false,
            'trunk_vlan_ranges' => '10-20',
            'admin_edge_port' => true,
            'bpdu_guard' => true,
            'lacp_mode' => 'ACTIVE',
            'trunk_type' => 'LACP',
            'port_list' => '1/1/1-1/1/2',
            'lacp_rate' => 'FAST',
        ]);
});

it('maps routed ethernet optional columns when ip_address is present', function () {
    $csvData = [
        ['name', 'serial', 'device_function', 'interface', 'description', 'ip_address', 'vrf_forwarding'],
        ['SW-1', 'SN0000000001', 'ACCESS_SWITCH', '1/1/53', 'routed uplink', '10.255.0.1/30', 'default'],
    ];

    $deviceArrays = CSVHelper::createDeviceArrays($csvData);

    expect($deviceArrays)->toHaveCount(1)
        ->and($deviceArrays[0])->toMatchArray([
            'interface' => '1/1/53',
            'description' => 'routed uplink',
            'ip_address' => '10.255.0.1/30',
            'vrf_forwarding' => 'default',
        ]);
});

it('rejects ethernet rows that set ip_address together with switchport columns', function () {
    $csvData = [
        ['name', 'serial', 'device_function', 'interface', 'ip_address', 'interface_mode', 'native_vlan'],
        ['SW-1', 'SN0000000001', 'ACCESS_SWITCH', '1/1/10', '10.20.30.1/24', 'TRUNK', '20'],
    ];

    expect(fn () => CSVHelper::createDeviceArrays($csvData))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

it('maps routed LAG optional columns when ip_address and port_list are present', function () {
    $csvData = [
        ['name', 'serial', 'device_function', 'interface', 'description', 'port_list', 'ip_address', 'vrf_forwarding', 'trunk_type', 'lacp_mode'],
        ['SW-1', 'SN0000000001', 'ACCESS_SWITCH', '11', 'Routed LAG', '1/1/1-1/1/2', '10.255.0.1/30', 'my-vrf', 'LACP', 'ACTIVE'],
    ];

    $deviceArrays = CSVHelper::createDeviceArrays($csvData);

    expect($deviceArrays)->toHaveCount(1)
        ->and($deviceArrays[0])->toMatchArray([
            'interface' => '11',
            'description' => 'Routed LAG',
            'port_list' => '1/1/1-1/1/2',
            'ip_address' => '10.255.0.1/30',
            'vrf_forwarding' => 'my-vrf',
            'trunk_type' => 'LACP',
            'lacp_mode' => 'ACTIVE',
        ]);
});

it('rejects LAG rows that set ip_address together with switchport columns', function () {
    $csvData = [
        ['name', 'serial', 'device_function', 'interface', 'port_list', 'ip_address', 'interface_mode', 'native_vlan'],
        ['SW-1', 'SN0000000001', 'ACCESS_SWITCH', '11', '1/1/1-1/1/2', '10.255.0.1/30', 'TRUNK', '20'],
    ];

    expect(fn () => CSVHelper::createDeviceArrays($csvData))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

it('keeps missing optional columns absent from mapped rows', function () {
    $csvData = [
        ['name', 'serial', 'device_function'],
        ['SW-1', 'SN0000000001', 'ACCESS_SWITCH'],
    ];

    $deviceArrays = CSVHelper::createDeviceArrays($csvData);

    expect($deviceArrays[0])->not()->toHaveKeys(['group', 'sku', 'site', 'interface', 'description', 'ip_address']);
});

it('accepts empty name when serial and device_function are present', function () {
    $csvData = [
        ['name', 'serial', 'device_function'],
        ['', 'SN0000000001', 'CAMPUS_AP'],
        ['', 'SN0000000002', 'ACCESS_SWITCH'],
    ];

    $deviceArrays = CSVHelper::createDeviceArrays($csvData);

    expect($deviceArrays)->toHaveCount(2)
        ->and($deviceArrays[0]['name'])->toBe('')
        ->and($deviceArrays[0]['serial'])->toBe('SN0000000001')
        ->and($deviceArrays[1]['device_function'])->toBe('ACCESS_SWITCH');
});

it('strips leading zeros from numeric segments in the interface column', function () {
    $out = CSVHelper::createDeviceArrays([
        ['name', 'serial', 'device_function', 'interface'],
        ['SW-1', 'SN0000000001', 'ACCESS_SWITCH', '01/01/01'],
    ]);

    expect($out[0]['interface'])->toBe('1/1/1');
});

it('maps shutdown_on_split CSV header directly', function () {
    $csvData = [
        ['name', 'serial', 'device_function', 'interface', 'shutdown_on_split'],
        ['SW-1', 'SN0000000001', 'ACCESS_SWITCH', '1/1/1', 'true'],
    ];

    $deviceArrays = CSVHelper::createDeviceArrays($csvData);

    expect($deviceArrays)->toHaveCount(1)
        ->and($deviceArrays[0])->toHaveKey('shutdown_on_split')
        ->and($deviceArrays[0]['shutdown_on_split'])->toBeTrue();
});

it('retains blank optional column values so downstream handlers can normalize them', function () {
    $csvData = [
        ['name', 'serial', 'device_function', 'group', 'site', 'interface', 'description'],
        ['SW-1', 'SN0000000001', 'ACCESS_SWITCH', '', '', '1/1/1', ''],
    ];

    $deviceArrays = CSVHelper::createDeviceArrays($csvData);

    expect($deviceArrays[0])->toMatchArray([
        'group' => '',
        'site' => '',
        'interface' => '1/1/1',
        'description' => '',
    ]);
});

it('normalizes enums and booleans case-insensitively', function () {
    $csvData = [
        ['name', 'serial', 'device_function', 'interface_mode', 'trunk_vlan_all', 'lacp_mode', 'sku'],
        ['SW-1', 'SN0000000001', 'access_switch', 'trunk', 'TRUE', 'active', 'jl660a'],
    ];

    $out = CSVHelper::createDeviceArrays($csvData);

    expect($out[0]['device_function'])->toBe('ACCESS_SWITCH')
        ->and($out[0]['interface_mode'])->toBe('TRUNK')
        ->and($out[0]['trunk_vlan_all'])->toBeTrue()
        ->and($out[0]['lacp_mode'])->toBe('ACTIVE')
        ->and($out[0]['sku'])->toBe('JL660A');
});

it('throws when device_function is not a valid enum', function () {
    expect(fn () => CSVHelper::createDeviceArrays([
        ['name', 'serial', 'device_function'],
        ['SW-1', 'SN0000000001', 'NOT_A_REAL_FUNCTION'],
    ]))->toThrow(ValidationException::class);
});

it('throws when a boolean cell is not recognized', function () {
    expect(fn () => CSVHelper::createDeviceArrays([
        ['name', 'serial', 'device_function', 'trunk_vlan_all'],
        ['SW-1', 'SN0000000001', 'CAMPUS_AP', 'maybe'],
    ]))->toThrow(ValidationException::class);
});

it('throws when sku is not a valid SKU', function () {
    try {
        CSVHelper::createDeviceArrays([
            ['name', 'serial', 'device_function', 'sku'],
            ['SW-1', 'SN0000000001', 'ACCESS_SWITCH', 'NOT_A_REAL_SKU'],
        ]);
        expect(false)->toBeTrue('expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('Row 2 (SW-1 / SN0000000001): sku')
            ->and($e->errors()['Row 2 (SW-1 / SN0000000001): sku'][0])->toContain('not a valid SKU')
            ->and($e->errors()['Row 2 (SW-1 / SN0000000001): sku'][0])->toContain('device SW-1');
    }
});

it('throws when interface_mode is not valid', function () {
    try {
        CSVHelper::createDeviceArrays([
            ['name', 'serial', 'device_function', 'interface_mode'],
            ['SW-1', 'SN0000000001', 'ACCESS_SWITCH', 'HYBRID'],
        ]);
        expect(false)->toBeTrue('expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('Row 2 (SW-1 / SN0000000001): interface_mode')
            ->and($e->errors()['Row 2 (SW-1 / SN0000000001): interface_mode'][0])->toContain('Allowed values: ACCESS, TRUNK');
    }
});

it('throws when trunk_type is not valid', function () {
    try {
        CSVHelper::createDeviceArrays([
            ['name', 'serial', 'device_function', 'trunk_type'],
            ['SW-1', 'SN0000000001', 'ACCESS_SWITCH', 'BOND'],
        ]);
        expect(false)->toBeTrue('expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('Row 2 (SW-1 / SN0000000001): trunk_type')
            ->and($e->errors()['Row 2 (SW-1 / SN0000000001): trunk_type'][0])->toContain('MULTI_CHASSIS_STATIC');
    }
});

it('throws when lacp_mode is not valid', function () {
    try {
        CSVHelper::createDeviceArrays([
            ['name', 'serial', 'device_function', 'lacp_mode'],
            ['SW-1', 'SN0000000001', 'ACCESS_SWITCH', 'ON'],
        ]);
        expect(false)->toBeTrue('expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('Row 2 (SW-1 / SN0000000001): lacp_mode')
            ->and($e->errors()['Row 2 (SW-1 / SN0000000001): lacp_mode'][0])->toContain('ACTIVE, PASSIVE, AUTO');
    }
});

it('throws when lacp_rate is not valid', function () {
    try {
        CSVHelper::createDeviceArrays([
            ['name', 'serial', 'device_function', 'lacp_rate'],
            ['SW-1', 'SN0000000001', 'ACCESS_SWITCH', '1G'],
        ]);
        expect(false)->toBeTrue('expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('Row 2 (SW-1 / SN0000000001): lacp_rate')
            ->and($e->errors()['Row 2 (SW-1 / SN0000000001): lacp_rate'][0])->toContain('FAST, SLOW');
    }
});

it('throws when admin_edge_port is not a valid boolean', function () {
    expect(fn () => CSVHelper::createDeviceArrays([
        ['name', 'serial', 'device_function', 'admin_edge_port'],
        ['SW-1', 'SN0000000001', 'ACCESS_SWITCH', 'sometimes'],
    ]))->toThrow(ValidationException::class);
});

it('throws when admin_edge_port_trunk is not a valid boolean', function () {
    expect(fn () => CSVHelper::createDeviceArrays([
        ['name', 'serial', 'device_function', 'admin_edge_port_trunk'],
        ['SW-1', 'SN0000000001', 'ACCESS_SWITCH', '2'],
    ]))->toThrow(ValidationException::class);
});

it('throws when bpdu_guard is not a valid boolean', function () {
    expect(fn () => CSVHelper::createDeviceArrays([
        ['name', 'serial', 'device_function', 'bpdu_guard'],
        ['SW-1', 'SN0000000001', 'ACCESS_SWITCH', 'yep'],
    ]))->toThrow(ValidationException::class);
});

it('throws when loop_guard is not a valid boolean', function () {
    expect(fn () => CSVHelper::createDeviceArrays([
        ['name', 'serial', 'device_function', 'loop_guard'],
        ['SW-1', 'SN0000000001', 'ACCESS_SWITCH', 'nope'],
    ]))->toThrow(ValidationException::class);
});

it('throws when shutdown_on_split is not a valid boolean', function () {
    expect(fn () => CSVHelper::createDeviceArrays([
        ['name', 'serial', 'device_function', 'interface', 'shutdown_on_split'],
        ['SW-1', 'SN0000000001', 'ACCESS_SWITCH', '1/1/1', 'unknown'],
    ]))->toThrow(ValidationException::class);
});

it('aggregates multiple validation errors for a single row', function () {
    try {
        CSVHelper::createDeviceArrays([
            ['name', 'serial', 'device_function', 'sku', 'interface_mode', 'lacp_rate'],
            ['SW-1', 'SN0000000001', 'ACCESS_SWITCH', 'BAD_SKU', 'INVALID_MODE', 'MEGAFAST'],
        ]);
        expect(false)->toBeTrue('expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKeys([
            'Row 2 (SW-1 / SN0000000001): sku',
            'Row 2 (SW-1 / SN0000000001): interface_mode',
            'Row 2 (SW-1 / SN0000000001): lacp_rate',
        ]);
    }
});

it('maps interface_description and lag_id CSV headers to description and lacp_port_id', function () {
    $csvData = [
        ['name', 'serial', 'device_function', 'interface', 'interface_description', 'lag_id'],
        ['SW-1', 'SN0000000001', 'ACCESS_SWITCH', '1/1/1', 'to core', '12'],
    ];

    $deviceArrays = CSVHelper::createDeviceArrays($csvData);

    expect($deviceArrays)->toHaveCount(1)
        ->and($deviceArrays[0])->toMatchArray([
            'description' => 'to core',
            'lacp_port_id' => '12',
        ])
        ->and($deviceArrays[0])->not()->toHaveKeys(['interface_description', 'lag_id']);
});

it('normalizes hyphenated headers before mapping interface_description and lag_id aliases', function () {
    $csvData = [
        ['name', 'serial', 'device_function', 'interface', 'interface-description', 'lag-id'],
        ['SW-1', 'SN0000000001', 'ACCESS_SWITCH', '1/1/1', 'uplink', '3'],
    ];

    $deviceArrays = CSVHelper::createDeviceArrays($csvData);

    expect($deviceArrays[0])->toMatchArray([
        'description' => 'uplink',
        'lacp_port_id' => '3',
    ]);
});

it('normalizes vsx_system_mac with missing leading zeros and dashes', function () {
    $deviceArrays = CSVHelper::createDeviceArrays([
        ['name', 'serial', 'device_function', 'vsx_system_mac'],
        ['SW-1', 'SN0000000001', 'ACCESS_SWITCH', '2:0:0:0:0:1'],
        ['SW-2', 'SN0000000002', 'ACCESS_SWITCH', '02-00-00-00-00-02'],
    ]);

    expect($deviceArrays[0]['vsx_system_mac'])->toBe('02:00:00:00:00:01')
        ->and($deviceArrays[1]['vsx_system_mac'])->toBe('02:00:00:00:00:02');
});

it('normalizes optional mac_address column', function () {
    $deviceArrays = CSVHelper::createDeviceArrays([
        ['name', 'serial', 'device_function', 'mac_address'],
        ['SW-1', 'SN0000000001', 'ACCESS_SWITCH', 'AA-BB-CC-DD-EE-FF'],
        ['SW-2', 'SN0000000002', 'ACCESS_SWITCH', 'aabbccddeeff'],
    ]);

    expect($deviceArrays[0]['mac_address'])->toBe('aa:bb:cc:dd:ee:ff')
        ->and($deviceArrays[1]['mac_address'])->toBe('aa:bb:cc:dd:ee:ff');
});

it('rejects invalid mac_address values', function () {
    expect(fn () => CSVHelper::createDeviceArrays([
        ['name', 'serial', 'device_function', 'mac_address'],
        ['SW-1', 'SN0000000001', 'ACCESS_SWITCH', 'not-a-mac'],
    ]))->toThrow(ValidationException::class);
});

it('keeps missing optional mac_address column absent', function () {
    $deviceArrays = CSVHelper::createDeviceArrays([
        ['name', 'serial', 'device_function'],
        ['SW-1', 'SN0000000001', 'ACCESS_SWITCH'],
    ]);

    expect($deviceArrays[0])->not()->toHaveKey('mac_address');
});

it('accepts mixed-case vsx column headers and role values', function () {
    $deviceArrays = CSVHelper::createDeviceArrays([
        ['Name', 'Serial', 'Device_Function', 'VSX_Profile', 'VSX_Role', 'VSX_System_Mac'],
        ['SW-1', 'SN0000000001', 'ACCESS_SWITCH', 'pair-1', 'vsx_primary', '02:00:00:00:00:01'],
    ]);

    expect($deviceArrays[0])->toMatchArray([
        'vsx_profile' => 'pair-1',
        'vsx_role' => 'VSX_PRIMARY',
        'vsx_system_mac' => '02:00:00:00:00:01',
    ]);
});

it('normalizes and validates vsx lag port override columns', function () {
    $deviceArrays = CSVHelper::createDeviceArrays([
        ['name', 'serial', 'device_function', 'vsx_isl_ports', 'vsx_keepalive_ports'],
        ['SW-1', 'SN0000000001', 'ACCESS_SWITCH', '1/1/53-1/1/54', '1/1/47&1/1/48'],
    ]);

    expect($deviceArrays[0])->toMatchArray([
        'vsx_isl_ports' => '1/1/53-1/1/54',
        'vsx_keepalive_ports' => '1/1/47&1/1/48',
    ]);
});

it('rejects partial vsx lag port override columns', function () {
    expect(fn () => CSVHelper::createDeviceArrays([
        ['name', 'serial', 'device_function', 'vsx_isl_ports'],
        ['SW-1', 'SN0000000001', 'ACCESS_SWITCH', '1/1/53-1/1/54'],
    ]))->toThrow(ValidationException::class);
});

it('reports missing required columns in header validation', function () {
    try {
        CSVHelper::createDeviceArrays([
            ['name', 'device_function'],
            ['SW-1', 'ACCESS_SWITCH'],
        ]);
        expect(false)->toBeTrue('expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('CSV headers: missing required columns')
            ->and($e->errors()['CSV headers: missing required columns'][0])->toContain('serial');
    }
});

it('reports unrecognized column headers', function () {
    try {
        CSVHelper::createDeviceArrays([
            ['name', 'serial', 'device_function', 'acces_vlan'],
            ['SW-1', 'SN0000000001', 'ACCESS_SWITCH', '10'],
        ]);
        expect(false)->toBeTrue('expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('CSV headers: unrecognized columns')
            ->and($e->errors()['CSV headers: unrecognized columns'][0])->toContain('acces_vlan');
    }
});

it('reports duplicate normalized column headers', function () {
    try {
        CSVHelper::createDeviceArrays([
            ['name', 'serial', 'device_function', 'name'],
            ['SW-1', 'SN0000000001', 'ACCESS_SWITCH'],
        ]);
        expect(false)->toBeTrue('expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('CSV headers: duplicate columns')
            ->and($e->errors()['CSV headers: duplicate columns'][0])->toContain('name');
    }
});

it('allows interface rows with blank serial and device_function when an anchor row exists', function () {
    $csvData = CSVHelper::fillInDeviceSerialAndDeviceFunction([
        ['name', 'serial', 'device_function', 'interface'],
        ['ACC-SWITCH-1', 'SN0000000001', 'ACCESS_SWITCH', '1/1/1'],
        ['ACC-SWITCH-1', '', '', '1/1/2'],
    ]);

    $deviceArrays = CSVHelper::createDeviceArrays($csvData);

    expect($deviceArrays)->toHaveCount(2)
        ->and($deviceArrays[1])->toMatchArray([
            'name' => 'ACC-SWITCH-1',
            'serial' => 'SN0000000001',
            'device_function' => 'ACCESS_SWITCH',
            'interface' => '1/1/2',
        ]);
});

it('reports inheritance hint when serial and device_function cannot be resolved', function () {
    try {
        CSVHelper::createDeviceArrays([
            ['name', 'serial', 'device_function', 'interface'],
            ['SW-1', '', '', '1/1/1'],
        ]);
        expect(false)->toBeTrue('expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('Row 2 (SW-1 / 1/1/1): serial')
            ->and($e->errors()['Row 2 (SW-1 / 1/1/1): serial'][0])->toContain('no other row for device "SW-1"')
            ->and($e->errors())->toHaveKey('Row 2 (SW-1 / 1/1/1): device_function');
    }
});

it('skips name-only organizational rows when processing a CSV file', function () {
    $path = tempnam(sys_get_temp_dir(), 'csv_helper_');
    file_put_contents($path, "name,serial,device_function,interface\nBuilding A Switches,,,\nSW-1,SN0000000001,ACCESS_SWITCH,1/1/1\n");

    try {
        $csvData = CSVHelper::processCSVFile($path);
        $deviceArrays = CSVHelper::createDeviceArrays($csvData);
    } finally {
        unlink($path);
    }

    expect($deviceArrays)->toHaveCount(1)
        ->and($deviceArrays[0]['name'])->toBe('SW-1');
});
