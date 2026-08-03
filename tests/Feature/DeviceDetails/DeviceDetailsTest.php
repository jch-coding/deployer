<?php

use App\BaseURL;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create();
    $this->client = Client::factory()->for($this->user)->create([
        'current' => true,
        'base_url' => BaseURL::US1,
        'bearer_token' => 'test-bearer-token',
        'expires_at' => now()->addHour(),
    ]);
    $this->actingAs($this->user);
    seedCentralScopeCache($this->client);
});

test('device details index redirects when no current client is set', function () {
    $this->client->update(['current' => false]);

    $this->get(route('device-details.index'))
        ->assertRedirect(route('clients.index'));
});

test('device details index renders without devices when no filters are applied', function () {
    $this->get(route('device-details.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('DeviceDetails/Index')
            ->where('devices', [])
            ->where('has_active_filters', false)
            ->has('site_options', 1)
            ->where('site_options.0.siteId', 'scope-site')
            ->where('site_options.0.siteName', 'Central Site')
            ->has('central_sites_cache.refreshed_at')
            ->has('central_groups_cache.refreshed_at'));
});

test('device details index fetches devices when filters are applied', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'network-monitoring/v1/devices')) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            expect($query['filter'] ?? null)->toBe('siteId eq scope-site and status eq ONLINE');

            return Http::response([
                'items' => [[
                    'deviceName' => 'Switch-A',
                    'serialNumber' => 'SN12345',
                    'deviceFunction' => 'ACCESS_SWITCH',
                    'model' => '6300',
                    'ipv4' => '10.0.0.1',
                    'status' => 'ONLINE',
                    'deployment' => 'Standalone',
                    'siteName' => 'HQ',
                ]],
                'next' => null,
                'total' => 1,
                'count' => 1,
            ], 200);
        }

        return Http::response([], 404);
    });

    $this->get(route('device-details.index', [
        'site_id' => 'scope-site',
        'status' => 'ONLINE',
        'submitted' => true,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('DeviceDetails/Index')
            ->where('has_active_filters', true)
            ->where('filters.site_id', 'scope-site')
            ->where('filters.status', 'ONLINE')
            ->has('devices', 1)
            ->where('devices.0.deviceName', 'Switch-A')
            ->where('devices.0.serialNumber', 'SN12345')
            ->where('devices.0.status', 'ONLINE')
            ->where('devices.0.deployment', 'Standalone')
            ->where('devices.0.siteName', 'HQ'));
});

test('device details index does not fetch devices when filters are applied without submission', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'network-monitoring/v1/devices')) {
            return Http::response([
                'items' => [[
                    'deviceName' => 'Switch-A',
                    'serialNumber' => 'SN12345',
                    'deviceFunction' => 'ACCESS_SWITCH',
                    'model' => '6300',
                    'ipv4' => '10.0.0.1',
                    'status' => 'ONLINE',
                    'deployment' => 'Standalone',
                    'siteName' => 'HQ',
                ]],
                'next' => null,
                'total' => 1,
                'count' => 1,
            ], 200);
        }

        return Http::response([], 404);
    });

    $this->get(route('device-details.index', [
        'site_id' => 'scope-site',
        'status' => 'ONLINE',
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('DeviceDetails/Index')
            ->where('has_active_filters', true)
            ->where('filters.site_id', 'scope-site')
            ->where('filters.status', 'ONLINE')
            ->where('devices', []));

    Http::assertNothingSent();
});

test('device details index rejects invalid filter enums', function () {
    $this->get(route('device-details.index', ['device_type' => 'INVALID']))
        ->assertSessionHasErrors('device_type');
});

test('device details show maps interface fields for a single serial', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'network-monitoring/v1/devices')) {
            return Http::response([
                'items' => [[
                    'deviceName' => 'Switch-A',
                    'serialNumber' => 'SN12345',
                ]],
                'next' => null,
                'total' => 1,
                'count' => 1,
            ], 200);
        }

        if (str_contains($request->url(), 'network-monitoring/v1/switches/SN12345/interfaces')) {
            return Http::response([
                'items' => [[
                    'name' => '1/1/1',
                    'status' => 'Connected',
                    'operStatus' => 'Up',
                    'neighbour' => 'AP-1',
                    'neighbourSerial' => 'APSN1',
                    'vlanMode' => 'Trunk',
                    'allowedVlanIds' => [10, 20],
                    'nativeVlan' => 1,
                    'poeClass' => 'Class4',
                    'neighbourFamily' => 'Aruba',
                    'neighbourFunction' => 'AP',
                    'neighbourType' => 'Access Point',
                    'transceiverType' => 'SFP',
                ]],
                'total' => 1,
                'offset' => null,
            ], 200);
        }

        return Http::response([], 404);
    });

    $this->get(route('device-details.show', ['serials' => ['SN12345']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('DeviceDetails/Show')
            ->has('devices', 1)
            ->where('devices.0.serial', 'SN12345')
            ->where('devices.0.device_name', 'Switch-A')
            ->where('devices.0.device_type', 'SWITCH')
            ->where('devices.0.central_error', null)
            ->has('devices.0.interfaces', 1)
            ->where('devices.0.interfaces.0.name', '1/1/1')
            ->where('devices.0.interfaces.0.status', 'Connected')
            ->where('devices.0.interfaces.0.operStatus', 'Up')
            ->where('devices.0.interfaces.0.neighbour', 'AP-1')
            ->where('devices.0.interfaces.0.neighbourSerial', 'APSN1')
            ->where('devices.0.interfaces.0.vlanMode', 'Trunk')
            ->where('devices.0.interfaces.0.allowedVlanIds', [10, 20])
            ->where('devices.0.interfaces.0.nativeVlan', '1')
            ->where('devices.0.interfaces.0.poeClass', 'Class4')
            ->where('devices.0.interfaces.0.neighbourFamily', 'Aruba')
            ->where('devices.0.interfaces.0.neighbourFunction', 'AP')
            ->where('devices.0.interfaces.0.neighbourType', 'Access Point')
            ->where('devices.0.interfaces.0.transceiverType', 'SFP'));
});

test('device details show maps interfaces for multiple serials', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'network-monitoring/v1/devices')) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $filter = (string) ($query['filter'] ?? '');

            if (str_contains($filter, 'SN111')) {
                return Http::response([
                    'items' => [['deviceName' => 'Switch-One', 'serialNumber' => 'SN111', 'deviceType' => 'SWITCH']],
                    'next' => null,
                    'total' => 1,
                    'count' => 1,
                ], 200);
            }

            if (str_contains($filter, 'SN222')) {
                return Http::response([
                    'items' => [['deviceName' => 'Switch-Two', 'serialNumber' => 'SN222', 'deviceType' => 'SWITCH']],
                    'next' => null,
                    'total' => 1,
                    'count' => 1,
                ], 200);
            }
        }

        if (str_contains($request->url(), 'network-monitoring/v1/switches/SN111/interfaces')) {
            return Http::response([
                'items' => [[
                    'name' => '1/1/1',
                    'status' => 'Connected',
                    'operStatus' => 'Up',
                    'neighbour' => '',
                    'neighbourSerial' => '',
                    'vlanMode' => 'Access',
                    'allowedVlanIds' => [10],
                    'nativeVlan' => 10,
                    'poeClass' => '',
                    'neighbourFamily' => '',
                    'neighbourFunction' => '',
                    'neighbourType' => '',
                    'transceiverType' => '',
                ]],
                'total' => 1,
                'offset' => null,
            ], 200);
        }

        if (str_contains($request->url(), 'network-monitoring/v1/switches/SN222/interfaces')) {
            return Http::response([
                'items' => [[
                    'name' => '1/1/2',
                    'status' => 'Not Connected',
                    'operStatus' => 'Down',
                    'neighbour' => '',
                    'neighbourSerial' => '',
                    'vlanMode' => 'Trunk',
                    'allowedVlanIds' => [20, 30],
                    'nativeVlan' => 1,
                    'poeClass' => 'Class3',
                    'neighbourFamily' => '',
                    'neighbourFunction' => '',
                    'neighbourType' => '',
                    'transceiverType' => 'SFP',
                ]],
                'total' => 1,
                'offset' => null,
            ], 200);
        }

        return Http::response([], 404);
    });

    $this->get(route('device-details.show', ['serials' => ['SN111', 'SN222']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('DeviceDetails/Show')
            ->has('devices', 2)
            ->where('devices.0.serial', 'SN111')
            ->where('devices.0.device_name', 'Switch-One')
            ->where('devices.0.interfaces.0.name', '1/1/1')
            ->where('devices.1.serial', 'SN222')
            ->where('devices.1.device_name', 'Switch-Two')
            ->where('devices.1.interfaces.0.name', '1/1/2')
            ->where('devices.1.interfaces.0.allowedVlanIds', [20, 30]));
});

test('device details show keeps per-switch errors isolated', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'network-monitoring/v1/devices')) {
            return Http::response([
                'items' => [['deviceName' => 'Switch-A', 'serialNumber' => 'SN12345', 'deviceType' => 'SWITCH']],
                'next' => null,
                'total' => 1,
                'count' => 1,
            ], 200);
        }

        if (str_contains($request->url(), 'network-monitoring/v1/switches/SN12345/interfaces')) {
            return Http::response([
                'items' => [[
                    'name' => '1/1/1',
                    'status' => 'Connected',
                    'operStatus' => 'Up',
                    'neighbour' => '',
                    'neighbourSerial' => '',
                    'vlanMode' => '',
                    'allowedVlanIds' => [],
                    'nativeVlan' => '',
                    'poeClass' => '',
                    'neighbourFamily' => '',
                    'neighbourFunction' => '',
                    'neighbourType' => '',
                    'transceiverType' => '',
                ]],
                'total' => 1,
                'offset' => null,
            ], 200);
        }

        if (str_contains($request->url(), 'network-monitoring/v1/switches/SN999/interfaces')) {
            return Http::response(['detail' => 'not found'], 404);
        }

        return Http::response([], 404);
    });

    $this->get(route('device-details.show', ['serials' => ['SN12345', 'SN999']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('DeviceDetails/Show')
            ->has('devices', 2)
            ->where('devices.0.serial', 'SN12345')
            ->where('devices.0.central_error', null)
            ->has('devices.0.interfaces', 1)
            ->where('devices.1.serial', 'SN999')
            ->where('devices.1.interfaces', [])
            ->where('devices.1.central_error', 'failed to get switch interfaces from central.'));
});

test('device details show skips switch interfaces for access points', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'network-monitoring/v1/devices')) {
            return Http::response([
                'items' => [[
                    'deviceName' => 'AP-Lobby',
                    'serialNumber' => 'AP00000001',
                    'deviceType' => 'ACCESS_POINT',
                    'deviceFunction' => 'CAMPUS_AP',
                ]],
                'next' => null,
                'total' => 1,
                'count' => 1,
            ], 200);
        }

        return Http::response(['detail' => 'unexpected '.$request->url()], 404);
    });

    $this->get(route('device-details.show', ['serials' => ['AP00000001']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('DeviceDetails/Show')
            ->has('devices', 1)
            ->where('devices.0.serial', 'AP00000001')
            ->where('devices.0.device_name', 'AP-Lobby')
            ->where('devices.0.device_type', 'ACCESS_POINT')
            ->where('devices.0.device_function', 'CAMPUS_AP')
            ->where('devices.0.interfaces', [])
            ->where('devices.0.central_error', null));

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/interfaces'));
});

test('device details legacy serial path redirects to show with serials query', function () {
    $this->get(route('device-details.redirect-show', ['serial' => 'SN12345']))
        ->assertRedirect(route('device-details.show', ['serials' => ['SN12345']]));
});

test('device details show requires serials', function () {
    $this->get(route('device-details.show'))
        ->assertSessionHasErrors('serials');
});

test('device details show rejects too many serials', function () {
    $serials = [];
    for ($i = 1; $i <= 26; $i++) {
        $serials[] = 'SN'.str_pad((string) $i, 3, '0', STR_PAD_LEFT);
    }

    $this->get(route('device-details.show', ['serials' => $serials]))
        ->assertSessionHasErrors('serials');
});

test('device details show redirects when no current client is set', function () {
    $this->client->update(['current' => false]);

    $this->get(route('device-details.show', ['serials' => ['SN12345']]))
        ->assertRedirect(route('clients.index'));
});

test('device details compare profiles redirects gate when no current client is set', function () {
    $this->client->update(['current' => false]);

    $this->postJson(route('device-details.compare-profiles'), ['serial' => 'SN12345'])
        ->assertStatus(422)
        ->assertJson([
            'message' => 'Please set current client to compare switch profiles.',
        ]);
});

test('device details compare profiles requires serial', function () {
    $this->postJson(route('device-details.compare-profiles'), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['serial']);
});

test('device details compare profiles walks scope chain and reports match and mismatch', function () {
    Http::fake(function (Request $request) {
        $url = $request->url();
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

        if (str_contains($url, 'network-monitoring/v1/switches/') && str_contains($url, '/interfaces')) {
            return Http::response([
                'items' => [
                    [
                        'name' => '1/1/1',
                        'vlanMode' => 'Trunk',
                        'nativeVlan' => 1,
                        'allowedVlanIds' => [10, 20],
                    ],
                    [
                        'name' => '1/1/2',
                        'vlanMode' => 'Access',
                        'nativeVlan' => 99,
                        'allowedVlanIds' => [99],
                    ],
                    [
                        'name' => '1/1/3',
                        'vlanMode' => 'Access',
                        'nativeVlan' => 1,
                        'allowedVlanIds' => [],
                    ],
                ],
                'offset' => null,
            ], 200);
        }

        if (str_contains($url, 'network-monitoring/v1/switches')) {
            return Http::response([
                'items' => [[
                    'serialNumber' => 'SN12345',
                    'stackId' => 'STACK-1',
                ]],
                'next' => null,
            ], 200);
        }

        if (str_contains($url, 'network-config/v1/hierarchy')) {
            $type = $query['type'] ?? '';
            $id = $query['id'] ?? '';

            if ($type === 'device') {
                return Http::response([
                    'items' => [[
                        'hierarchy' => [
                            ['scopeName' => 'Switch-A', 'scopeType' => 'device', 'childCount' => null, 'scopeId' => 'scope-device', 'hostName' => 'Switch-A'],
                            ['scopeName' => 'Edge', 'scopeType' => 'device_group', 'childCount' => 2, 'scopeId' => 'scope-group', 'hostName' => ''],
                            ['scopeName' => 'HQ', 'scopeType' => 'site', 'childCount' => 5, 'scopeId' => 'scope-site', 'hostName' => ''],
                        ],
                    ]],
                ], 200);
            }

            if ($type === 'site' && $id === 'scope-site') {
                return Http::response([
                    'items' => [[
                        'hierarchy' => [
                            ['scopeName' => 'HQ', 'scopeType' => 'site', 'childCount' => 5, 'scopeId' => 'scope-site', 'hostName' => ''],
                            ['scopeName' => 'Retail', 'scopeType' => 'site_collection', 'childCount' => 10, 'scopeId' => 'scope-collection', 'hostName' => ''],
                        ],
                    ]],
                ], 200);
            }

            return Http::response(['items' => [['hierarchy' => []]]], 200);
        }

        if (str_contains($url, 'network-config/v1alpha1/ethernet-interfaces')) {
            expect($query['scope-id'] ?? null)->toBe('scope-device');
            expect($query['view-type'] ?? null)->toBe('LOCAL');
            expect($query['device-function'] ?? null)->toBe('ACCESS_SWITCH');

            return Http::response([
                'interface' => [
                    [
                        'name' => '1/1/1',
                        'sw-profile' => 'trunk-profile',
                    ],
                    [
                        'name' => '1/1/2',
                        'sw-profile' => 'trunk-profile',
                    ],
                    [
                        'name' => '1/1/3',
                    ],
                ],
            ], 200);
        }

        if (str_contains($url, 'network-config/v1alpha1/sw-port-profiles/trunk-profile')) {
            $scopeId = $query['scope-id'] ?? null;
            expect($query['view-type'] ?? null)->toBe('LOCAL');
            expect($query['device-function'] ?? null)->toBe('ACCESS_SWITCH');

            if (in_array($scopeId, ['scope-device', 'scope-group'], true)) {
                return Http::response([], 200);
            }

            if ($scopeId === 'scope-site') {
                return Http::response([
                    'profile-name' => 'trunk-profile',
                    'switchport' => [
                        'interface-mode' => 'TRUNK',
                        'native-vlan' => 1,
                        'trunk-vlan-ranges' => [10, 20],
                    ],
                ], 200);
            }

            return Http::response([], 200);
        }

        return Http::response(['detail' => 'unexpected '.$url], 404);
    });

    $response = $this->postJson(route('device-details.compare-profiles'), [
        'serial' => 'SN12345',
    ]);

    $response->assertOk()
        ->assertJsonPath('serial', 'SN12345')
        ->assertJsonPath('error', null)
        ->assertJsonPath('summary.profiles', 1)
        ->assertJsonPath('summary.matches', 1)
        ->assertJsonPath('summary.mismatches', 1)
        ->assertJsonPath('summary.no_profile', 1)
        ->assertJsonPath('profiles.0.name', 'trunk-profile')
        ->assertJsonPath('profiles.0.found', true)
        ->assertJsonPath('profiles.0.scope_level', 'site');

    $interfaces = collect($response->json('interfaces'));

    expect($interfaces->firstWhere('name', '1/1/1')['status'])->toBe('match')
        ->and($interfaces->firstWhere('name', '1/1/2')['status'])->toBe('mismatch')
        ->and($interfaces->firstWhere('name', '1/1/3')['status'])->toBe('no_profile');
});

test('device details bssids returns mapped rows for a serial', function () {
    Http::fake(function (Request $request) {
        expect($request->url())->toContain('network-monitoring/v1/bssids');

        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
        expect($query['filter'] ?? null)->toBe('serialNumber eq AP00000001');

        return Http::response([
            'items' => [[
                'serialNumber' => 'AP00000001',
                'deviceName' => 'AP-Lobby',
                'macAddress' => '11:22:33:44:55:66',
                'clusterId' => 'cluster1',
                'radioNumber' => 0,
                'radioMacAddress' => '11:22:33:44:55:77',
                'bssid' => '11:22:33:44:55:88',
                'wlanName' => 'wlan1',
                'siteId' => '24833497',
                'siteName' => 'site1',
                'clientCount' => 3,
            ]],
            'count' => 1,
            'total' => 1,
            'next' => null,
        ], 200);
    });

    $this->postJson(route('device-details.bssids'), ['serial' => 'AP00000001'])
        ->assertOk()
        ->assertJson([
            'serial' => 'AP00000001',
            'error' => null,
            'bssids' => [[
                'bssid' => '11:22:33:44:55:88',
                'wlanName' => 'wlan1',
                'radioNumber' => 0,
                'radioMacAddress' => '11:22:33:44:55:77',
                'macAddress' => '11:22:33:44:55:66',
                'clientCount' => 3,
                'siteName' => 'site1',
                'siteId' => '24833497',
                'clusterId' => 'cluster1',
                'deviceName' => 'AP-Lobby',
                'serialNumber' => 'AP00000001',
            ]],
        ]);
});

test('device details bssids returns error when central fails', function () {
    Http::fake([
        '*network-monitoring/v1/bssids*' => Http::response(['detail' => 'error'], 500),
    ]);

    $this->postJson(route('device-details.bssids'), ['serial' => 'AP00000001'])
        ->assertStatus(422)
        ->assertJson([
            'serial' => 'AP00000001',
            'bssids' => [],
            'error' => 'failed to get bssids from central.',
        ]);
});

test('device details bssids requires serial', function () {
    $this->postJson(route('device-details.bssids'), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['serial']);
});

test('device details bssids redirects gate when no current client is set', function () {
    $this->client->update(['current' => false]);

    $this->postJson(route('device-details.bssids'), ['serial' => 'AP00000001'])
        ->assertStatus(422)
        ->assertJson([
            'bssids' => [],
            'error' => 'Please set current client to view BSSIDs.',
        ]);
});

test('device details site bssids returns mapped ap_name and ap_mac rows', function () {
    Http::fake(function (Request $request) {
        expect($request->url())->toContain('network-monitoring/v1/bssids');

        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
        expect($query['filter'] ?? null)->toBe('siteId eq scope-site');

        return Http::response([
            'items' => [
                [
                    'deviceName' => 'AP-Lobby',
                    'bssid' => '11:22:33:44:55:88',
                    'serialNumber' => 'AP00000001',
                ],
                [
                    'deviceName' => 'AP-Lobby',
                    'bssid' => '11:22:33:44:55:99',
                    'serialNumber' => 'AP00000001',
                ],
                [
                    'deviceName' => 'AP-Cafe',
                    'bssid' => 'aa:bb:cc:dd:ee:01',
                    'serialNumber' => 'AP00000002',
                ],
            ],
            'count' => 3,
            'total' => 3,
            'next' => null,
        ], 200);
    });

    $this->postJson(route('device-details.site-bssids'), [
        'site_id' => 'scope-site',
        'site_name' => '',
    ])
        ->assertOk()
        ->assertJson([
            'site_id' => 'scope-site',
            'site_name' => '',
            'error' => null,
            'bssids' => [
                ['ap_name' => 'AP-Lobby', 'ap_mac' => '11:22:33:44:55:88'],
                ['ap_name' => 'AP-Lobby', 'ap_mac' => '11:22:33:44:55:99'],
                ['ap_name' => 'AP-Cafe', 'ap_mac' => 'aa:bb:cc:dd:ee:01'],
            ],
        ]);
});

test('device details site bssids requires a site id or name', function () {
    $this->postJson(route('device-details.site-bssids'), [
        'site_id' => '',
        'site_name' => '',
    ])
        ->assertStatus(422)
        ->assertJson([
            'bssids' => [],
            'error' => 'A site ID or site name is required.',
        ]);
});

test('device details site bssids returns error when central fails', function () {
    Http::fake([
        '*network-monitoring/v1/bssids*' => Http::response(['detail' => 'error'], 500),
    ]);

    $this->postJson(route('device-details.site-bssids'), ['site_id' => 'scope-site'])
        ->assertStatus(422)
        ->assertJson([
            'site_id' => 'scope-site',
            'bssids' => [],
            'error' => 'failed to get bssids from central.',
        ]);
});

test('device details site bssids redirects gate when no current client is set', function () {
    $this->client->update(['current' => false]);

    $this->postJson(route('device-details.site-bssids'), ['site_id' => 'scope-site'])
        ->assertStatus(422)
        ->assertJson([
            'bssids' => [],
            'error' => 'Please set current client to view BSSIDs.',
        ]);
});
