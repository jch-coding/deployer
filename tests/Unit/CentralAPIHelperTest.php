<?php

use App\Helper\CentralAPIHelper;
use App\Models\DeviceInterface;
use App\Models\LacpProfile;
use App\Models\StpProfile;
use App\Models\SwitchPort;

test('build_switchport_from_device_interface returns a switchport array with subarrays that are not empty', function () {
    $this->withoutExceptionHandling();
    $switch_port = SwitchPort::factory()->create(['access_vlan' => 10, 'native_vlan' => null, 'trunk_vlan_all' => null, 'trunk_vlan_ranges' => null, 'interface_mode' => 'ACCESS']);
    $deviceInterface = DeviceInterface::factory()->create(['switch_port_id' => $switch_port->id]);

    $expected = [
        'interface' => $deviceInterface->interface,
        'switchport' => [
            'access-vlan' => $switch_port->access_vlan,
            'interface-mode' => $switch_port->interface_mode,
            'native-vlan' => $switch_port->trunk_native_vlan,
            'trunk-vlan-all' => $switch_port->trunk_vlan_all,
            'trunk-vlan-ranges' => $switch_port->trunk_vlan_ranges,
        ],
    ];

    $result = CentralAPIHelper::build_switchport_from_device_interface($deviceInterface);

    expect($result)->toEqual($expected);
});

test('build_switchport_from_device_interface returns a switchport array for a port that is part of a portchannel', function () {
    $deviceInterface = DeviceInterface::factory()->create(['portchannel_lag' => '10']);
    $expected = [
        'interface' => $deviceInterface->interface,
        'portchannel-lag' => '10'
    ];
    $actual =  CentralAPIHelper::build_switchport_from_device_interface($deviceInterface);
    expect($actual)->toEqual($expected);
});

test('it processes portchannel interfaces', function () {
    $lacp_profile = LacpProfile::factory()->create([
        'mode' => 'ACTIVE',
        'rate' => 'SLOW',
        'port_list' => '1/1/1-1/1/2&2/1/1-2/1/2',
        'trunk_type' => 'LACP'
    ]);
    $switch_port = SwitchPort::factory()->create([
        'interface_mode' => 'TRUNK',
        'access_vlan' => null,
        'native_vlan' => 10,
        'trunk_vlan_all' => 'true',
        'trunk_vlan_ranges' => null,
    ]);
    $deviceInterface = DeviceInterface::factory()
        ->create([
        'interface' => '1',
            'switch_port_id' => $switch_port->id,
            'lacp_profile_id' => $lacp_profile->id,
    ]);
    $expected = [
        'name' => $deviceInterface->interface,
        'switchport' => [
            'access-vlan' => null,
            'interface-mode' => 'TRUNK',
            'native-vlan' => 10,
            'trunk-vlan-all' => true,
            'trunk-vlan-ranges' => null,
        ],
        'lacp' => [
            'mode' => 'ACTIVE',
            'rate' => 'SLOW',
            'port-list' => ['1/1/1','1/1/2','2/1/1','2/1/2'],
        ],
        'trunk-type' => 'LACP',
        'portchannel-lag' => null
    ];
    $actual = CentralAPIHelper::build_portchannel_from_device_interface($deviceInterface);
    expect($actual)->toEqual($expected);
});

test('the categorize_interfaces function takes a list of device interfaces and returns an array categorized by ethernet, vlan and portchannel sub-arrays', function () {
    $lacp_profile = LacpProfile::factory()->create([
        'mode' => 'ACTIVE',
        'rate' => 'SLOW',
        'port_list' => '1/1/1-1/1/2&2/1/1-2/1/2',
        'trunk_type' => 'LACP'
    ]);
    $switch_port = SwitchPort::factory()->create([
        'interface_mode' => 'TRUNK',
        'access_vlan' => null,
        'native_vlan' => 10,
        'trunk_vlan_all' => 'true',
        'trunk_vlan_ranges' => null,
    ]);
    $devInt1 = DeviceInterface::factory()->create(['interface' => '1', 'switch_port_id' => $switch_port->id, 'lacp_profile_id' => $lacp_profile->id]);
    $devInt2 = DeviceInterface::factory()->create(['interface' => '1/1/3', 'switch_port_id' => $switch_port->id]);
    $expected = [
        'ethernet_interfaces' => [
            array_merge($devInt2->toArray(),['lacp_profile' => null]),
        ],
        'portchannel_interfaces' => [
            $devInt1->load('lacp_profile')->toArray(),
        ],
    ];
    $actual = CentralAPIHelper::categorize_device_interfaces([$devInt1, $devInt2]);
    expect($actual)->toEqual($expected);
});
