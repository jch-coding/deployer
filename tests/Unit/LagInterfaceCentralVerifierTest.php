<?php

use App\InterfaceKind;
use App\Models\DeviceInterface;
use App\Models\LacpProfile;
use App\Models\SwitchPort;
use App\Services\LagInterfaceCentralVerifier;

test('diffExpectedAgainstActual treats port-list order as equal', function () {
    $verifier = new LagInterfaceCentralVerifier;

    $expected = ['port-list' => ['1/1/2', '1/1/1']];
    $actual = ['port-list' => ['1/1/1', '1/1/2']];

    expect($verifier->diffExpectedAgainstActual($expected, $actual))->toBe([]);
});

test('diffExpectedAgainstActual treats port-list range notation as equal to expanded members', function () {
    $verifier = new LagInterfaceCentralVerifier;

    $expected = ['port-list' => ['1/1/1', '1/1/2']];
    $actual = ['port-list' => ['1/1/1-1/1/2']];

    expect($verifier->diffExpectedAgainstActual($expected, $actual))->toBe([]);
});

test('diffExpectedAgainstActual still fails when port-list members differ', function () {
    $verifier = new LagInterfaceCentralVerifier;

    $expected = ['port-list' => ['1/1/1', '1/1/2']];
    $actual = ['port-list' => ['1/1/1', '1/1/3']];

    $diff = $verifier->diffExpectedAgainstActual($expected, $actual);

    expect($diff)->toHaveCount(1)
        ->and($diff[0]['path'])->toBe('port-list');
});

test('diffExpectedAgainstActual coerces trunk-vlan-all true and string true', function () {
    $verifier = new LagInterfaceCentralVerifier;

    $expected = [
        'switchport' => ['trunk-vlan-all' => true],
    ];
    $actual = [
        'switchport' => ['trunk-vlan-all' => 'true'],
    ];

    expect($verifier->diffExpectedAgainstActual($expected, $actual))->toBe([]);
});

test('diffExpectedAgainstActual reports nested mismatches', function () {
    $verifier = new LagInterfaceCentralVerifier;

    $expected = ['lacp' => ['mode' => 'ACTIVE']];
    $actual = ['lacp' => ['mode' => 'PASSIVE']];

    $diff = $verifier->diffExpectedAgainstActual($expected, $actual);

    expect($diff)->toHaveCount(1)
        ->and($diff[0]['path'])->toBe('lacp.mode')
        ->and($diff[0]['expected'])->toBe('ACTIVE')
        ->and($diff[0]['actual'])->toBe('PASSIVE');
});

test('diffExpectedAgainstActual treats false expected and null actual as equal for booleans', function () {
    $verifier = new LagInterfaceCentralVerifier;

    $expected = [
        'stp' => ['bpdu-guard' => false],
    ];
    $actual = [
        'stp' => ['bpdu-guard' => null],
    ];

    expect($verifier->diffExpectedAgainstActual($expected, $actual))->toBe([]);
});

test('diffExpectedAgainstActual still fails when expected true and actual null', function () {
    $verifier = new LagInterfaceCentralVerifier;

    $expected = [
        'stp' => ['bpdu-guard' => true],
    ];
    $actual = [
        'stp' => ['bpdu-guard' => null],
    ];

    $diff = $verifier->diffExpectedAgainstActual($expected, $actual);

    expect($diff)->toHaveCount(1)
        ->and($diff[0]['path'])->toBe('stp.bpdu-guard');
});

test('buildExpectedPayload merges sw-profile patch body', function () {
    $switchPort = SwitchPort::factory()->create([
        'interface_mode' => 'TRUNK',
        'access_vlan' => null,
        'native_vlan' => 10,
        'trunk_vlan_all' => 'true',
        'trunk_vlan_ranges' => null,
    ]);
    $lacpProfile = LacpProfile::factory()->create([
        'mode' => 'ACTIVE',
        'rate' => 'SLOW',
        'port_list' => '1/1/1-1/1/2',
        'trunk_type' => 'LACP',
    ]);
    $deviceInterface = DeviceInterface::factory()->create([
        'interface' => '5',
        'switch_port_id' => $switchPort->id,
        'lacp_profile_id' => $lacpProfile->id,
        'interface_kind' => InterfaceKind::LAG,
        'sw_profile' => 'my-profile',
    ]);

    $verifier = new LagInterfaceCentralVerifier;
    $expected = $verifier->buildExpectedPayload($deviceInterface);

    expect($expected)->toHaveKey('sw-profile', 'my-profile')
        ->and($expected)->toHaveKey('lacp');
});

test('buildExpectedPayload for routed LAG includes routing and ipv4 without switchport', function () {
    $lacpProfile = LacpProfile::factory()->create([
        'mode' => 'ACTIVE',
        'rate' => 'SLOW',
        'port_list' => '1/1/1-1/1/2',
        'trunk_type' => 'LACP',
    ]);
    $deviceInterface = DeviceInterface::factory()->create([
        'interface' => '11',
        'lacp_profile_id' => $lacpProfile->id,
        'interface_kind' => InterfaceKind::LAG,
        'description' => 'Routed LAG',
        'ip_address' => '10.255.0.1/30',
        'vrf_forwarding' => 'my-vrf',
        'routing' => true,
    ]);

    $verifier = new LagInterfaceCentralVerifier;
    $expected = $verifier->buildExpectedPayload($deviceInterface);

    expect($expected)->toHaveKey('routing', true)
        ->toHaveKey('ipv4', ['address' => '10.255.0.1/30'])
        ->toHaveKey('vrf-forwarding', 'my-vrf')
        ->toHaveKey('port-list')
        ->toHaveKey('lacp')
        ->not->toHaveKey('switchport')
        ->not->toHaveKey('sw-profile');
});
