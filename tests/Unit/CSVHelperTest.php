<?php

use App\Helper\CSVHelper;

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
            'name', 'serial', 'device_function', 'interface', 'description', 'ip_address',
            'interface_mode', 'access_vlan', 'native_vlan', 'trunk_vlan_all', 'trunk_vlan_ranges',
            'admin_edge_port', 'admin_edge_port_trunk', 'bpdu_guard', 'loop_guard',
            'lacp_mode', 'trunk_type', 'port_list', 'lacp_rate',
        ],
        [
            'SW-1', 'SN0000000001', 'ACCESS_SWITCH', '1/1/1', 'to AP', '10.0.0.1/24',
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
            'ip_address' => '10.0.0.1/24',
            'interface_mode' => 'TRUNK',
            'native_vlan' => '10',
            'trunk_vlan_all' => 'false',
            'trunk_vlan_ranges' => '10-20',
            'admin_edge_port' => 'true',
            'bpdu_guard' => 'true',
            'lacp_mode' => 'ACTIVE',
            'trunk_type' => 'LACP',
            'port_list' => '1/1/1-1/1/2',
            'lacp_rate' => 'FAST',
        ]);
});

it('keeps missing optional columns absent from mapped rows', function () {
    $csvData = [
        ['name', 'serial', 'device_function'],
        ['SW-1', 'SN0000000001', 'ACCESS_SWITCH'],
    ];

    $deviceArrays = CSVHelper::createDeviceArrays($csvData);

    expect($deviceArrays[0])->not()->toHaveKeys(['group', 'sku', 'site', 'interface', 'description', 'ip_address']);
});

it('normalizes shutdown-on-split CSV header to shutdown_on_split key', function () {
    $csvData = [
        ['name', 'serial', 'device_function', 'interface', 'shutdown-on-split'],
        ['SW-1', 'SN0000000001', 'ACCESS_SWITCH', '1/1/1', 'true'],
    ];

    $deviceArrays = CSVHelper::createDeviceArrays($csvData);

    expect($deviceArrays)->toHaveCount(1)
        ->and($deviceArrays[0])->toHaveKey('shutdown_on_split')
        ->and($deviceArrays[0]['shutdown_on_split'])->toBe('true');
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
