<?php

use App\Helper\CentralAPIHelper;
use App\Models\DeviceInterface;
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
