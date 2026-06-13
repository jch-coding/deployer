<?php

use App\BaseURL;
use App\Helper\CentralAPIHelper;
use App\InterfaceKind;
use App\Models\Client;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\LacpProfile;
use App\Models\Site;
use App\Models\SwitchPort;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('build_switchport_from_device_interface returns a switchport array with subarrays that are not empty', function () {
    $this->withoutExceptionHandling();
    $switch_port = SwitchPort::factory()->create(['access_vlan' => 10, 'native_vlan' => null, 'trunk_vlan_all' => null, 'trunk_vlan_ranges' => null, 'interface_mode' => 'ACCESS']);
    $deviceInterface = DeviceInterface::factory()->create(['switch_port_id' => $switch_port->id, 'description' => null]);

    $expected = [
        'name' => $deviceInterface->interface,
        'vsx' => [
            'shutdown-on-split' => false,
        ],
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
        'vsx' => [
            'shutdown-on-split' => false,
        ],
        'portchannel-lag' => '10',
    ];
    $actual = CentralAPIHelper::build_switchport_from_device_interface($deviceInterface);
    expect($actual)->toEqual($expected);
});

test('it processes portchannel interfaces', function () {
    $lacp_profile = LacpProfile::factory()->create([
        'mode' => 'ACTIVE',
        'rate' => 'SLOW',
        'port_list' => '1/1/1-1/1/2&2/1/1-2/1/2',
        'trunk_type' => 'LACP',
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
            'interface_kind' => InterfaceKind::LAG,
        ]);
    $expected = [
        'name' => $deviceInterface->interface,
        'vsx' => [
            'shutdown-on-split' => false,
        ],
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
        'port-list' => ['1/1/1', '1/1/2', '2/1/1', '2/1/2'],
        'enable' => true,
    ];
    $actual = CentralAPIHelper::build_portchannel_from_device_interface($deviceInterface);
    expect($actual)->toEqual($expected);
});

test('build_portchannel_from_device_interface includes switchport when creating LAG and interface has a description', function () {
    $lacp_profile = LacpProfile::factory()->create([
        'mode' => 'ACTIVE',
        'rate' => 'SLOW',
        'port_list' => '1/1/1-1/1/2&2/1/1-2/1/2',
        'trunk_type' => 'LACP',
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
            'description' => 'LAG uplink to core',
            'interface_kind' => InterfaceKind::LAG,
        ]);

    $expected = [
        'name' => $deviceInterface->interface,
        'vsx' => [
            'shutdown-on-split' => false,
        ],
        'description' => 'LAG uplink to core',
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
        'port-list' => ['1/1/1', '1/1/2', '2/1/1', '2/1/2'],
        'enable' => true,
    ];

    $actual = CentralAPIHelper::build_portchannel_from_device_interface($deviceInterface, true);

    expect($actual)->toEqual($expected);
});

test('build_portchannel_from_device_interface includes lacp for MULTI_CHASSIS trunk type', function () {
    $lacp_profile = LacpProfile::factory()->create([
        'mode' => 'ACTIVE',
        'rate' => 'SLOW',
        'port_list' => '1/1/1&1/1/2',
        'trunk_type' => 'MULTI_CHASSIS',
    ]);
    $switch_port = SwitchPort::factory()->create([
        'interface_mode' => 'TRUNK',
        'access_vlan' => null,
        'native_vlan' => 10,
        'trunk_vlan_all' => 'true',
        'trunk_vlan_ranges' => null,
    ]);
    $deviceInterface = DeviceInterface::factory()->create([
        'interface' => '10',
        'switch_port_id' => $switch_port->id,
        'lacp_profile_id' => $lacp_profile->id,
        'interface_kind' => InterfaceKind::LAG,
    ]);

    $actual = CentralAPIHelper::build_portchannel_from_device_interface($deviceInterface);

    expect($actual)
        ->toHaveKey('trunk-type', 'MULTI_CHASSIS')
        ->toHaveKey('lacp')
        ->and($actual['lacp'])->toBe([
            'mode' => 'ACTIVE',
            'rate' => 'SLOW',
        ]);
});

test('build_portchannel_from_device_interface post body for routed LAG omits switchport and includes lacp', function () {
    $lacp_profile = LacpProfile::factory()->create([
        'mode' => 'ACTIVE',
        'rate' => 'SLOW',
        'port_list' => '1/1/1-1/1/2',
        'trunk_type' => 'LACP',
    ]);
    $switch_port = SwitchPort::factory()->create([
        'interface_mode' => 'TRUNK',
        'access_vlan' => null,
        'native_vlan' => 10,
        'trunk_vlan_all' => 'true',
        'trunk_vlan_ranges' => null,
    ]);
    $deviceInterface = DeviceInterface::factory()->create([
        'interface' => '11',
        'switch_port_id' => $switch_port->id,
        'lacp_profile_id' => $lacp_profile->id,
        'interface_kind' => InterfaceKind::LAG,
        'description' => 'Routed LAG uplink',
        'ip_address' => '10.255.0.1/30',
        'vrf_forwarding' => 'my-vrf',
        'routing' => true,
    ]);

    $actual = CentralAPIHelper::build_portchannel_from_device_interface($deviceInterface, true);

    expect($actual)->toEqual([
        'name' => '11',
        'description' => 'Routed LAG uplink',
        'ipv4' => ['address' => '10.255.0.1/30'],
        'vrf-forwarding' => 'my-vrf',
        'trunk-type' => 'LACP',
        'port-list' => ['1/1/1', '1/1/2'],
        'enable' => true,
        'lacp' => ['mode' => 'ACTIVE', 'rate' => 'SLOW'],
    ])->not->toHaveKey('switchport')
        ->not->toHaveKey('stp')
        ->not->toHaveKey('vsx')
        ->not->toHaveKey('routing');
});

test('build_portchannel_from_device_interface patch body for routed LAG sets routing true', function () {
    $lacp_profile = LacpProfile::factory()->create([
        'mode' => 'ACTIVE',
        'rate' => 'SLOW',
        'port_list' => '1/1/1-1/1/2',
        'trunk_type' => 'LACP',
    ]);
    $deviceInterface = DeviceInterface::factory()->create([
        'interface' => '11',
        'lacp_profile_id' => $lacp_profile->id,
        'interface_kind' => InterfaceKind::LAG,
        'ip_address' => '10.255.0.1/30',
        'vrf_forwarding' => 'default',
        'routing' => true,
    ]);

    $actual = CentralAPIHelper::build_portchannel_from_device_interface($deviceInterface);

    expect($actual)->toEqual([
        'routing' => true,
        'ipv4' => ['address' => '10.255.0.1/30'],
    ])->not->toHaveKey('vrf-forwarding');
});

test('build_portchannel_from_device_interface omits lacp for non-lacp trunk types', function () {
    $nonLacpTrunkTypes = ['TRUNK', 'DT_TRUNK', 'MULTI_CHASSIS_STATIC'];

    foreach ($nonLacpTrunkTypes as $trunkType) {
        $lacp_profile = LacpProfile::factory()->create([
            'mode' => 'ACTIVE',
            'rate' => 'SLOW',
            'port_list' => '1/1/1&1/1/2',
            'trunk_type' => $trunkType,
        ]);
        $switch_port = SwitchPort::factory()->create([
            'interface_mode' => 'TRUNK',
            'access_vlan' => null,
            'native_vlan' => 10,
            'trunk_vlan_all' => 'true',
            'trunk_vlan_ranges' => null,
        ]);
        $deviceInterface = DeviceInterface::factory()->create([
            'interface' => "20-{$trunkType}",
            'switch_port_id' => $switch_port->id,
            'lacp_profile_id' => $lacp_profile->id,
            'interface_kind' => InterfaceKind::LAG,
        ]);

        $actual = CentralAPIHelper::build_portchannel_from_device_interface($deviceInterface);

        expect($actual)
            ->toHaveKey('trunk-type', $trunkType)
            ->toHaveKey('port-list')
            ->toHaveKey('enable', true)
            ->not->toHaveKey('lacp');
    }
});

test('build_ethernet_interface_patch_body returns routed payload when ip_address is set', function () {
    $deviceInterface = DeviceInterface::factory()->create([
        'interface' => '1/1/10',
        'ip_address' => '10.20.30.1/24',
        'description' => 'Routed uplink',
        'interface_kind' => InterfaceKind::ETHERNET,
        'vrf_forwarding' => 'default',
    ]);

    $actual = CentralAPIHelper::build_ethernet_interface_patch_body($deviceInterface);

    expect($actual)->toEqual([
        'name' => '1/1/10',
        'description' => 'Routed uplink',
        'routing' => true,
        'ipv4' => [
            'address' => '10.20.30.1/24',
            'vrf-forwarding' => 'default',
        ],
    ])->not->toHaveKeys(['switchport', 'stp', 'vsx', 'sw-profile']);
});

test('build_ethernet_interface_patch_body omits vrf-forwarding when vrf_forwarding is empty', function () {
    $deviceInterface = DeviceInterface::factory()->create([
        'interface' => '1/1/11',
        'ip_address' => '10.20.30.2/24',
        'description' => null,
        'interface_kind' => InterfaceKind::ETHERNET,
        'vrf_forwarding' => '',
    ]);

    $actual = CentralAPIHelper::build_ethernet_interface_patch_body($deviceInterface);

    expect($actual)->toEqual([
        'name' => '1/1/11',
        'routing' => true,
        'ipv4' => [
            'address' => '10.20.30.2/24',
        ],
    ]);
});

test('build_ethernet_interface_patch_body uses routed payload for LAG member with ip_address', function () {
    $device = Device::factory()->create();
    $lacp_profile = LacpProfile::factory()->create([
        'port_list' => '1/1/1&1/1/2',
    ]);
    DeviceInterface::factory()->create([
        'device_id' => $device->id,
        'interface' => 'lag10',
        'lacp_profile_id' => $lacp_profile->id,
        'interface_kind' => InterfaceKind::LAG,
    ]);
    $member_interface = DeviceInterface::factory()->create([
        'device_id' => $device->id,
        'interface' => '1/1/1',
        'ip_address' => '10.0.0.1/30',
        'interface_kind' => InterfaceKind::ETHERNET,
    ]);

    $actual = CentralAPIHelper::build_ethernet_interface_patch_body($member_interface);

    expect($actual)->toHaveKey('routing', true)
        ->toHaveKey('ipv4')
        ->not->toHaveKey('switchport');
});

test('build_ethernet_interface_patch_body returns only name and description for LAG members', function () {
    $device = Device::factory()->create();
    $lacp_profile = LacpProfile::factory()->create([
        'port_list' => '1/1/1&1/1/2',
    ]);
    DeviceInterface::factory()->create([
        'device_id' => $device->id,
        'interface' => 'lag10',
        'lacp_profile_id' => $lacp_profile->id,
        'interface_kind' => InterfaceKind::LAG,
    ]);
    $member_interface = DeviceInterface::factory()->create([
        'device_id' => $device->id,
        'interface' => '1/1/1',
        'description' => 'Member link description',
        'shutdown_on_split' => true,
        'interface_kind' => InterfaceKind::ETHERNET,
    ]);

    $actual = CentralAPIHelper::build_ethernet_interface_patch_body($member_interface);

    expect($actual)->toEqual([
        'name' => '1/1/1',
        'description' => 'Member link description',
    ]);
});

test('build_ethernet_interface_patch_body returns only name for LAG members without description', function () {
    $device = Device::factory()->create();
    $lacp_profile = LacpProfile::factory()->create([
        'port_list' => '1/1/1&1/1/2',
    ]);
    DeviceInterface::factory()->create([
        'device_id' => $device->id,
        'interface' => 'lag20',
        'lacp_profile_id' => $lacp_profile->id,
        'interface_kind' => InterfaceKind::LAG,
    ]);
    $member_interface = DeviceInterface::factory()->create([
        'device_id' => $device->id,
        'interface' => '1/1/2',
        'description' => null,
        'shutdown_on_split' => true,
        'interface_kind' => InterfaceKind::ETHERNET,
    ]);

    $actual = CentralAPIHelper::build_ethernet_interface_patch_body($member_interface);

    expect($actual)->toEqual([
        'name' => '1/1/2',
    ]);
});

test('build_ethernet_interface_patch_body uses full switchport payload for non-member even with portchannel_lag set', function () {
    $device = Device::factory()->create();
    $lacp_profile = LacpProfile::factory()->create([
        'port_list' => '1/1/1&1/1/2',
    ]);
    DeviceInterface::factory()->create([
        'device_id' => $device->id,
        'interface' => 'lag30',
        'lacp_profile_id' => $lacp_profile->id,
        'interface_kind' => InterfaceKind::LAG,
    ]);
    $switch_port = SwitchPort::factory()->create([
        'interface_mode' => 'ACCESS',
        'access_vlan' => 100,
        'native_vlan' => null,
        'trunk_vlan_all' => null,
        'trunk_vlan_ranges' => null,
    ]);
    $non_member_interface = DeviceInterface::factory()->create([
        'device_id' => $device->id,
        'interface' => '1/1/3',
        'description' => 'Non-member',
        'portchannel_lag' => '999',
        'switch_port_id' => $switch_port->id,
        'interface_kind' => InterfaceKind::ETHERNET,
    ]);

    $actual = CentralAPIHelper::build_ethernet_interface_patch_body($non_member_interface);
    $expected = CentralAPIHelper::build_switchport_from_device_interface($non_member_interface);

    expect($actual)->toEqual($expected);
    expect($actual)->toHaveKey('vsx');
});

test('the categorize_interfaces function takes a list of device interfaces and returns an array categorized by ethernet, vlan and portchannel sub-arrays', function () {
    $lacp_profile = LacpProfile::factory()->create([
        'mode' => 'ACTIVE',
        'rate' => 'SLOW',
        'port_list' => '1/1/1-1/1/2&2/1/1-2/1/2',
        'trunk_type' => 'LACP',
    ]);
    $switch_port = SwitchPort::factory()->create([
        'interface_mode' => 'TRUNK',
        'access_vlan' => null,
        'native_vlan' => 10,
        'trunk_vlan_all' => 'true',
        'trunk_vlan_ranges' => null,
    ]);
    $devInt1 = DeviceInterface::factory()->create(['interface' => '1', 'switch_port_id' => $switch_port->id, 'lacp_profile_id' => $lacp_profile->id, 'interface_kind' => InterfaceKind::LAG]);
    $devInt2 = DeviceInterface::factory()->create(['interface' => '1/1/3', 'switch_port_id' => $switch_port->id, 'interface_kind' => InterfaceKind::ETHERNET]);
    $expected = [
        'ethernet_interfaces' => [
            array_merge($devInt2->toArray(), ['lacp_profile' => null]),
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
        'items' => [
            [
                'deployment' => 'Stack',
                'firmwareVersion' => 'FL.10.15.1010',
                'publicIp' => '64.73.160.102',
                'id' => 'SG20KN309L',
                'stackId' => '41bbc334-749b-4924-bf91-c4377a323536',
                'stackMemberId' => 2,
                'switchType' => 'cx',
                'uptimeInMillis' => 27381482771,
                'lastSeenAt' => 0,
                'ipv4' => '10.89.52.15',
                'siteName' => 'CDW LAB',
                'ipv6' => null,
                'switchRole' => 'Standby',
                'switchTrends' => [
                    [
                        'cpuUtilization' => 6,
                        'memoryUtilization' => 11,
                        'systemTemperature' => 24,
                        'poeAvailable' => 0,
                        'poeConsumption' => 0,
                        'powerConsumption' => 49.330001831055,
                        'totalPowerConsumption' => 49.33,
                        'upLinkPorts' => null,
                        'usage' => 24898.05,
                    ],
                ],
                'type' => 'network-monitoring/switch-monitoring',
                'siteId' => '266035542831',
                'jNumber' => 'JL664A',
                'macAddress' => '0c:97:5f:bd:76:80',
                'serialNumber' => 'SG20KN309L',
                'model' => 'CX-6300M',
                'deviceName' => 'vht2509-as6300m',
                'status' => 'Online',
            ],
            [
                'deployment' => 'Stack',
                'firmwareVersion' => 'FL.10.15.1010',
                'publicIp' => '64.73.160.102',
                'id' => 'SG20KN309V',
                'stackId' => '41bbc334-749b-4924-bf91-c4377a323536',
                'stackMemberId' => 1,
                'switchType' => 'cx',
                'uptimeInMillis' => 27381482771,
                'lastSeenAt' => 0,
                'ipv4' => '10.89.52.15',
                'siteName' => 'CDW LAB',
                'ipv6' => null,
                'switchRole' => 'Conductor',
                'switchTrends' => [
                    [
                        'cpuUtilization' => 12,
                        'memoryUtilization' => 21,
                        'systemTemperature' => 23.5,
                        'poeAvailable' => 0,
                        'poeConsumption' => 0,
                        'powerConsumption' => 49.029998779297,
                        'totalPowerConsumption' => 49.03,
                        'upLinkPorts' => null,
                        'usage' => 67234.71,
                    ],
                ],
                'type' => 'network-monitoring/switch-monitoring',
                'siteId' => '266035542831',
                'jNumber' => 'JL664A',
                'macAddress' => '0c:97:5f:bd:c4:80',
                'serialNumber' => 'SG20KN309V',
                'model' => 'CX-6300M',
                'deviceName' => 'vht2509-as6300m',
                'status' => 'Online',
            ],
        ],
        'count' => 2,
        'total' => 2,
        'next' => null,
    ];

    $device = Device::factory()->create([
        'serial' => 'SG20KN309V',
        'name' => 'vht2507-as6300m-stk06',
        'device_function' => 'ACCESS_SWITCH',
        'scope_id' => null,
        'stack_id' => null,
    ]);

    $stack_id = CentralAPIHelper::getStackId($device, $response_json['items']);
    expect($stack_id['stackId'])->toEqual('41bbc334-749b-4924-bf91-c4377a323536');
});

function makeCentralApiHelperForSwitches(): CentralAPIHelper
{
    $client = Client::factory()->create([
        'expires_at' => now()->addHour(),
        'bearer_token' => 'test-bearer-token',
        'base_url' => BaseURL::US1->value,
    ]);

    return new CentralAPIHelper($client);
}

/**
 * @return array<string, mixed>
 */
function minimalSwitchItem(string $serial, string $stackId): array
{
    return [
        'serialNumber' => $serial,
        'stackId' => $stackId,
    ];
}

test('get_all_interface_portchannels paginates with limit and next until cursor is null', function () {
    Http::fake(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        expect($query['limit'] ?? null)->toBe('100')
            ->and($query['view-type'] ?? null)->toBe('LOCAL');

        if (! isset($query['next'])) {
            return Http::response([
                'items' => [['name' => '1', 'enable' => true]],
                'next' => '2',
            ], 200);
        }

        expect($query['next'])->toBe('2');

        return Http::response([
            'items' => [['name' => '10', 'enable' => true]],
            'next' => null,
        ], 200);
    });

    $helper = makeCentralApiHelperForSwitches();
    $result = $helper->get_all_interface_portchannels([
        'object-type' => 'LOCAL',
        'view-type' => 'LOCAL',
        'scope-id' => 'scope-abc',
        'device-function' => 'ACCESS_SWITCH',
    ]);

    expect($result)->not->toHaveKey('error')
        ->and($result)->toHaveCount(2)
        ->and($result[0]['name'])->toBe('1')
        ->and($result[1]['name'])->toBe('10');

    Http::assertSentCount(2);
});

test('get_all_switches paginates with limit and next until cursor is null', function () {
    $pageOneSerial = 'SERIAL-PAGE-ONE';
    $pageTwoSerial = 'SERIAL-PAGE-TWO';
    $stackId = 'stack-uuid-page-two';

    Http::fake(function (Request $request) use ($pageOneSerial, $pageTwoSerial, $stackId) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        expect($query['limit'] ?? null)->toBe('100');

        if (! isset($query['next'])) {
            return Http::response([
                'items' => [minimalSwitchItem($pageOneSerial, 'stack-page-one')],
                'count' => 1,
                'total' => 2,
                'next' => '2',
            ], 200);
        }

        expect($query['next'])->toBe('2');

        return Http::response([
            'items' => [minimalSwitchItem($pageTwoSerial, $stackId)],
            'count' => 1,
            'total' => 2,
            'next' => null,
        ], 200);
    });

    $helper = makeCentralApiHelperForSwitches();
    $result = $helper->get_all_switches();

    expect($result)->not->toHaveKey('error')
        ->and($result)->toHaveCount(2)
        ->and($result[0]['serialNumber'])->toBe($pageOneSerial)
        ->and($result[1]['serialNumber'])->toBe($pageTwoSerial);

    Http::assertSentCount(2);
});

test('get_all_devices paginates with limit and next until cursor is null', function () {
    Http::fake(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        expect($query['limit'] ?? null)->toBe('1000')
            ->and($query['filter'] ?? null)->toBe('siteId eq site-1');

        if (! isset($query['next'])) {
            return Http::response([
                'items' => [['serialNumber' => 'SN-1', 'siteId' => 'site-1']],
                'count' => 1,
                'total' => 2,
                'next' => '2',
            ], 200);
        }

        expect($query['next'])->toBe('2');

        return Http::response([
            'items' => [['serialNumber' => 'SN-2', 'siteId' => 'site-1']],
            'count' => 1,
            'total' => 2,
            'next' => null,
        ], 200);
    });

    $helper = makeCentralApiHelperForSwitches();
    $result = $helper->get_all_devices(['filter' => 'siteId eq site-1']);

    expect($result)->not->toHaveKey('error')
        ->and($result)->toHaveCount(2)
        ->and($result[0]['serialNumber'])->toBe('SN-1')
        ->and($result[1]['serialNumber'])->toBe('SN-2');

    Http::assertSentCount(2);
});

test('get_all_switches returns error when a page request fails', function () {
    Http::fake([
        '*network-monitoring/v1/switches*' => Http::response(['detail' => 'error'], 500),
    ]);

    $helper = makeCentralApiHelperForSwitches();

    expect($helper->get_all_switches())->toBe([
        'error' => 'failed to get switches from central.',
    ]);
});

test('getScopeIdFromCentral resolves stack_id from a later switches page', function () {
    $deviceSerial = 'SG20KN309V';
    $stackId = '41bbc334-749b-4924-bf91-c4377a323536';
    $scopeId = 'resolved-scope-id';

    Http::fake(function (Request $request) use ($deviceSerial, $stackId, $scopeId) {
        $url = $request->url();

        if (str_contains($url, 'network-monitoring/v1/switches')) {
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

            if (! isset($query['next'])) {
                return Http::response([
                    'items' => [minimalSwitchItem('OTHER-SERIAL', 'other-stack')],
                    'count' => 1,
                    'total' => 2,
                    'next' => '2',
                ], 200);
            }

            return Http::response([
                'items' => [minimalSwitchItem($deviceSerial, $stackId)],
                'count' => 1,
                'total' => 2,
                'next' => null,
            ], 200);
        }

        if (str_contains($url, 'network-config/v1/hierarchy')) {
            return Http::response([
                'items' => [
                    [
                        'hierarchy' => [
                            [
                                'childCount' => null,
                                'scopeType' => 'device',
                                'scopeId' => $scopeId,
                            ],
                        ],
                    ],
                ],
            ], 200);
        }

        return Http::response([], 404);
    });

    $device = Device::factory()->create([
        'serial' => $deviceSerial,
        'device_function' => 'ACCESS_SWITCH',
        'scope_id' => null,
        'stack_id' => null,
    ]);

    $helper = makeCentralApiHelperForSwitches();
    $result = $helper->getScopeIdFromCentral($device);

    expect($result)->not->toHaveKey('error')
        ->and($device->fresh()->stack_id)->toBe($stackId)
        ->and(collect($result)->first()['scopeId'] ?? null)->toBe($scopeId);

    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), 'network-monitoring/v1/switches')
            && str_contains($request->url(), 'next=2');
    });
});

test('localDeviceInterfaceQueryParameters uses LOCAL scope and string device function', function () {
    $device = Device::factory()->create([
        'scope_id' => 'scope-abc',
        'device_function' => 'ACCESS_SWITCH',
    ]);

    expect(CentralAPIHelper::localDeviceInterfaceQueryParameters($device))->toBe([
        'view-type' => 'LOCAL',
        'object-type' => 'LOCAL',
        'scope-id' => 'scope-abc',
        'device-function' => 'ACCESS_SWITCH',
    ]);
});

test('get_vlan_interfaces sends LOCAL device query parameters', function () {
    Http::fake(['*vlan-interfaces*' => Http::response(['items' => []], 200)]);

    $helper = makeCentralApiHelperForSwitches();
    $device = Device::factory()->for($helper->client)->create([
        'scope_id' => 'device-scope-123',
        'device_function' => 'ACCESS_SWITCH',
    ]);

    $helper->get_vlan_interfaces($device);

    Http::assertSent(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return str_contains($request->url(), 'vlan-interfaces')
            && ($query['view-type'] ?? '') === 'LOCAL'
            && ($query['object-type'] ?? '') === 'LOCAL'
            && ($query['scope-id'] ?? '') === 'device-scope-123'
            && ($query['device-function'] ?? '') === 'ACCESS_SWITCH';
    });
});

test('get_all_interface_portchannels uses LOCAL device query parameters', function () {
    Http::fake(['*portchannels*' => Http::response(['items' => []], 200)]);

    $helper = makeCentralApiHelperForSwitches();
    $device = Device::factory()->for($helper->client)->create([
        'scope_id' => 'device-scope-456',
        'device_function' => 'ACCESS_SWITCH',
    ]);

    $helper->get_all_interface_portchannels(
        CentralAPIHelper::localDeviceInterfaceQueryParameters($device)
    );

    Http::assertSent(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return str_contains($request->url(), 'portchannels')
            && ($query['view-type'] ?? '') === 'LOCAL'
            && ($query['object-type'] ?? '') === 'LOCAL'
            && ($query['scope-id'] ?? '') === 'device-scope-456'
            && ($query['device-function'] ?? '') === 'ACCESS_SWITCH';
    });
});

test('post_interface_portchannel omits lacp in request body for TRUNK trunk type', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $helper = makeCentralApiHelperForSwitches();
    $device = Device::factory()->for($helper->client)->create([
        'scope_id' => 'device-scope-post',
        'device_function' => 'ACCESS_SWITCH',
    ]);
    $switch_port = SwitchPort::factory()->create([
        'interface_mode' => 'TRUNK',
        'native_vlan' => 10,
    ]);
    $lacpProfile = LacpProfile::factory()->create([
        'mode' => 'ACTIVE',
        'rate' => 'SLOW',
        'port_list' => '1/1/1&1/1/2',
        'trunk_type' => 'TRUNK',
    ]);
    $deviceInterface = DeviceInterface::factory()->for($device)->create([
        'interface' => 'lag100',
        'switch_port_id' => $switch_port->id,
        'lacp_profile_id' => $lacpProfile->id,
        'interface_kind' => InterfaceKind::LAG,
    ]);

    $helper->post_interface_portchannel($deviceInterface);

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $request->method() === 'POST'
            && str_contains($request->url(), 'network-config/v1alpha1/portchannels/lag100')
            && ($body['trunk-type'] ?? null) === 'TRUNK'
            && ! array_key_exists('lacp', $body);
    });
});

test('patch_interface_portchannel includes lacp in request body for MULTI_CHASSIS trunk type', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $helper = makeCentralApiHelperForSwitches();
    $device = Device::factory()->for($helper->client)->create([
        'scope_id' => 'device-scope-patch',
        'device_function' => 'ACCESS_SWITCH',
    ]);
    $switch_port = SwitchPort::factory()->create([
        'interface_mode' => 'TRUNK',
        'native_vlan' => 20,
    ]);
    $lacpProfile = LacpProfile::factory()->create([
        'mode' => 'PASSIVE',
        'rate' => 'FAST',
        'port_list' => '1/1/3&1/1/4',
        'trunk_type' => 'MULTI_CHASSIS',
    ]);
    $deviceInterface = DeviceInterface::factory()->for($device)->create([
        'interface' => 'lag200',
        'switch_port_id' => $switch_port->id,
        'lacp_profile_id' => $lacpProfile->id,
        'interface_kind' => InterfaceKind::LAG,
    ]);

    $helper->patch_interface_portchannel($deviceInterface);

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $request->method() === 'PATCH'
            && str_contains($request->url(), 'network-config/v1alpha1/portchannels/lag200')
            && ($body['trunk-type'] ?? null) === 'MULTI_CHASSIS'
            && ($body['lacp']['mode'] ?? null) === 'PASSIVE'
            && ($body['lacp']['rate'] ?? null) === 'FAST';
    });
});

function makeRoutedEthernetInterfaceForVrfTests(CentralAPIHelper $helper, array $deviceOverrides = [], array $interfaceOverrides = []): DeviceInterface
{
    $siteAttrs = ['scope_id' => 'site-scope-1'];
    if (array_key_exists('site', $deviceOverrides)) {
        $siteAttrs = array_merge($siteAttrs, $deviceOverrides['site']);
        unset($deviceOverrides['site']);
    }

    $site = Site::factory()->for($helper->client)->create($siteAttrs);

    $device = Device::factory()->for($helper->client)->for($site)->create([
        'group' => 'MyGroup',
        'scope_id' => 'device-scope-1',
        'device_function' => 'ACCESS_SWITCH',
        ...$deviceOverrides,
    ]);

    return DeviceInterface::factory()->for($device)->create([
        'interface' => '1/1/53',
        'ip_address' => '10.255.0.1/30',
        'vrf_forwarding' => 'my-vrf',
        'routing' => true,
        'interface_kind' => InterfaceKind::ETHERNET,
        ...$interfaceOverrides,
    ]);
}

test('ensureVrfForRoutedInterface skips default vrf', function () {
    Http::fake();

    $helper = makeCentralApiHelperForSwitches();
    $deviceInterface = makeRoutedEthernetInterfaceForVrfTests($helper, [], [
        'vrf_forwarding' => 'default',
    ]);

    $result = $helper->ensureVrfForRoutedInterface($deviceInterface);

    expect($result)->toBe(['ok' => true]);
    Http::assertNothingSent();
});

test('ensureVrfForRoutedInterface does not post when vrf exists at latest non-empty scope', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'device-groups')) {
            return Http::response(['items' => [['scopeName' => 'MyGroup', 'scopeId' => 'group-scope-1']]], 200);
        }

        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
        $scopeId = $query['scope-id'] ?? null;

        if ($request->method() === 'GET' && str_contains($request->url(), '/vrfs')) {
            return match ($scopeId) {
                'group-scope-1' => Http::response(['vrf' => []], 200),
                'site-scope-1' => Http::response(['vrf' => [['name' => 'my-vrf']]], 200),
                'device-scope-1' => Http::response(['vrf' => []], 200),
                default => Http::response(['vrf' => []], 200),
            };
        }

        return Http::response([], 404);
    });

    $helper = makeCentralApiHelperForSwitches();
    $deviceInterface = makeRoutedEthernetInterfaceForVrfTests($helper);

    $result = $helper->ensureVrfForRoutedInterface($deviceInterface);

    expect($result)->toBe(['ok' => true]);
    Http::assertSentCount(4);
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), '/vrfs/'));
});

test('ensureVrfForRoutedInterface posts vrf at group scope when missing', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'device-groups')) {
            return Http::response(['items' => [['scopeName' => 'MyGroup', 'scopeId' => 'group-scope-1']]], 200);
        }

        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        if ($request->method() === 'GET' && str_contains($request->url(), '/vrfs')) {
            return Http::response(['vrf' => []], 200);
        }

        if ($request->method() === 'POST' && str_contains($request->url(), '/vrfs/my-vrf')) {
            expect($query['scope-id'] ?? null)->toBe('group-scope-1')
                ->and($query['view-type'] ?? null)->toBe('LOCAL')
                ->and($query['device-function'] ?? null)->toBe('ACCESS_SWITCH');

            return Http::response(['name' => 'my-vrf'], 200);
        }

        return Http::response([], 404);
    });

    $helper = makeCentralApiHelperForSwitches();
    $deviceInterface = makeRoutedEthernetInterfaceForVrfTests($helper);

    $result = $helper->ensureVrfForRoutedInterface($deviceInterface);

    expect($result)->toBe(['ok' => true, 'created' => true]);
});

test('ensureVrfForRoutedInterface posts vrf at site scope when device has no group', function () {
    Http::fake(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        if ($request->method() === 'GET' && str_contains($request->url(), '/vrfs')) {
            return Http::response(['vrf' => []], 200);
        }

        if ($request->method() === 'POST' && str_contains($request->url(), '/vrfs/my-vrf')) {
            expect($query['scope-id'] ?? null)->toBe('site-scope-1');

            return Http::response(['name' => 'my-vrf'], 200);
        }

        return Http::response([], 404);
    });

    $helper = makeCentralApiHelperForSwitches();
    $deviceInterface = makeRoutedEthernetInterfaceForVrfTests($helper, ['group' => null]);

    $result = $helper->ensureVrfForRoutedInterface($deviceInterface);

    expect($result)->toBe(['ok' => true, 'created' => true]);
});

test('ensureVrfForRoutedInterface returns error when group and site scope cannot be resolved for post', function () {
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET' && str_contains($request->url(), '/vrfs')) {
            return Http::response(['vrf' => []], 200);
        }

        return Http::response([], 404);
    });

    $helper = makeCentralApiHelperForSwitches();
    $deviceInterface = makeRoutedEthernetInterfaceForVrfTests($helper, [
        'group' => null,
        'site' => ['scope_id' => null],
    ], []);

    $result = $helper->ensureVrfForRoutedInterface($deviceInterface);

    expect($result)->toHaveKey('error');
});

test('ensureVrfForRoutedInterface checks vrf against latest non-empty scope response', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'device-groups')) {
            return Http::response(['items' => [['scopeName' => 'MyGroup', 'scopeId' => 'group-scope-1']]], 200);
        }

        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
        $scopeId = $query['scope-id'] ?? null;

        if ($request->method() === 'GET' && str_contains($request->url(), '/vrfs')) {
            return match ($scopeId) {
                'group-scope-1' => Http::response(['vrf' => []], 200),
                'site-scope-1' => Http::response(['vrf' => [['name' => 'other-vrf']]], 200),
                'device-scope-1' => Http::response(['vrf' => [['name' => 'my-vrf']]], 200),
                default => Http::response(['vrf' => []], 200),
            };
        }

        return Http::response([], 404);
    });

    $helper = makeCentralApiHelperForSwitches();
    $deviceInterface = makeRoutedEthernetInterfaceForVrfTests($helper);

    $result = $helper->ensureVrfForRoutedInterface($deviceInterface);

    expect($result)->toBe(['ok' => true]);
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), '/vrfs/'));
});

test('buildVsxKeepaliveLagPayload uses role-specific keepalive address', function () {
    $ports = ['1/1/47', '1/1/48'];

    $primary = CentralAPIHelper::buildVsxKeepaliveLagPayload($ports, App\VsxRole::VSX_PRIMARY);
    $secondary = CentralAPIHelper::buildVsxKeepaliveLagPayload($ports, App\VsxRole::VSX_SECONDARY);

    expect($primary['ipv4']['address'])->toBe('1.1.1.1/30')
        ->and($secondary['ipv4']['address'])->toBe('1.1.1.2/30')
        ->and($primary['vrf-forwarding'])->toBe('WHSE-VSX-Keep-Alive')
        ->and($primary['ipv4'])->not->toHaveKey('vrf-forwarding')
        ->and($primary['port-list'])->toBe($ports);
});

test('buildVsxIslLagPayload builds trunk inter-switch-link lag', function () {
    $payload = CentralAPIHelper::buildVsxIslLagPayload(['1/1/45', '1/1/46']);

    expect($payload)->toMatchArray([
        'name' => '256',
        'switchport' => [
            'interface-mode' => 'TRUNK',
            'native-vlan' => 1,
            'trunk-vlan-all' => true,
        ],
        'trunk-type' => 'LACP',
        'lacp' => ['mode' => 'ACTIVE'],
        'port-list' => ['1/1/45', '1/1/46'],
        'enable' => true,
    ]);
});

test('buildVsxLagMemberPortDescription formats peer link and keepalive labels', function () {
    expect(CentralAPIHelper::buildVsxLagMemberPortDescription('Peer-A', '1/1/45', '[VSX-Peer-Link]'))
        ->toBe('Peer-A - 1/1/45 [VSX-Peer-Link]')
        ->and(CentralAPIHelper::buildVsxLagMemberPortDescription('Peer-B', '1/1/47', '[VSX Keep-Alive]'))
        ->toBe('Peer-B - 1/1/47 [VSX Keep-Alive]');
});

test('getVsxPortSelections returns core switch default ports', function () {
    $device = Device::factory()->create(['name' => 'NY1-MDF-CORE-SW1']);

    [$islPorts, $keepalivePorts] = CentralAPIHelper::getVsxPortSelections($device);

    expect($islPorts)->toBe(['1/1/53', '1/1/54'])
        ->and($keepalivePorts)->toBe(['1/1/47', '1/1/48']);
});

test('getVsxPortSelections returns svr switch default ports', function () {
    $device = Device::factory()->create(['name' => 'NY1-MDF-SVR-SW1']);

    [$islPorts, $keepalivePorts] = CentralAPIHelper::getVsxPortSelections($device);

    expect($islPorts)->toBe(['1/1/21', '1/1/22'])
        ->and($keepalivePorts)->toBe(['1/1/23', '1/1/24']);
});

test('getVsxPortSelections uses explicit port overrides when set', function () {
    $device = Device::factory()->create([
        'name' => 'Primary-SW',
        'vsx_isl_ports' => '1/1/53-1/1/54',
        'vsx_keepalive_ports' => '1/1/47&1/1/48',
    ]);

    [$islPorts, $keepalivePorts] = CentralAPIHelper::getVsxPortSelections($device);

    expect($islPorts)->toBe(['1/1/53', '1/1/54'])
        ->and($keepalivePorts)->toBe(['1/1/47', '1/1/48']);
});

test('getVsxPortSelections requires both override columns when one is set', function () {
    $device = Device::factory()->create([
        'name' => 'Primary-SW',
        'vsx_isl_ports' => '1/1/53-1/1/54',
    ]);

    $result = CentralAPIHelper::getVsxPortSelections($device);

    expect($result)->toHaveKey('error');
});

test('getVsxPortSelections errors when name is ambiguous and overrides missing', function () {
    $result = CentralAPIHelper::getVsxPortSelections(Device::factory()->create(['name' => 'Primary-SW']));

    expect($result)->toBe(['error' => 'Cannot determine VSX LAG ports for Primary-SW: device name must contain CORE or SVR, or set vsx_isl_ports and vsx_keepalive_ports.']);
});

test('vsxPortchannelMatchesExpected compares port-list order independently', function () {
    $expected = CentralAPIHelper::buildVsxIslLagPayload(['1/1/45', '1/1/46']);
    $actual = CentralAPIHelper::buildVsxIslLagPayload(['1/1/46', '1/1/45']);

    expect(CentralAPIHelper::vsxPortchannelMatchesExpected($expected, $actual))->toBe([]);
});

test('vsxPortchannelMatchesExpected reports mismatched keepalive ip', function () {
    $expected = CentralAPIHelper::buildVsxKeepaliveLagPayload(['1/1/47', '1/1/48'], App\VsxRole::VSX_PRIMARY);
    $actual = $expected;
    $actual['ipv4']['address'] = '1.1.1.9/30';

    $diffs = CentralAPIHelper::vsxPortchannelMatchesExpected($expected, $actual);

    expect($diffs)->not->toBeEmpty()
        ->and($diffs[0]['path'])->toBe('ipv4.address');
});

test('vsxPortchannelMatchesExpected accepts central keepalive portchannel shape', function () {
    $expected = CentralAPIHelper::buildVsxKeepaliveLagPayload(['1/1/23', '1/1/24'], App\VsxRole::VSX_SECONDARY);
    $actual = [
        'name' => '255',
        'port-list' => ['1/1/24', '1/1/23'],
        'enable' => true,
        'description' => 'NY1-MDF-SVR-SW1 [VSX Keep-Alive]',
        'routing' => true,
        'vrf-forwarding' => 'WHSE-VSX-Keep-Alive',
        'lacp' => [
            'mode' => 'ACTIVE',
            'fallback-static' => false,
            'rate' => 'SLOW',
        ],
        'ip' => ['mtu' => 1500],
        'ipv4' => ['address' => '1.1.1.2/30'],
        'ip-directed-broadcast-enable' => false,
        'vsx' => ['shutdown-on-split' => false],
        'trunk-type' => 'LACP',
        'metadata' => [
            'count_objects_in_module' => [
                'LOCAL' => 2,
                'SHARED' => 0,
                'ANY' => 2,
            ],
        ],
    ];

    expect(CentralAPIHelper::vsxPortchannelMatchesExpected($expected, $actual))->toBe([]);
});

test('buildVsxProfilePayload builds paired keepalive ip mapping', function () {
    $helper = makeCentralApiHelperForSwitches();
    $site = Site::factory()->for($helper->client)->create(['scope_id' => 'site-scope-1']);

    $primary = Device::factory()->for($helper->client)->for($site)->create([
        'name' => 'Primary-SW',
        'serial' => 'PRIMARY123',
        'vsx_profile' => 'vsx-pair-1',
        'vsx_role' => 'VSX_PRIMARY',
        'vsx_system_mac' => '02:00:00:00:00:01',
    ]);
    $secondary = Device::factory()->for($helper->client)->for($site)->create([
        'name' => 'Secondary-SW',
        'serial' => 'SECONDARY123',
        'vsx_profile' => 'vsx-pair-1',
        'vsx_role' => 'VSX_SECONDARY',
        'vsx_system_mac' => '02:00:00:00:00:01',
    ]);

    $payload = CentralAPIHelper::buildVsxProfilePayload($primary, $secondary);

    expect($payload['name'])->toBe('vsx-pair-1')
        ->and($payload['peer1']['keepalive-device']['source-ip'])->toBe('1.1.1.1')
        ->and($payload['peer1']['keepalive-device']['peer-ip'])->toBe('1.1.1.2')
        ->and($payload['peer2']['keepalive-device']['source-ip'])->toBe('1.1.1.2')
        ->and($payload['peer2']['keepalive-device']['peer-ip'])->toBe('1.1.1.1');
});

test('post_vsx_profile sends site scope and service persona query parameters', function () {
    Http::fake(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        expect($request->method())->toBe('POST')
            ->and($query)->toMatchArray([
                'object-type' => 'LOCAL',
                'scope-id' => 'site-scope-99',
                'device-function' => 'SERVICE_PERSONA',
            ]);

        return Http::response(['ok' => true], 200);
    });

    $helper = makeCentralApiHelperForSwitches();
    $response = $helper->post_vsx_profile(['name' => 'vsx-pair-1'], 'site-scope-99');

    expect($response->ok())->toBeTrue();
});

test('ensureVsxKeepAliveVrf skips post when vrf exists at group scope', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'device-groups')) {
            return Http::response(['items' => [['scopeName' => 'MyGroup', 'scopeId' => 'group-scope-1']]], 200);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/vrfs')) {
            return Http::response(['vrf' => [['name' => CentralAPIHelper::VSX_KEEPALIVE_VRF]]], 200);
        }

        return Http::response([], 404);
    });

    $helper = makeCentralApiHelperForSwitches();
    $device = Device::factory()->for($helper->client)->create([
        'group' => 'MyGroup',
        'device_function' => 'ACCESS_SWITCH',
    ]);

    $result = $helper->ensureVsxKeepAliveVrf($device);

    expect($result)->toBe(['ok' => true]);
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), '/vrfs/'));
});

test('ensureVsxKeepAliveVrf posts vrf at group scope when missing', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'device-groups')) {
            return Http::response(['items' => [['scopeName' => 'MyGroup', 'scopeId' => 'group-scope-1']]], 200);
        }

        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        if ($request->method() === 'GET' && str_contains($request->url(), '/vrfs')) {
            return Http::response(['vrf' => []], 200);
        }

        if ($request->method() === 'POST' && str_contains($request->url(), '/vrfs/'.CentralAPIHelper::VSX_KEEPALIVE_VRF)) {
            expect($query['scope-id'] ?? null)->toBe('group-scope-1')
                ->and($query['device-function'] ?? null)->toBe('ACCESS_SWITCH');

            return Http::response(['name' => CentralAPIHelper::VSX_KEEPALIVE_VRF], 200);
        }

        return Http::response([], 404);
    });

    $helper = makeCentralApiHelperForSwitches();
    $device = Device::factory()->for($helper->client)->create([
        'group' => 'MyGroup',
        'device_function' => 'ACCESS_SWITCH',
    ]);

    $result = $helper->ensureVsxKeepAliveVrf($device);

    expect($result)->toBe(['ok' => true]);
});

test('ensureVsxKeepAliveVrf returns error when vrf post fails', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'device-groups')) {
            return Http::response(['items' => [['scopeName' => 'MyGroup', 'scopeId' => 'group-scope-1']]], 200);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/vrfs')) {
            return Http::response(['vrf' => []], 200);
        }

        if ($request->method() === 'POST' && str_contains($request->url(), '/vrfs/')) {
            return Http::response(['message' => 'VRF create failed'], 400);
        }

        return Http::response([], 404);
    });

    $helper = makeCentralApiHelperForSwitches();
    $device = Device::factory()->for($helper->client)->create([
        'name' => 'Switch-A',
        'group' => 'MyGroup',
        'device_function' => 'ACCESS_SWITCH',
    ]);

    $result = $helper->ensureVsxKeepAliveVrf($device);

    expect($result)->toHaveKey('error')
        ->and($result['error'])->toContain('WHSE-VSX-Keep-Alive VRF creation failed');
});

test('ensureVsxKeepAliveVrf returns error when device has no group', function () {
    $helper = makeCentralApiHelperForSwitches();
    $device = Device::factory()->for($helper->client)->create([
        'name' => 'Switch-A',
        'group' => null,
    ]);

    $result = $helper->ensureVsxKeepAliveVrf($device);

    expect($result['error'])->toContain('has no group for VRF lookup');
});

test('deviceHasMirrorAttributes detects any populated mirror column', function () {
    $device = Device::factory()->create([
        'mirror_session_id' => null,
        'mirror_dst_ports' => null,
        'mirror_vlans' => null,
        'mirror_name' => null,
    ]);

    expect(CentralAPIHelper::deviceHasMirrorAttributes($device))->toBeFalse();

    $device->mirror_session_id = 1;

    expect(CentralAPIHelper::deviceHasMirrorAttributes($device))->toBeTrue();
});

test('deploymentUsesMirrorFallbackMode is true when no selected device has mirror attributes', function () {
    $devices = Device::factory()->count(2)->create([
        'mirror_dst_ports' => null,
    ]);

    expect(CentralAPIHelper::deploymentUsesMirrorFallbackMode(collect($devices)))->toBeTrue();

    $devices->first()->mirror_dst_ports = '1/1/43';

    expect(CentralAPIHelper::deploymentUsesMirrorFallbackMode($devices))->toBeFalse();
});

test('deploymentUsesVsxFallbackMode is true when no selected device has vsx attributes', function () {
    $devices = Device::factory()->count(2)->create([
        'vsx_profile' => null,
        'vsx_role' => null,
        'vsx_system_mac' => null,
    ]);

    expect(CentralAPIHelper::deploymentUsesVsxFallbackMode(collect($devices)))->toBeTrue();

    $devices->first()->vsx_profile = 'pair-1';

    expect(CentralAPIHelper::deploymentUsesVsxFallbackMode($devices))->toBeFalse();
});

test('applyVsxFallbackAttributes infers core and svr profile metadata from device names', function () {
    $corePrimary = Device::factory()->make(['name' => 'NY1-MDF-CORE-SW1']);
    $coreSecondary = Device::factory()->make(['name' => 'NY1-MDF-CORE-SW2']);
    $svrPrimary = Device::factory()->make(['name' => 'NY1-MDF-SVR-SW1']);

    expect(CentralAPIHelper::applyVsxFallbackAttributes($corePrimary))->toBeTrue()
        ->and($corePrimary->vsx_profile)->toBe('NY1-MDF-CORE-VSX-PROFILE')
        ->and($corePrimary->vsx_role)->toBe('VSX_PRIMARY')
        ->and($corePrimary->vsx_system_mac)->toBe('02:00:00:00:00:01')
        ->and(CentralAPIHelper::applyVsxFallbackAttributes($coreSecondary))->toBeTrue()
        ->and($coreSecondary->vsx_role)->toBe('VSX_SECONDARY')
        ->and($coreSecondary->vsx_system_mac)->toBe('02:00:00:00:00:01')
        ->and(CentralAPIHelper::applyVsxFallbackAttributes($svrPrimary))->toBeTrue()
        ->and($svrPrimary->vsx_profile)->toBe('NY1-MDF-SVR-VSX-PROFILE')
        ->and($svrPrimary->vsx_role)->toBe('VSX_PRIMARY')
        ->and($svrPrimary->vsx_system_mac)->toBe('02:00:00:00:00:02');
});

test('inferVsxRoleFromName maps sw1 and sw2 suffixes to primary and secondary roles', function () {
    expect(CentralAPIHelper::inferVsxRoleFromName(Device::factory()->make(['name' => 'WHSE-MDF-CORE-SW1'])))
        ->toBe(App\VsxRole::VSX_PRIMARY)
        ->and(CentralAPIHelper::inferVsxRoleFromName(Device::factory()->make(['name' => 'WHSE-MDF-CORE-SW2'])))
        ->toBe(App\VsxRole::VSX_SECONDARY);
});

test('classic_collect_all_switches paginates until all pages are loaded', function () {
    Http::fake([
        '*' => Http::sequence()
            ->push(['switches' => [['serial' => 'SN1', 'status' => 'Up']], 'total' => 2], 200)
            ->push(['switches' => [['serial' => 'SN2', 'status' => 'Down']], 'total' => 2], 200),
    ]);

    $helper = makeCentralApiHelperForSwitches();
    $result = $helper->classic_collect_all_switches(['limit' => 1]);

    expect($result)->toHaveKey('switches')
        ->and($result['switches'])->toHaveCount(2)
        ->and($result['switches'][0]['serial'])->toBe('SN1')
        ->and($result['switches'][1]['serial'])->toBe('SN2');
});

test('deviceMatchesMirrorSessionNamePattern matches core fzn-mdf-mgmt and mdf-mgmt names', function () {
    expect(CentralAPIHelper::deviceMatchesMirrorSessionNamePattern(Device::factory()->create(['name' => 'NY1-MDF-CORE-SW1'])))->toBeTrue()
        ->and(CentralAPIHelper::deviceMatchesMirrorSessionNamePattern(Device::factory()->create(['name' => 'FZN-MDF-MGMT-SW1'])))->toBeTrue()
        ->and(CentralAPIHelper::deviceMatchesMirrorSessionNamePattern(Device::factory()->create(['name' => 'WHSE-MDF-MGMT-SW1'])))->toBeTrue()
        ->and(CentralAPIHelper::deviceMatchesMirrorSessionNamePattern(Device::factory()->create(['name' => 'NY1-ACCESS-SW1'])))->toBeFalse();
});

test('resolveMirrorSettings uses name-pattern defaults in fallback mode', function () {
    $helper = makeCentralApiHelperForSwitches();
    $device = Device::factory()->for($helper->client)->create([
        'name' => 'NY1-MDF-CORE-SW1',
        'scope_id' => 'scope-1',
        'device_function' => App\DeviceFunction::CORE_SWITCH,
    ]);

    Http::fake([
        '*' => Http::response(['l2-vlan' => [['vlan' => 10], ['vlan' => 20]]], 200),
    ]);

    $settings = $helper->resolveMirrorSettings($device, true);

    expect($settings)->toMatchArray([
        'name' => 'NY1-MDF-CORE-SW1-DARKTRACE-SPAN',
        'session_id' => 1,
        'dst_ports' => ['1/1/43'],
        'vlan_ids' => [10, 20],
    ]);
});

test('fetchMirrorVlanIdsForDevice merges device and group scope l2 vlan results', function () {
    $helper = makeCentralApiHelperForSwitches();
    $device = Device::factory()->for($helper->client)->create([
        'name' => 'NY1-MDF-CORE-SW1',
        'scope_id' => 'device-scope-1',
        'group' => 'MyGroup',
        'device_function' => App\DeviceFunction::CORE_SWITCH,
    ]);

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'device-groups')) {
            return Http::response(['items' => [['scopeName' => 'MyGroup', 'scopeId' => 'group-scope-1']]], 200);
        }

        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        if (! str_contains($request->url(), 'layer2-vlan')) {
            return Http::response([], 404);
        }

        return match ($query['scope-id'] ?? null) {
            'device-scope-1' => Http::response(['l2-vlan' => [['vlan' => 10], ['vlan' => 20]]], 200),
            'group-scope-1' => Http::response(['l2-vlan' => [['vlan' => 20], ['vlan' => 30]]], 200),
            default => Http::response(['l2-vlan' => []], 200),
        };
    });

    $result = $helper->fetchMirrorVlanIdsForDevice($device);

    expect($result)->toBe(['vlan_ids' => [10, 20, 30]]);

    Http::assertSent(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return str_contains($request->url(), 'layer2-vlan')
            && ($query['scope-id'] ?? null) === 'group-scope-1'
            && ($query['device-function'] ?? null) === 'CORE_SWITCH'
            && ($query['view-type'] ?? null) === 'LOCAL'
            && ($query['object-type'] ?? null) === 'LOCAL';
    });
});

test('fetchMirrorVlanIdsForDevice skips group l2 vlan call when group scope matches device scope', function () {
    $helper = makeCentralApiHelperForSwitches();
    $device = Device::factory()->for($helper->client)->create([
        'name' => 'NY1-MDF-CORE-SW1',
        'scope_id' => 'shared-scope-1',
        'group' => 'MyGroup',
        'device_function' => App\DeviceFunction::CORE_SWITCH,
    ]);

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'device-groups')) {
            return Http::response(['items' => [['scopeName' => 'MyGroup', 'scopeId' => 'shared-scope-1']]], 200);
        }

        if (str_contains($request->url(), 'layer2-vlan')) {
            return Http::response(['l2-vlan' => [['vlan' => 10]]], 200);
        }

        return Http::response([], 404);
    });

    $result = $helper->fetchMirrorVlanIdsForDevice($device);

    expect($result)->toBe(['vlan_ids' => [10]]);

    Http::assertSentCount(2);
});

test('resolveMirrorSettings prefers fzn-mdf-mgmt ports over core and mdf-mgmt patterns', function () {
    $helper = makeCentralApiHelperForSwitches();
    $device = Device::factory()->for($helper->client)->create([
        'name' => 'FZN-MDF-MGMT-CORE-SW1',
        'scope_id' => 'scope-1',
        'device_function' => App\DeviceFunction::CORE_SWITCH,
    ]);

    Http::fake([
        '*' => Http::response(['l2-vlan' => [['vlan' => 10]]], 200),
    ]);

    $settings = $helper->resolveMirrorSettings($device, true);

    expect($settings['dst_ports'])->toBe(['1/1/21', '1/1/22']);
});

test('resolveMirrorSettings uses mdf-mgmt-only ports when fzn is absent', function () {
    $helper = makeCentralApiHelperForSwitches();
    $device = Device::factory()->for($helper->client)->create([
        'name' => 'WHSE-MDF-MGMT-SW1',
        'scope_id' => 'scope-1',
        'device_function' => App\DeviceFunction::ACCESS_SWITCH,
    ]);

    Http::fake([
        '*' => Http::response(['l2-vlan' => [['vlan' => 10]]], 200),
    ]);

    $settings = $helper->resolveMirrorSettings($device, true);

    expect($settings['dst_ports'])->toBe(['1/1/16', '2/1/9']);
});

test('resolveMirrorSettings uses database columns in explicit mode without name-pattern ports', function () {
    $helper = makeCentralApiHelperForSwitches();
    $device = Device::factory()->for($helper->client)->create([
        'name' => 'NY1-MDF-CORE-SW1',
        'scope_id' => 'scope-1',
        'device_function' => App\DeviceFunction::CORE_SWITCH,
        'mirror_dst_ports' => '1/1/10&1/1/11',
        'mirror_session_id' => 3,
        'mirror_name' => 'custom-mirror',
        'mirror_vlans' => '100&200-202',
    ]);

    $settings = $helper->resolveMirrorSettings($device, false);

    expect($settings)->toMatchArray([
        'name' => 'custom-mirror',
        'session_id' => 3,
        'dst_ports' => ['1/1/10', '1/1/11'],
        'vlan_ids' => [100, 200, 201, 202],
    ]);
});

test('buildMirrorPayload matches expected mirror session shape', function () {
    $device = Device::factory()->create([
        'name' => 'NY1-MDF-CORE-SW1',
        'serial' => 'CORE123456',
    ]);

    $payload = CentralAPIHelper::buildMirrorPayload($device, 'NY1-MDF-CORE-SW1-DARKTRACE-SPAN', 1, ['1/1/43'], [10, 20]);

    expect($payload)->toBe([
        'name' => 'NY1-MDF-CORE-SW1-DARKTRACE-SPAN',
        'session' => [
            'enable' => true,
            'session-id' => 1,
            'session-destination' => [
                'destination-type' => 'INTERFACES',
                'destination-switch-serial' => 'CORE123456',
                'eth-interfaces' => [
                    ['eth-interface' => '1/1/43'],
                ],
            ],
            'session-sources' => [
                'source-switch-serial' => 'CORE123456',
                'vlans' => [
                    ['direction' => 'BOTH', 'vlan-id' => 10],
                    ['direction' => 'BOTH', 'vlan-id' => 20],
                ],
            ],
        ],
    ]);
});
