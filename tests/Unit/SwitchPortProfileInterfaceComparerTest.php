<?php

use App\Services\SwitchPortProfileInterfaceComparer;

test('modesEqual is case insensitive', function () {
    $comparer = new SwitchPortProfileInterfaceComparer;

    expect($comparer->modesEqual('TRUNK', 'Trunk'))->toBeTrue()
        ->and($comparer->modesEqual('ACCESS', 'access'))->toBeTrue()
        ->and($comparer->modesEqual('TRUNK', 'ACCESS'))->toBeFalse()
        ->and($comparer->modesEqual(null, null))->toBeTrue()
        ->and($comparer->modesEqual('TRUNK', null))->toBeFalse();
});

test('vlanListsEqual ignores order and duplicates', function () {
    $comparer = new SwitchPortProfileInterfaceComparer;

    expect($comparer->vlanListsEqual([20, 10, 30], [30, 10, 20]))->toBeTrue()
        ->and($comparer->vlanListsEqual([10, 10, 20], [20, 10]))->toBeTrue()
        ->and($comparer->vlanListsEqual([10, 20], [10, 20, 30]))->toBeFalse()
        ->and($comparer->vlanListsEqual([], []))->toBeTrue();
});

test('normalizeVlanIdList expands range strings and numeric arrays', function () {
    $comparer = new SwitchPortProfileInterfaceComparer;

    expect($comparer->normalizeVlanIdList([10, 20, 30]))->toBe([10, 20, 30])
        ->and($comparer->normalizeVlanIdList(['10-12', 20]))->toBe([10, 11, 12, 20])
        ->and($comparer->normalizeVlanIdList('8&10-12'))->toBe([8, 10, 11, 12])
        ->and($comparer->normalizeVlanIdList(null))->toBe([])
        ->and($comparer->normalizeVlanIdList(''))->toBe([]);
});

test('diffExpectedActual reports mode native and vlan differences', function () {
    $comparer = new SwitchPortProfileInterfaceComparer;

    $expected = [
        'interface_mode' => 'TRUNK',
        'native_vlan' => 1,
        'trunk_vlan_ids' => [10, 20],
    ];
    $actual = [
        'vlan_mode' => 'Access',
        'native_vlan' => 99,
        'allowed_vlan_ids' => [10],
    ];

    $differences = $comparer->diffExpectedActual($expected, $actual);

    expect($differences)->toHaveCount(3)
        ->and(collect($differences)->pluck('field')->all())->toBe([
            'interface_mode',
            'native_vlan',
            'trunk_vlans',
        ]);
});

test('diffExpectedActual returns empty when values match case-insensitively', function () {
    $comparer = new SwitchPortProfileInterfaceComparer;

    $expected = [
        'interface_mode' => 'TRUNK',
        'native_vlan' => 1,
        'trunk_vlan_ids' => [20, 10],
    ];
    $actual = [
        'vlan_mode' => 'Trunk',
        'native_vlan' => 1,
        'allowed_vlan_ids' => [10, 20],
    ];

    expect($comparer->diffExpectedActual($expected, $actual))->toBe([]);
});

test('diffExpectedActual for ACCESS ignores allowed vlan set differences', function () {
    $comparer = new SwitchPortProfileInterfaceComparer;

    $expected = [
        'interface_mode' => 'ACCESS',
        'native_vlan' => 10,
        'trunk_vlan_ids' => [],
    ];
    $actual = [
        'vlan_mode' => 'Access',
        'native_vlan' => 10,
        'allowed_vlan_ids' => [1, 2, 3],
    ];

    expect($comparer->diffExpectedActual($expected, $actual))->toBe([]);
});

test('diffExpectedActual for ACCESS reports access vlan mismatch against nativeVlan', function () {
    $comparer = new SwitchPortProfileInterfaceComparer;

    $expected = [
        'interface_mode' => 'ACCESS',
        'native_vlan' => 10,
        'trunk_vlan_ids' => [],
    ];
    $actual = [
        'vlan_mode' => 'Access',
        'native_vlan' => 99,
        'allowed_vlan_ids' => [99],
    ];

    $differences = $comparer->diffExpectedActual($expected, $actual);

    expect($differences)->toHaveCount(1)
        ->and($differences[0]['field'])->toBe('native_vlan')
        ->and($differences[0]['expected'])->toBe(10)
        ->and($differences[0]['actual'])->toBe(99);
});

test('mapExpected for ACCESS uses access-vlan and empty trunk list', function () {
    $comparer = new SwitchPortProfileInterfaceComparer;

    $mapped = $comparer->mapExpected([
        'profile-name' => 'access-ports',
        'switchport' => [
            'interface-mode' => 'ACCESS',
            'access-vlan' => 10,
            'native-vlan' => 99,
            'trunk-vlan-ranges' => [10, 20],
        ],
    ]);

    expect($mapped)->toBe([
        'interface_mode' => 'ACCESS',
        'native_vlan' => 10,
        'trunk_vlan_ids' => [],
    ]);
});

test('mapExpected for TRUNK reads native-vlan and trunk-vlan-ranges', function () {
    $comparer = new SwitchPortProfileInterfaceComparer;

    $mapped = $comparer->mapExpected([
        'profile-name' => 'trunk-ports',
        'switchport' => [
            'interface-mode' => 'TRUNK',
            'native-vlan' => 1,
            'trunk-vlan-ranges' => [10, 20],
        ],
    ]);

    expect($mapped)->toBe([
        'interface_mode' => 'TRUNK',
        'native_vlan' => 1,
        'trunk_vlan_ids' => [10, 20],
    ]);
});

test('normalizeSwPortProfileResponse treats empty arrays as empty', function () {
    $client = App\Models\Client::factory()->create([
        'expires_at' => now()->addHour(),
        'bearer_token' => 'test-bearer-token',
        'base_url' => App\BaseURL::US1->value,
    ]);
    $helper = new App\Helper\CentralAPIHelper($client);

    expect($helper->normalizeSwPortProfileResponse([], 'p1'))->toMatchArray([
        'profile' => null,
        'empty' => true,
        'error' => null,
    ]);

    expect($helper->normalizeSwPortProfileResponse(['profile' => []], 'p1'))->toMatchArray([
        'profile' => null,
        'empty' => true,
        'error' => null,
    ]);

    $profile = [
        'profile-name' => 'p1',
        'switchport' => ['interface-mode' => 'ACCESS', 'native-vlan' => 1],
    ];

    expect($helper->normalizeSwPortProfileResponse($profile, 'p1'))->toMatchArray([
        'profile' => $profile,
        'empty' => false,
        'error' => null,
    ]);
});
