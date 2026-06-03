<?php

use App\InterfaceKind;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\LacpProfile;
use App\Models\SwitchPort;
use App\Services\EthernetInterfaceCentralVerifier;

test('diffExpectedAgainstActual coerces trunk-vlan-all true and string true', function () {
    $verifier = new EthernetInterfaceCentralVerifier;

    $expected = [
        'switchport' => ['trunk-vlan-all' => true],
    ];
    $actual = [
        'switchport' => ['trunk-vlan-all' => 'true'],
    ];

    expect($verifier->diffExpectedAgainstActual($expected, $actual))->toBe([]);
});

test('diffExpectedAgainstActual reports nested mismatches', function () {
    $verifier = new EthernetInterfaceCentralVerifier;

    $expected = ['switchport' => ['access-vlan' => 100]];
    $actual = ['switchport' => ['access-vlan' => 200]];

    $diff = $verifier->diffExpectedAgainstActual($expected, $actual);

    expect($diff)->toHaveCount(1)
        ->and($diff[0]['path'])->toBe('switchport.access-vlan')
        ->and($diff[0]['expected'])->toBe(100)
        ->and($diff[0]['actual'])->toBe(200);
});

test('buildExpectedPayload returns minimal body for LAG member interfaces', function () {
    $device = Device::factory()->create();
    $lacpProfile = LacpProfile::factory()->create([
        'port_list' => '1/1/1&1/1/2',
    ]);
    DeviceInterface::factory()->create([
        'device_id' => $device->id,
        'interface' => 'lag10',
        'lacp_profile_id' => $lacpProfile->id,
        'interface_kind' => InterfaceKind::LAG,
    ]);
    $memberInterface = DeviceInterface::factory()->create([
        'device_id' => $device->id,
        'interface' => '1/1/1',
        'description' => 'Member link',
        'interface_kind' => InterfaceKind::ETHERNET,
    ]);

    $verifier = new EthernetInterfaceCentralVerifier;
    $expected = $verifier->buildExpectedPayload($memberInterface);

    expect($expected)->toEqual([
        'name' => '1/1/1',
        'description' => 'Member link',
    ]);
});

test('buildExpectedPayload uses full switchport body for standalone ethernet', function () {
    $switchPort = SwitchPort::factory()->create([
        'interface_mode' => 'ACCESS',
        'access_vlan' => 50,
        'native_vlan' => null,
        'trunk_vlan_all' => null,
        'trunk_vlan_ranges' => null,
    ]);
    $deviceInterface = DeviceInterface::factory()->create([
        'interface' => '1/1/5',
        'switch_port_id' => $switchPort->id,
        'interface_kind' => InterfaceKind::ETHERNET,
        'shutdown_on_split' => false,
    ]);

    $verifier = new EthernetInterfaceCentralVerifier;
    $expected = $verifier->buildExpectedPayload($deviceInterface);

    expect($expected)->toHaveKey('name', '1/1/5')
        ->and($expected)->toHaveKey('switchport')
        ->and($expected['switchport']['access-vlan'])->toBe(50);
});

test('buildExpectedPayload uses routed body for ethernet with ip_address', function () {
    $deviceInterface = DeviceInterface::factory()->create([
        'interface' => '1/1/53',
        'ip_address' => '10.255.0.1/30',
        'description' => 'Routed uplink',
        'vrf_forwarding' => 'my-vrf',
        'interface_kind' => InterfaceKind::ETHERNET,
    ]);

    $verifier = new EthernetInterfaceCentralVerifier;
    $expected = $verifier->buildExpectedPayload($deviceInterface);

    expect($expected)->toEqual([
        'name' => '1/1/53',
        'description' => 'Routed uplink',
        'routing' => true,
        'ipv4' => [
            'address' => '10.255.0.1/30',
            'vrf-forwarding' => 'my-vrf',
        ],
    ]);
});
