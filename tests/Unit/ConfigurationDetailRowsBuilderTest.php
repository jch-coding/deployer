<?php

use App\Services\ConfigurationDetailRowsBuilder;

test('fromExpectedAndActual returns a row for each expected field', function () {
    $expected = [
        'id' => '100',
        'ipv4' => ['address' => '10.0.0.1/24'],
        'enable' => true,
    ];
    $actual = [
        'id' => '100',
        'ipv4' => ['address' => '10.0.0.1/24'],
        'enable' => true,
    ];

    $rows = ConfigurationDetailRowsBuilder::fromExpectedAndActual($expected, $actual);

    expect($rows)->toHaveCount(3)
        ->and(collect($rows)->pluck('path')->all())->toContain('id', 'ipv4.address', 'enable');
});

test('fromExpectedAndActual includes mismatched values in rows', function () {
    $rows = ConfigurationDetailRowsBuilder::fromExpectedAndActual(
        ['lacp' => ['mode' => 'ACTIVE']],
        ['lacp' => ['mode' => 'PASSIVE']],
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['path'])->toBe('lacp.mode')
        ->and($rows[0]['expected'])->toBe('ACTIVE')
        ->and($rows[0]['actual'])->toBe('PASSIVE');
});
