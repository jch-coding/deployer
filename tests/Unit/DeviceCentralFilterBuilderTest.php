<?php

use App\Services\DeviceCentralFilterBuilder;

test('build returns null when no criteria are set', function () {
    $builder = new DeviceCentralFilterBuilder;

    expect($builder->build([]))->toBeNull();
});

test('build produces a single quoted eq clause', function () {
    $builder = new DeviceCentralFilterBuilder;

    expect($builder->build(['siteId' => 'site-scope-123']))
        ->toBe("siteId eq 'site-scope-123'");
});

test('build joins multiple criteria with and', function () {
    $builder = new DeviceCentralFilterBuilder;

    expect($builder->build([
        'siteId' => 'site-1',
        'status' => 'ONLINE',
        'deviceType' => 'SWITCH',
    ]))->toBe("siteId eq 'site-1' and deviceType eq 'SWITCH' and status eq 'ONLINE'");
});

test('build quotes values with spaces', function () {
    $builder = new DeviceCentralFilterBuilder;

    expect($builder->build(['siteName' => 'Main Campus']))
        ->toBe("siteName eq 'Main Campus'");
});

test('build escapes single quotes in quoted values', function () {
    $builder = new DeviceCentralFilterBuilder;

    expect($builder->build(['deviceName' => "O'Brien Switch"]))
        ->toBe("deviceName eq 'O''Brien Switch'");
});

test('build quotes serial numbers', function () {
    $builder = new DeviceCentralFilterBuilder;

    expect($builder->build(['serialNumber' => 'SN12345']))
        ->toBe("serialNumber eq 'SN12345'");
});

test('buildIn returns quoted in clause', function () {
    $builder = new DeviceCentralFilterBuilder;

    expect($builder->buildIn('serialNumber', ['SN111', 'SN222', 'SN111']))
        ->toBe("serialNumber in ('SN111', 'SN222')");
});

test('buildIn returns null for empty values or unknown fields', function () {
    $builder = new DeviceCentralFilterBuilder;

    expect($builder->buildIn('serialNumber', ['', '  ']))->toBeNull()
        ->and($builder->buildIn('unknownField', ['SN111']))->toBeNull();
});
