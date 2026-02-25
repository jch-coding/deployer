<?php

use App\Helper\CSVHelper;

it('processes a CSV file and returns an array of device data', function () {
    $result = CSVHelper::processCSVFile('tests/Unit/test.csv');
    expect($result)->toBeArray()
    ->and($result)->toHaveCount(6);
});

it('creates device arrays from CSV data', function () {
    $csvData = CSVHelper::processCSVFile('tests/Unit/test.csv');
    $deviceArrays = CSVHelper::createDeviceArrays($csvData);
    expect($deviceArrays)
        ->toBeArray()
        ->and($deviceArrays)->toHaveCount(5)
        ->and($deviceArrays[0])->toHaveCount(3)
        ->and($deviceArrays[0])->toHaveKeys(['serial', 'name', 'device_function']);
});

it('returns an empty array if the CSV file is empty', function () {
    $result = CSVHelper::processCSVFile('tests/Unit/empty.csv');
    expect($result)->toBeArray()->and($result)->toHaveCount(0);
});

it('returns a header row if the CSV file contains a header row with no data', function () {
    $result = CSVHelper::processCSVFile('tests/Unit/header.csv');
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
