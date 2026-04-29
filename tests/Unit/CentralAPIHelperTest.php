<?php

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\LacpProfile;
use App\Models\StpProfile;
use App\Models\SwitchPort;

test('build_switchport_from_device_interface returns a switchport array with subarrays that are not empty', function () {
    $this->withoutExceptionHandling();
    $switch_port = SwitchPort::factory()->create(['access_vlan' => 10, 'native_vlan' => null, 'trunk_vlan_all' => null, 'trunk_vlan_ranges' => null, 'interface_mode' => 'ACCESS']);
    $deviceInterface = DeviceInterface::factory()->create(['switch_port_id' => $switch_port->id, 'description' => null]);

    $expected = [
        'name' => $deviceInterface->interface,
        'switchport' => [
            'access-vlan' => $switch_port->access_vlan,
            'interface-mode' => $switch_port->interface_mode,
            'native-vlan' => $switch_port->native_vlan,
            'trunk-vlan-all' => $switch_port->trunk_vlan_all,
            'trunk-vlan-ranges' => $switch_port->trunk_vlan_ranges,
        ],
    ];

    $result = CentralAPIHelper::build_switchport_from_device_interface($deviceInterface);

    expect($result)->toEqual($expected);
});

test('build_switchport_from_device_interface returns a switchport array for a port that is part of a portchannel', function () {
    $deviceInterface = DeviceInterface::factory()->create(['description' => null, 'portchannel_lag' => '10']);
    $expected = [
        'name' => $deviceInterface->interface,
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
            'description' => null,
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
        ],
        'trunk-type' => 'LACP',
        'port-list' => ['1/1/1','1/1/2','2/1/1','2/1/2'],
        'enable' => true,
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

test('the conductor serial number is used to find the stack_id of a stack in mrt', function () {
    $response_json = [
        'items' =>   [
            [
            "deployment" => "Stack",
            "firmwareVersion" => "FL.10.15.1010",
            "publicIp" => "64.73.160.102",
            "id" => "SG20KN309L",
            "stackId" => "41bbc334-749b-4924-bf91-c4377a323536",
            "stackMemberId" => 2,
            "switchType" => "cx",
            "uptimeInMillis" => 27381482771,
            "lastSeenAt" => 0,
            "ipv4" => "10.89.52.15",
            "siteName" => "CDW LAB",
            "ipv6" => null,
            "switchRole" => "Standby",
            "switchTrends" => [
                [
                    "cpuUtilization" => 6,
                    "memoryUtilization" => 11,
                    "systemTemperature" => 24,
                    "poeAvailable" => 0,
                    "poeConsumption" => 0,
                    "powerConsumption" => 49.330001831055,
                    "totalPowerConsumption" => 49.33,
                    "upLinkPorts" => null,
                    "usage" => 24898.05,
                ],
            ],
            "type" => "network-monitoring/switch-monitoring",
            "siteId" => "266035542831",
            "jNumber" => "JL664A",
            "macAddress" => "0c:97:5f:bd:76:80",
            "serialNumber" => "SG20KN309L",
            "model" => "CX-6300M",
            "deviceName" => "vht2509-as6300m",
            "status" => "Online",
        ],
        [
            "deployment" => "Stack",
            "firmwareVersion" => "FL.10.15.1010",
            "publicIp" => "64.73.160.102",
            "id" => "SG20KN309V",
            "stackId" => "41bbc334-749b-4924-bf91-c4377a323536",
            "stackMemberId" => 1,
            "switchType" => "cx",
            "uptimeInMillis" => 27381482771,
            "lastSeenAt" => 0,
            "ipv4" => "10.89.52.15",
            "siteName" => "CDW LAB",
            "ipv6" => null,
            "switchRole" => "Conductor",
            "switchTrends" => [
                [
                    "cpuUtilization" => 12,
                    "memoryUtilization" => 21,
                    "systemTemperature" => 23.5,
                    "poeAvailable" => 0,
                    "poeConsumption" => 0,
                    "powerConsumption" => 49.029998779297,
                    "totalPowerConsumption" => 49.03,
                    "upLinkPorts" => null,
                    "usage" => 67234.71,
                ],
            ],
            "type" => "network-monitoring/switch-monitoring",
            "siteId" => "266035542831",
            "jNumber" => "JL664A",
            "macAddress" => "0c:97:5f:bd:c4:80",
            "serialNumber" => "SG20KN309V",
            "model" => "CX-6300M",
            "deviceName" => "vht2509-as6300m",
            "status" => "Online",
        ],
            ],
        'count' => 2,
        'total' => 2,
        'next' => null,
    ];

    $device = Device::factory()->create([
        'serial' => 'SG20KN309V',
        'name' => "vht2507-as6300m-stk06",
        'device_function' => 'ACCESS_SWITCH',
        'scope_id' => null,
        'stack_id' => null,
    ]);

    $stack_id = CentralAPIHelper::getStackId($device, $response_json['items']);
    expect($stack_id['stackId'])->toEqual('41bbc334-749b-4924-bf91-c4377a323536');
});
