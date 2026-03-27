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
           'trunk_type' => 'LACP'
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
       ]
   ]);
});
