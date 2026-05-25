<?php

use App\Services\DeviceCentralFilterBuilder;

test('build returns null when no criteria are set', function () {
    $builder = new DeviceCentralFilterBuilder;

    expect($builder->build([]))->toBeNull();
});

test('build produces a single eq clause', function () {
    $builder = new DeviceCentralFilterBuilder;

    expect($builder->build(['siteId' => 'site-scope-123']))
        ->toBe('siteId eq site-scope-123');
});

test('build joins multiple criteria with and', function () {
    $builder = new DeviceCentralFilterBuilder;

    expect($builder->build([
        'siteId' => 'site-1',
        'status' => 'ONLINE',
        'deviceType' => 'SWITCH',
    ]))->toBe('siteId eq site-1 and status eq ONLINE and deviceType eq SWITCH');
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
