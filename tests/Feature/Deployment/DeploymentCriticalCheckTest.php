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
        '*portchannels*' => Http::response(['items' => []], 200),
        '*vlan-interfaces*' => Http::response(['items' => []], 200),
        '*static-route*' => Http::response([
            'profile' => [
                'name' => 'static-profile',
                'ipv4' => [
                    'prefix' => '0.0.0.0/0',
                    'prefix-vrf-nexthop-id' => '1',
                ],
            ],
        ], 200),
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
        '*portchannels*' => Http::response(['items' => [$centralLag]], 200),
    ]);

    $this->getJson(route('deployments.critical_check.step', [$this->deployment, 1]))
        ->assertOk()
        ->assertJsonPath('partial.lag_results.0.ok', true)
        ->assertJsonPath('progress.message', 'Checking LAG interfaces for '.$this->device->name.'...');
});

test('deployment critical check step endpoint returns progress', function () {
    fakeCriticalCheckCentralApis();

    $this->getJson(route('deployments.critical_check.step', [$this->deployment, 0]))
        ->assertOk()
        ->assertJsonPath('progress.current', 1)
        ->assertJsonPath('progress.message', 'Resolving DNS scope ID...')
        ->assertJsonPath('partial.dns_scope_id', '73800600944427008');
});

test('deployment critical check page loads immediately without results', function () {
    $this->get(route('deployments.critical_check', $this->deployment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Deployment/CriticalCheck')
            ->where('total_steps', 5)
            ->has('lag_results', 0)
            ->where('summary.lag_passed', 0)
            ->where('summary.lag_failed', 0));
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
        '*portchannels*' => Http::response(['items' => [$mismatched]], 200),
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
            'items' => [
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
        ->assertJsonPath('partial.static_routes.0.routes.0.profile_name', 'static-profile')
        ->assertJsonPath('partial.static_routes.0.routes.0.prefix', '0.0.0.0/0');
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
            return Http::response(['items' => []], 200);
        }
        if (str_contains($url, 'vlan-interfaces')) {
            return Http::response(['items' => []], 200);
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
        '*portchannels*' => Http::response(['items' => []], 200),
        '*vlan-interfaces*' => Http::response(['items' => []], 200),
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

test('deployment critical check reports missing site scope for static routes', function () {
    $this->site->update(['scope_id' => null]);

    fakeCriticalCheckCentralApis();

    $this->getJson(criticalCheckStepUrl($this->deployment, 3))
        ->assertOk()
        ->assertJsonPath('partial.static_routes.0.error', 'Site or site scope ID not available for this device.')
        ->assertJsonPath('partial.static_routes.0.routes', []);

    Http::assertNotSent(fn (\Illuminate\Http\Client\Request $request) => str_contains($request->url(), 'static-route'));
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
