<?php

use App\BaseURL;
use App\Helper\CentralAPIHelper;
use App\InterfaceKind;
use App\Models\Client;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\LacpProfile;
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

        expect($query['limit'] ?? null)->toBe('100')
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
