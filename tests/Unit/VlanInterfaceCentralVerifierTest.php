<?php

use App\Services\VlanInterfaceCentralVerifier;

test('diffExpectedAgainstActual treats false expected and null actual as equal for booleans', function () {
    $verifier = new VlanInterfaceCentralVerifier;

    $expected = [
        'enable' => false,
    ];
    $actual = [
        'enable' => null,
    ];

    expect($verifier->diffExpectedAgainstActual($expected, $actual))->toBe([]);
});

test('diffExpectedAgainstActual still fails when expected true and actual null', function () {
    $verifier = new VlanInterfaceCentralVerifier;

    $expected = [
        'enable' => true,
    ];
    $actual = [
        'enable' => null,
    ];

    $diff = $verifier->diffExpectedAgainstActual($expected, $actual);

    expect($diff)->toHaveCount(1)
        ->and($diff[0]['path'])->toBe('enable');
});
