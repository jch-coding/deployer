<?php

use App\BaseURL;
use App\InterfaceKind;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\LacpProfile;
use App\Models\Site;
use App\Models\SwitchPort;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = Client::factory()->for($this->user)->create([
        'current' => true,
        'base_url' => BaseURL::US1,
        'bearer_token' => 'test-bearer-token',
        'expires_at' => now()->addHour(),
    ]);
    $this->deployment = Deployment::factory()->for($this->client)->create();
    $this->site = Site::factory()->for($this->client)->create([
        'name' => 'TestSite',
        'scope_id' => 'site-scope-123',
    ]);
    $this->device = Device::factory()->for($this->deployment)->create([
        'site_id' => $this->site->id,
        'scope_id' => 'device-scope-123',
        'device_function' => 'ACCESS_SWITCH',
    ]);
    $this->actingAs($this->user);
});

function fakeCriticalCheckCentralApis(array $overrides = []): void
{
    Http::fake(array_merge([
        '*portchannels*' => Http::response(['interface' => []], 200),
        '*ethernet-interfaces*' => Http::response(['interface' => []], 200),
        '*vlan-interfaces*' => Http::response(['interface' => []], 200),
        '*static-route*' => Http::response(staticRouteProfilePayload(), 200),
        '*device-groups*' => Http::response(['items' => []], 200),
        '*dns*' => Http::response([
            'profile' => [
                [
                    'name' => 'dns-profile',
                    'resolver' => [
                        [
                            'vrf' => 'default',
                            'name-server' => [
                                ['ip' => '8.8.8.8', 'priority' => 1],
                            ],
                        ],
                    ],
                ],
            ],
        ], 200),
        '*site-collections*' => Http::response([
            'items' => [
                ['scopeName' => 'WCD', 'scopeId' => 'wcd-scope-from-central'],
            ],
        ], 200),
    ], $overrides));
}

function criticalCheckStepUrl(Deployment $deployment, int $step, array $query = []): string
{
    $url = route('deployments.critical_check.step', [$deployment, $step]);

    if ($query !== []) {
        $url .= '?'.http_build_query($query);
    }

    return $url;
}

/**
 * @return array<string, mixed>
 */
function staticRouteProfilePayload(
    string $name = 'static-profile',
    string $prefix = '0.0.0.0/0',
    string $nextHop = '192.168.1.1',
): array {
    return [
        'profile' => [
            'name' => $name,
            'ipv4' => [
                'prefix' => $prefix,
                'next-hop' => $nextHop,
                'prefix-vrf-nexthop-id' => '1',
            ],
        ],
    ];
}

/**
 * @return array<string, string>
 */
function staticRouteRequestQuery(\Illuminate\Http\Client\Request $request): array
{
    parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

    return is_array($query) ? $query : [];
}

function fakeCriticalCheckCentralApisWithStaticRouteHandler(callable $staticRouteHandler): void
{
    Http::fake(function (\Illuminate\Http\Client\Request $request) use ($staticRouteHandler) {
        $url = $request->url();

        if (str_contains($url, 'static-route')) {
            return $staticRouteHandler($request);
        }

        if (str_contains($url, 'portchannels')) {
            return Http::response(['interface' => []], 200);
        }
        if (str_contains($url, 'ethernet-interfaces')) {
            return Http::response(['interface' => []], 200);
        }
        if (str_contains($url, 'vlan-interfaces')) {
            return Http::response(['interface' => []], 200);
        }
        if (str_contains($url, 'device-groups')) {
            return Http::response(['items' => []], 200);
        }
        if (str_contains($url, 'site-collections')) {
            return Http::response([
                'items' => [
                    ['scopeName' => 'WCD', 'scopeId' => 'wcd-scope-from-central'],
                ],
            ], 200);
        }
        if (str_contains($url, 'dns')) {
            return Http::response([
                'profile' => [
                    [
                        'name' => 'dns-profile',
                        'resolver' => [
                            [
                                'vrf' => 'default',
                                'name-server' => [
                                    ['ip' => '8.8.8.8', 'priority' => 1],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200);
        }

        return Http::response([], 200);
    });
}

test('deployment critical check reports lag match', function () {
    $lacpProfile = LacpProfile::factory()->create([
        'mode' => 'ACTIVE',
        'rate' => 'SLOW',
        'port_list' => '1/1/1-1/1/2',
        'trunk_type' => 'LACP',
    ]);
    $switchPort = SwitchPort::factory()->create([
        'interface_mode' => 'TRUNK',
        'access_vlan' => null,
        'native_vlan' => 10,
        'trunk_vlan_all' => 'true',
        'trunk_vlan_ranges' => null,
    ]);
    DeviceInterface::factory()->create([
        'device_id' => $this->device->id,
        'interface' => '10',
        'switch_port_id' => $switchPort->id,
        'lacp_profile_id' => $lacpProfile->id,
        'interface_kind' => InterfaceKind::LAG,
        'description' => null,
    ]);

    $centralLag = [
        'name' => '10',
        'vsx' => ['shutdown-on-split' => false],
        'switchport' => [
            'access-vlan' => null,
            'interface-mode' => 'TRUNK',
            'native-vlan' => 10,
            'trunk-vlan-all' => true,
            'trunk-vlan-ranges' => null,
        ],
        'lacp' => ['mode' => 'ACTIVE', 'rate' => 'SLOW'],
        'trunk-type' => 'LACP',
        'port-list' => ['1/1/1', '1/1/2'],
        'enable' => true,
    ];

    fakeCriticalCheckCentralApis([
        '*portchannels*' => Http::response(['interface' => [$centralLag]], 200),
    ]);

    $response = $this->getJson(route('deployments.critical_check.step', [$this->deployment, 1]))
        ->assertOk()
        ->assertJsonPath('partial.lag_results.0.ok', true)
        ->assertJsonPath('progress.message', 'Checking LAG interfaces for '.$this->device->name.'...');

    expect($response->json('partial.lag_results.0.details'))->toBeArray()->not->toBeEmpty();
});

test('deployment critical check step endpoint returns progress', function () {
    fakeCriticalCheckCentralApis();

    $this->getJson(route('deployments.critical_check.step', [$this->deployment, 0]))
        ->assertOk()
        ->assertJsonPath('progress.current', 1)
        ->assertJsonPath('progress.message', 'Resolving DNS scope ID...')
        ->assertJsonPath('partial.dns_scope_id', '73800600944427008')
        ->assertJsonPath('partial.dns_site_collection_name', 'WCD');
});

test('deployment critical check page loads immediately without results', function () {
    $this->get(route('deployments.critical_check', $this->deployment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Deployment/CriticalCheck')
            ->where('total_steps', 5)
            ->where('device_count', 1)
            ->has('lag_results', 0)
            ->has('ethernet_results', 0)
            ->where('summary.lag_passed', 0)
            ->where('summary.lag_failed', 0)
            ->where('dns_site_collection_name', 'WCD'));
});

test('deployment critical check reports lag mismatch', function () {
    $lacpProfile = LacpProfile::factory()->create([
        'mode' => 'ACTIVE',
        'rate' => 'SLOW',
        'port_list' => '1/1/1-1/1/2',
        'trunk_type' => 'LACP',
    ]);
    $switchPort = SwitchPort::factory()->create([
        'interface_mode' => 'TRUNK',
        'access_vlan' => null,
        'native_vlan' => 10,
        'trunk_vlan_all' => 'true',
        'trunk_vlan_ranges' => null,
    ]);
    DeviceInterface::factory()->create([
        'device_id' => $this->device->id,
        'interface' => '10',
        'switch_port_id' => $switchPort->id,
        'lacp_profile_id' => $lacpProfile->id,
        'interface_kind' => InterfaceKind::LAG,
        'description' => null,
    ]);

    $mismatched = [
        'name' => '10',
        'vsx' => ['shutdown-on-split' => false],
        'switchport' => [
            'access-vlan' => null,
            'interface-mode' => 'TRUNK',
            'native-vlan' => 10,
            'trunk-vlan-all' => true,
            'trunk-vlan-ranges' => null,
        ],
        'lacp' => ['mode' => 'PASSIVE', 'rate' => 'SLOW'],
        'trunk-type' => 'LACP',
        'port-list' => ['1/1/1', '1/1/2'],
        'enable' => true,
    ];

    fakeCriticalCheckCentralApis([
        '*portchannels*' => Http::response(['interface' => [$mismatched]], 200),
    ]);

    $this->getJson(criticalCheckStepUrl($this->deployment, 1))
        ->assertOk()
        ->assertJsonPath('partial.lag_results.0.ok', false)
        ->assertJsonPath('partial.lag_results.0.diff.0.path', 'lacp.mode');
});

test('deployment critical check reports vlan mismatch', function () {
    DeviceInterface::factory()->create([
        'device_id' => $this->device->id,
        'interface' => '100',
        'interface_kind' => InterfaceKind::VLAN,
        'ip_address' => '10.0.0.1/24',
        'enable' => true,
    ]);

    fakeCriticalCheckCentralApis([
        '*vlan-interfaces*' => Http::response([
            'interface' => [
                [
                    'id' => '100',
                    'ipv4' => ['address' => '10.0.0.2/24'],
                    'enable' => true,
                    'is-valid' => true,
                ],
            ],
        ], 200),
    ]);

    $this->getJson(criticalCheckStepUrl($this->deployment, 2))
        ->assertOk()
        ->assertJsonPath('partial.vlan_results.0.ok', false)
        ->assertJsonPath('partial.vlan_results.0.diff.0.path', 'ipv4.address');
});

test('deployment critical check displays static routes from central', function () {
    fakeCriticalCheckCentralApis();

    $this->getJson(criticalCheckStepUrl($this->deployment, 3))
        ->assertOk()
        ->assertJsonPath('partial.static_routes.0.device_name', $this->device->name)
        ->assertJsonPath('partial.static_routes.0.error', null)
        ->assertJsonPath('partial.static_routes.0.source', 'device')
        ->assertJsonPath('partial.static_routes.0.routes.0.profile_name', 'static-profile')
        ->assertJsonPath('partial.static_routes.0.routes.0.prefix', '0.0.0.0/0')
        ->assertJsonPath('partial.static_routes.0.routes.0.next_hop', '192.168.1.1');
});

test('deployment critical check inherits static routes from site when device level is empty', function () {
    fakeCriticalCheckCentralApisWithStaticRouteHandler(function (\Illuminate\Http\Client\Request $request) {
        $query = staticRouteRequestQuery($request);

        if (($query['object-type'] ?? '') === 'LOCAL') {
            return Http::response([], 200);
        }

        if (($query['object-type'] ?? '') === 'SHARED'
            && ($query['scope-id'] ?? '') === 'site-scope-123') {
            return Http::response(staticRouteProfilePayload('site-static-profile', '10.0.0.0/8'), 200);
        }

        return Http::response([], 200);
    });

    $this->getJson(criticalCheckStepUrl($this->deployment, 3))
        ->assertOk()
        ->assertJsonPath('partial.static_routes.0.source', 'site')
        ->assertJsonPath('partial.static_routes.0.site_name', 'TestSite')
        ->assertJsonPath('partial.static_routes.0.routes.0.profile_name', 'site-static-profile')
        ->assertJsonPath('partial.static_routes.0.routes.0.prefix', '10.0.0.0/8');
});

test('deployment critical check inherits static routes from device group when device level is empty', function () {
    $this->device->update(['group' => 'WHSE-TEST-ACCESS']);

    Http::fake(function (\Illuminate\Http\Client\Request $request) {
        $url = $request->url();

        if (str_contains($url, 'device-groups')) {
            return Http::response([
                'items' => [
                    ['scopeName' => 'WHSE-TEST-ACCESS', 'scopeId' => 'group-scope-456'],
                ],
            ], 200);
        }

        if (str_contains($url, 'static-route')) {
            $query = staticRouteRequestQuery($request);

            if (($query['object-type'] ?? '') === 'LOCAL') {
                return Http::response([], 200);
            }

            if (($query['object-type'] ?? '') === 'SHARED'
                && ($query['scope-id'] ?? '') === 'group-scope-456') {
                return Http::response(
                    staticRouteProfilePayload('group-static-profile', '172.16.0.0/12', '10.0.0.1'),
                    200,
                );
            }

            return Http::response([], 200);
        }

        if (str_contains($url, 'portchannels')
            || str_contains($url, 'ethernet-interfaces')
            || str_contains($url, 'vlan-interfaces')) {
            return Http::response(['interface' => []], 200);
        }

        if (str_contains($url, 'site-collections')) {
            return Http::response([
                'items' => [
                    ['scopeName' => 'WCD', 'scopeId' => 'wcd-scope-from-central'],
                ],
            ], 200);
        }

        if (str_contains($url, 'dns')) {
            return Http::response(['profile' => []], 200);
        }

        return Http::response([], 200);
    });

    $this->getJson(criticalCheckStepUrl($this->deployment, 3))
        ->assertOk()
        ->assertJsonPath('partial.static_routes.0.source', 'group')
        ->assertJsonPath('partial.static_routes.0.group_name', 'WHSE-TEST-ACCESS')
        ->assertJsonPath('partial.static_routes.0.routes.0.profile_name', 'group-static-profile')
        ->assertJsonPath('partial.static_routes.0.routes.0.prefix', '172.16.0.0/12');

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        if (! str_contains($request->url(), 'static-route')) {
            return false;
        }
        $query = staticRouteRequestQuery($request);

        return ($query['object-type'] ?? '') === 'SHARED'
            && ($query['scope-id'] ?? '') === 'group-scope-456';
    });

    Http::assertNotSent(function (\Illuminate\Http\Client\Request $request) {
        if (! str_contains($request->url(), 'static-route')) {
            return false;
        }
        $query = staticRouteRequestQuery($request);

        return ($query['object-type'] ?? '') === 'SHARED'
            && ($query['scope-id'] ?? '') === 'site-scope-123';
    });
});

test('deployment critical check reports empty static route profile when device and site are empty', function () {
    fakeCriticalCheckCentralApisWithStaticRouteHandler(
        fn () => Http::response([], 200),
    );

    $this->getJson(criticalCheckStepUrl($this->deployment, 3))
        ->assertOk()
        ->assertJsonPath('partial.static_routes.0.source', null)
        ->assertJsonPath('partial.static_routes.0.routes', [])
        ->assertJsonPath(
            'partial.static_routes.0.error',
            'Empty static route profile for this device.',
        );
});

test('deployment critical check displays dns profiles from central', function () {
    fakeCriticalCheckCentralApis();

    $scope = $this->getJson(criticalCheckStepUrl($this->deployment, 0))
        ->assertOk()
        ->json();

    $this->getJson(criticalCheckStepUrl($this->deployment, 4, [
        'dns_scope_id' => $scope['partial']['dns_scope_id'],
    ]))
        ->assertOk()
        ->assertJsonPath('partial.dns_results.0.profiles.0.name', 'dns-profile')
        ->assertJsonPath('partial.dns_results.0.profiles.0.resolvers.0.vrf', 'default')
        ->assertJsonPath('partial.dns_results.0.profiles.0.resolvers.0.name_server_ips.0', '8.8.8.8');
});

test('deployment critical check resolves dns scope from wcd site collection on failure', function () {
    Http::fake(function (\Illuminate\Http\Client\Request $request) {
        $url = $request->url();

        if (str_contains($url, 'portchannels')) {
            return Http::response(['interface' => []], 200);
        }
        if (str_contains($url, 'ethernet-interfaces')) {
            return Http::response(['interface' => []], 200);
        }
        if (str_contains($url, 'vlan-interfaces')) {
            return Http::response(['interface' => []], 200);
        }
        if (str_contains($url, 'static-route')) {
            return Http::response(['profile' => []], 200);
        }
        if (str_contains($url, 'site-collections')) {
            return Http::response([
                'items' => [
                    ['scopeName' => 'WCD', 'scopeId' => 'wcd-scope-from-central'],
                ],
            ], 200);
        }
        if (str_contains($url, 'dns')) {
            if (str_contains($url, 'scope-id=73800600944427008') || str_contains($url, 'scope-id=73800600944427008')) {
                return Http::response(['message' => 'scope not found'], 404);
            }

            return Http::response([
                'profile' => [
                    [
                        'name' => 'dns-wcd',
                        'resolver' => [
                            ['vrf' => 'mgmt', 'name-server' => [['ip' => '1.1.1.1', 'priority' => 1]]],
                        ],
                    ],
                ],
            ], 200);
        }

        return Http::response([], 200);
    });

    $scope = $this->getJson(criticalCheckStepUrl($this->deployment, 0))
        ->assertOk()
        ->assertJsonPath('partial.dns_scope_id', 'wcd-scope-from-central')
        ->json();

    $this->getJson(criticalCheckStepUrl($this->deployment, 4, [
        'dns_scope_id' => $scope['partial']['dns_scope_id'],
    ]))
        ->assertOk()
        ->assertJsonPath('partial.dns_results.0.profiles.0.name', 'dns-wcd');

    Http::assertSent(fn (\Illuminate\Http\Client\Request $request) => str_contains($request->url(), 'site-collections'));
});

test('deployment critical check reports dns scope error when wcd not found', function () {
    Http::fake([
        '*portchannels*' => Http::response(['interface' => []], 200),
        '*ethernet-interfaces*' => Http::response(['interface' => []], 200),
        '*vlan-interfaces*' => Http::response(['interface' => []], 200),
        '*static-route*' => Http::response(['profile' => []], 200),
        '*site-collections*' => Http::response(['items' => [['scopeName' => 'Other', 'scopeId' => 'x']]], 200),
        '*dns*' => Http::response(['message' => 'failed'], 500),
    ]);

    $this->getJson(criticalCheckStepUrl($this->deployment, 0))
        ->assertOk()
        ->assertJsonPath('partial.dns_scope_error', 'Site collection "WCD" was not found in Central.');

    $this->getJson(criticalCheckStepUrl($this->deployment, 4, [
        'dns_scope_error' => 'Site collection "WCD" was not found in Central.',
    ]))
        ->assertOk()
        ->assertJsonPath('partial.dns_results', []);
});

test('deployment critical check resolves static routes at device level when site scope is missing', function () {
    $this->site->update(['scope_id' => null]);

    fakeCriticalCheckCentralApis();

    $this->getJson(criticalCheckStepUrl($this->deployment, 3))
        ->assertOk()
        ->assertJsonPath('partial.static_routes.0.error', null)
        ->assertJsonPath('partial.static_routes.0.source', 'device')
        ->assertJsonPath('partial.static_routes.0.routes.0.profile_name', 'static-profile');

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        if (! str_contains($request->url(), 'static-route')) {
            return false;
        }
        $query = staticRouteRequestQuery($request);

        return ($query['object-type'] ?? '') === 'LOCAL'
            && ($query['scope-id'] ?? '') === 'device-scope-123';
    });

    Http::assertSentCount(1, fn (\Illuminate\Http\Client\Request $request) => str_contains($request->url(), 'static-route'));
});

test('deployment critical check reports ethernet match when include_ethernet is enabled', function () {
    $switchPort = SwitchPort::factory()->create([
        'interface_mode' => 'ACCESS',
        'access_vlan' => 100,
        'native_vlan' => null,
        'trunk_vlan_all' => null,
        'trunk_vlan_ranges' => null,
    ]);
    DeviceInterface::factory()->create([
        'device_id' => $this->device->id,
        'interface' => '1/1/1',
        'switch_port_id' => $switchPort->id,
        'interface_kind' => InterfaceKind::ETHERNET,
        'description' => 'Access port',
        'shutdown_on_split' => false,
    ]);

    $centralEthernet = [
        'name' => '1/1/1',
        'description' => 'Access port',
        'vsx' => ['shutdown-on-split' => false],
        'switchport' => [
            'access-vlan' => 100,
            'interface-mode' => 'ACCESS',
            'native-vlan' => null,
            'trunk-vlan-all' => null,
            'trunk-vlan-ranges' => null,
        ],
        'stp' => [],
    ];

    fakeCriticalCheckCentralApis([
        '*ethernet-interfaces*' => Http::response(['interface' => [$centralEthernet]], 200),
    ]);

    $this->getJson(criticalCheckStepUrl($this->deployment, 2, ['include_ethernet' => '1']))
        ->assertOk()
        ->assertJsonPath('partial.ethernet_results.0.ok', true)
        ->assertJsonPath('progress.message', 'Checking ethernet interfaces for '.$this->device->name.'...');
});

test('deployment critical check reports ethernet mismatch when include_ethernet is enabled', function () {
    $switchPort = SwitchPort::factory()->create([
        'interface_mode' => 'ACCESS',
        'access_vlan' => 100,
        'native_vlan' => null,
        'trunk_vlan_all' => null,
        'trunk_vlan_ranges' => null,
    ]);
    DeviceInterface::factory()->create([
        'device_id' => $this->device->id,
        'interface' => '1/1/2',
        'switch_port_id' => $switchPort->id,
        'interface_kind' => InterfaceKind::ETHERNET,
        'shutdown_on_split' => false,
    ]);

    $mismatched = [
        'name' => '1/1/2',
        'vsx' => ['shutdown-on-split' => false],
        'switchport' => [
            'access-vlan' => 200,
            'interface-mode' => 'ACCESS',
            'native-vlan' => null,
            'trunk-vlan-all' => null,
            'trunk-vlan-ranges' => null,
        ],
        'stp' => [],
    ];

    fakeCriticalCheckCentralApis([
        '*ethernet-interfaces*' => Http::response(['interface' => [$mismatched]], 200),
    ]);

    $response = $this->getJson(criticalCheckStepUrl($this->deployment, 2, ['include_ethernet' => '1']))
        ->assertOk()
        ->assertJsonPath('partial.ethernet_results.0.ok', false);

    $paths = collect($response->json('partial.ethernet_results.0.diff'))->pluck('path')->all();
    expect($paths)->toContain('switchport.access-vlan');
});

test('deployment critical check skips ethernet phase when include_ethernet is not set', function () {
    $switchPort = SwitchPort::factory()->create([
        'interface_mode' => 'ACCESS',
        'access_vlan' => 50,
        'native_vlan' => null,
        'trunk_vlan_all' => null,
        'trunk_vlan_ranges' => null,
    ]);
    DeviceInterface::factory()->create([
        'device_id' => $this->device->id,
        'interface' => '1/1/9',
        'switch_port_id' => $switchPort->id,
        'interface_kind' => InterfaceKind::ETHERNET,
    ]);

    fakeCriticalCheckCentralApis();

    $this->getJson(criticalCheckStepUrl($this->deployment, 2))
        ->assertOk()
        ->assertJsonPath('progress.message', 'Checking VLAN interfaces for '.$this->device->name.'...')
        ->assertJsonMissingPath('partial.ethernet_results');
});

test('deployment critical check redirects when current client does not match deployment', function () {
    $otherClient = Client::factory()->for($this->user)->create(['current' => true]);
    $this->client->update(['current' => false]);

    fakeCriticalCheckCentralApis();

    $this->get(route('deployments.critical_check', $this->deployment))
        ->assertRedirect(route('deployments.index'))
        ->assertSessionHas('error');

    expect($otherClient->fresh()->current)->toBeTrue();
});
