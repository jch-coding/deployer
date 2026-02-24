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
