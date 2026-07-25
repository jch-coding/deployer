<?php

use App\BaseURL;
use App\Models\CentralScopeCache;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
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
    Http::fake([
        '*site-collections*' => Http::response([
            'items' => [
                ['scopeName' => 'WCD Collection', 'scopeId' => 'scope-collection'],
            ],
        ], 200),
    ]);
});

test('migrations index redirects when no current client is set', function () {
    $this->client->update(['current' => false]);

    $this->get(route('migrations.index'))
        ->assertRedirect(route('clients.index'));
});

test('migrations index renders with site options', function () {
    $this->get(route('migrations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Migration/Index')
            ->where('parsed_controllers', [])
            ->where('deploy_results', [])
            ->where('named_vlan_deploy_results', [])
            ->where('last_created_deployment', null)
            ->has('site_options', 1)
            ->where('site_options.0.siteId', 'scope-site')
            ->where('site_options.0.siteName', 'Central Site')
            ->has('device_group_options', 2)
            ->where('device_group_options.0.scopeName', 'Central Group')
            ->has('site_collection_options', 1)
            ->where('site_collection_options.0.scopeId', 'scope-collection')
            ->has('device_function_options')
            ->where('device_function_options.1', 'CAMPUS_AP'));
});

test('migrations parse uploads config file and returns parsed controllers', function () {
    $file = new UploadedFile(
        base_path('tests/fixtures/daytona_config.txt'),
        'daytona_config.txt',
        'text/plain',
        null,
        true,
    );

    $this->post(route('migrations.parse'), [
        'config_file' => $file,
    ])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Migration/Index')
            ->has('parsed_controllers', 1)
            ->where('parsed_controllers.0.controller_name', 'DAY-HUB-WLC1')
            ->has('parsed_controllers.0.devices', 106)
            ->has('parsed_controllers.0.lldp_neighbors')
            ->has('parsed_controllers.0.auth_servers')
            ->has('parsed_controllers.0.wlan_profiles'));
});

test('migrations deploy wlan posts profiles to central with scope query parameters', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $body = migrationWlanProfilePayload();

    $this->post(route('migrations.deploy-wlan'), [
        'scope_id' => 'scope-site',
        'profiles' => [
            [
                'ssid_profile_name' => 'DAYKIT',
                'body' => $body,
            ],
        ],
        'parsed_controllers' => [],
    ])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Migration/Index')
            ->has('deploy_results', 1)
            ->where('deploy_results.0.ssid', 'DAYKIT')
            ->where('deploy_results.0.status', 'success'));

    Http::assertSent(function (Request $request) use ($body) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return $request->method() === 'POST'
            && str_contains($request->url(), 'network-config/v1alpha1/wlan-ssids/DAYKIT')
            && ($query['object-type'] ?? null) === 'LOCAL'
            && ($query['view-type'] ?? null) === 'LOCAL'
            && ($query['scope-id'] ?? null) === 'scope-site'
            && ($query['device-function'] ?? null) === 'CAMPUS_AP'
            && json_decode($request->body(), true) === $body;
    });
});

test('migrations deploy wlan posts subset of profiles when caller sends partial selection', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $body = migrationWlanProfilePayload();

    $this->post(route('migrations.deploy-wlan'), [
        'scope_id' => 'scope-site',
        'profiles' => [
            [
                'ssid_profile_name' => 'DAYKIT',
                'body' => $body,
            ],
        ],
        'parsed_controllers' => [],
    ])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('deploy_results', 1)
            ->where('deploy_results.0.ssid', 'DAYKIT')
            ->where('deploy_results.0.status', 'success'));

    Http::assertSent(function (Request $request) use ($body) {
        return $request->method() === 'POST'
            && str_contains($request->url(), 'network-config/v1alpha1/wlan-ssids/DAYKIT')
            && json_decode($request->body(), true) === $body;
    });

    Http::assertNotSent(function (Request $request) {
        return str_contains($request->url(), 'wlan-ssids/DAYWCD');
    });
});

test('migrations deploy wlan skips profiles missing passphrase or vlan', function () {
    Http::fake([
        '*site-collections*' => Http::response(['items' => []], 200),
    ]);

    $this->post(route('migrations.deploy-wlan'), [
        'scope_id' => 'scope-site',
        'profiles' => [
            [
                'ssid_profile_name' => 'incomplete-profile',
                'body' => [
                    'vlan-name' => null,
                    'personal-security' => ['wpa-passphrase' => null],
                ],
            ],
        ],
        'parsed_controllers' => [],
    ])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('deploy_results.0.status', 'skipped'));

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'wlan-ssids'));
});

test('migrations deploy wlan patches profile when post fails', function () {
    Http::fake(function (Request $request) {
        if ($request->method() === 'POST' && str_contains($request->url(), 'wlan-ssids/DAYKIT')) {
            return Http::response(['error' => 'already exists'], 409);
        }

        if ($request->method() === 'PATCH' && str_contains($request->url(), 'wlan-ssids/DAYKIT')) {
            return Http::response(['ok' => true], 200);
        }

        return Http::response(['ok' => true], 200);
    });

    $body = migrationWlanProfilePayload();

    $this->post(route('migrations.deploy-wlan'), [
        'scope_id' => 'scope-site',
        'profiles' => [
            [
                'ssid_profile_name' => 'DAYKIT',
                'body' => $body,
            ],
        ],
        'parsed_controllers' => [],
    ])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('deploy_results.0.ssid', 'DAYKIT')
            ->where('deploy_results.0.status', 'success')
            ->where('deploy_results.0.message', 'Updated successfully'));

    Http::assertSent(function (Request $request) use ($body) {
        return $request->method() === 'POST'
            && str_contains($request->url(), 'network-config/v1alpha1/wlan-ssids/DAYKIT')
            && json_decode($request->body(), true) === $body;
    });

    Http::assertSent(function (Request $request) use ($body) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return $request->method() === 'PATCH'
            && str_contains($request->url(), 'network-config/v1alpha1/wlan-ssids/DAYKIT')
            && ($query['object-type'] ?? null) === 'LOCAL'
            && ($query['view-type'] ?? null) === 'LOCAL'
            && ($query['scope-id'] ?? null) === 'scope-site'
            && ($query['device-function'] ?? null) === 'CAMPUS_AP'
            && json_decode($request->body(), true) === $body;
    });
});

test('migrations deploy wlan returns error when post and patch both fail', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'wlan-ssids/DAYKIT')) {
            return Http::response(['error' => 'server error'], 500);
        }

        if (str_contains($request->url(), 'site-collections')) {
            return Http::response([
                'items' => [
                    ['scopeName' => 'WCD Collection', 'scopeId' => 'scope-collection'],
                ],
            ], 200);
        }

        return Http::response(['ok' => true], 200);
    });

    $body = migrationWlanProfilePayload();

    $this->post(route('migrations.deploy-wlan'), [
        'scope_id' => 'scope-site',
        'profiles' => [
            [
                'ssid_profile_name' => 'DAYKIT',
                'body' => $body,
            ],
        ],
        'parsed_controllers' => [],
    ])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('deploy_results.0.ssid', 'DAYKIT')
            ->where('deploy_results.0.status', 'error'));

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_contains($request->url(), 'wlan-ssids/DAYKIT'));

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && str_contains($request->url(), 'wlan-ssids/DAYKIT'));
});

test('migrations deploy auth server step posts to central with scope and device function', function () {
    Http::fake([
        '*site-collections*' => Http::response([
            'items' => [
                ['scopeName' => 'WCD Collection', 'scopeId' => 'scope-collection'],
            ],
        ], 200),
        '*auth-servers*' => Http::response(['ok' => true], 200),
    ]);

    $body = migrationAuthServerPayload();

    $this->post(route('migrations.deploy-auth-servers.step', ['step' => 0]), [
        'scope_id' => 'scope-collection',
        'device_function' => 'CAMPUS_AP',
        'servers' => [
            [
                'name' => 'ECPPM',
                'body' => $body,
            ],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('step.key', 'auth-server-ECPPM')
        ->assertJsonPath('step.status', 'success')
        ->assertJsonPath('partial.deploy_results.0.name', 'ECPPM')
        ->assertJsonPath('partial.deploy_results.0.status', 'success');

    Http::assertSent(function (Request $request) use ($body) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return $request->method() === 'POST'
            && str_contains($request->url(), 'network-config/v1alpha1/auth-servers/ECPPM')
            && ($query['object-type'] ?? null) === 'LOCAL'
            && ($query['view-type'] ?? null) === 'LOCAL'
            && ($query['scope-id'] ?? null) === 'scope-collection'
            && ($query['device-function'] ?? null) === 'CAMPUS_AP'
            && json_decode($request->body(), true) === $body;
    });
});

test('migrations deploy auth server step patches when post fails', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'site-collections')) {
            return Http::response([
                'items' => [
                    ['scopeName' => 'WCD Collection', 'scopeId' => 'scope-collection'],
                ],
            ], 200);
        }

        if ($request->method() === 'POST' && str_contains($request->url(), 'auth-servers/ECPPM')) {
            return Http::response(['error' => 'already exists'], 409);
        }

        if ($request->method() === 'PATCH' && str_contains($request->url(), 'auth-servers/ECPPM')) {
            return Http::response(['ok' => true], 200);
        }

        return Http::response(['ok' => true], 200);
    });

    $body = migrationAuthServerPayload();

    $this->post(route('migrations.deploy-auth-servers.step', ['step' => 0]), [
        'scope_id' => 'scope-site',
        'device_function' => 'BRANCH_GW',
        'servers' => [
            [
                'name' => 'ECPPM',
                'body' => $body,
            ],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('partial.deploy_results.0.status', 'success')
        ->assertJsonPath('partial.deploy_results.0.message', 'Updated successfully');

    Http::assertSent(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return $request->method() === 'PATCH'
            && str_contains($request->url(), 'network-config/v1alpha1/auth-servers/ECPPM')
            && ($query['device-function'] ?? null) === 'BRANCH_GW'
            && ($query['scope-id'] ?? null) === 'scope-site';
    });
});

test('migrations deploy auth server step skips servers missing host or key', function () {
    Http::fake([
        '*site-collections*' => Http::response(['items' => []], 200),
    ]);

    $this->post(route('migrations.deploy-auth-servers.step', ['step' => 0]), [
        'scope_id' => 'scope-site',
        'device_function' => 'CAMPUS_AP',
        'servers' => [
            [
                'name' => 'incomplete',
                'body' => [
                    'auth-server-address' => null,
                    'shared-secret-config' => ['plaintext-value' => null],
                    'type' => 'RADIUS',
                ],
            ],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('partial.deploy_results.0.status', 'skipped');

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'auth-servers'));
});

function seedMigrationSiteCache(Client $client, string $scopeName, string $scopeId): void
{
    CentralScopeCache::query()
        ->where('client_id', $client->id)
        ->where('type', \App\CentralScopeCacheType::Sites)
        ->update([
            'items' => [
                ['scopeName' => $scopeName, 'scopeId' => $scopeId],
            ],
        ]);
}

function migrationWlanProfilePayload(): array
{
    return [
        'ssid' => 'DAYKIT',
        'enable' => true,
        'forward-mode' => 'FORWARD_MODE_BRIDGE',
        'dmo' => [
            'enable' => false,
            'channel-utilization-threshold' => 90,
            'clients-threshold' => 6,
        ],
        'broadcast-filter-ipv4' => 'BCAST_FILTER_ARP',
        'local-proxy-ns' => false,
        'optimize-mcast-rate' => false,
        'ssid-utf8' => true,
        'essid' => ['name' => 'DAYKIT'],
        'advertise-apname' => true,
        'disable-on-6ghz-mesh' => false,
        'dot11k' => false,
        'dot11r' => false,
        'dtim-period' => 1,
        'ftm-responder' => false,
        'hide-ssid' => false,
        'explicit-ageout-client' => false,
        'inactivity-timeout' => 1000,
        'max-clients-threshold' => 128,
        'rf-band' => '24GHZ_5GHZ',
        'high-throughput' => ['enable' => true, 'very-high-throughput' => true],
        'g-legacy-rates' => [
            'basic-rates' => ['RATE_12MB', 'RATE_24MB'],
            'tx-rates' => ['RATE_12MB', 'RATE_18MB', 'RATE_24MB', 'RATE_36MB', 'RATE_48MB', 'RATE_54MB'],
        ],
        'a-legacy-rates' => ['basic-rates' => ['RATE_12MB'], 'tx-rates' => ['RATE_12MB']],
        'high-efficiency' => ['enable' => true],
        'extremely-high-throughput' => ['enable' => false, 'mlo' => false],
        'wmm-cfg' => ['uapsd' => false],
        'advertise-timing' => false,
        'opmode' => 'WPA2_PERSONAL',
        'mac-authentication' => false,
        'personal-security' => [
            'passphrase-format' => 'STRING',
            'wpa-passphrase' => 'secret-passphrase',
        ],
        'type' => 'EMPLOYEE',
        'use-ip-for-calling-station-id' => false,
        'server-load-balancing' => false,
        'called-station-id' => [
            'type' => 'MAC_ADDRESS',
            'include-ssid' => false,
        ],
        'cloud-auth' => false,
        'denylist' => false,
        'enforce-dhcp' => false,
        'pan' => false,
        'vlan-selector' => 'NAMED_VLAN',
        'vlan-name' => 'WCD_KIT',
        'client-isolation' => false,
    ];
}

function migrationAuthServerPayload(): array
{
    return [
        'auth-server-address' => '10.232.188.4',
        'enable' => true,
        'name' => 'ECPPM',
        'shared-secret-config' => [
            'plaintext-value' => 'r3@LcH0c0L@t315tH3B35t',
            'secret-type' => 'PLAIN_TEXT',
        ],
        'type' => 'RADIUS',
        'dynamic-authorization-enable' => true,
        'radius-server-mode' => 'AUTH_AND_COA',
    ];
}

/**
 * @param  array<int, array{name: string, vlan: array<string, mixed>}>  $namedVlanProfiles
 */
function fakeMigrationFreezerCentralApi(
    string $siteScopeId = 'scope-freezer',
    string $collectionScopeId = 'scope-collection',
    array $namedVlanProfiles = [],
): void {
    Http::fake(function (Request $request) use ($siteScopeId, $collectionScopeId, $namedVlanProfiles) {
        if ($request->method() === 'GET' && str_contains($request->url(), 'network-config/v1/hierarchy')) {
            return Http::response([
                'items' => [
                    [
                        'hierarchy' => [
                            [
                                'scopeName' => 'Daytona Freezer',
                                'scopeType' => 'site',
                                'scopeId' => $siteScopeId,
                            ],
                            [
                                'scopeName' => 'WCD',
                                'scopeType' => 'site_collection',
                                'scopeId' => $collectionScopeId,
                            ],
                        ],
                    ],
                ],
            ], 200);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), 'named-vlan') && ! str_contains($request->url(), 'named-vlan/')) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $scopeId = $query['scope-id'] ?? null;

            if ($scopeId === $collectionScopeId && $namedVlanProfiles !== []) {
                return Http::response(['profile' => $namedVlanProfiles], 200);
            }

            if ($scopeId === $siteScopeId && $namedVlanProfiles !== []) {
                return Http::response(['profile' => $namedVlanProfiles], 200);
            }

            return Http::response(['profile' => []], 200);
        }

        return Http::response(['ok' => true], 200);
    });
}

test('migrations deploy wlan triggers named vlan offset for freezer sites', function () {
    seedMigrationSiteCache($this->client, 'Daytona Freezer', 'scope-freezer');

    fakeMigrationFreezerCentralApi(namedVlanProfiles: [
        [
            'name' => 'WCD_KIT',
            'vlan' => ['vlan-id-ranges' => ['1']],
        ],
    ]);

    $this->post(route('migrations.deploy-wlan'), [
        'scope_id' => 'scope-freezer',
        'profiles' => [
            [
                'ssid_profile_name' => 'DAYKIT',
                'body' => migrationWlanProfilePayload(),
            ],
        ],
        'parsed_controllers' => [],
    ])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('named_vlan_deploy_results', 1)
            ->where('named_vlan_deploy_results.0.name', 'WCD_KIT')
            ->where('named_vlan_deploy_results.0.status', 'success'));

    Http::assertSent(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return $request->method() === 'GET'
            && str_contains($request->url(), 'network-config/v1/hierarchy')
            && ($query['id'] ?? null) === 'scope-freezer'
            && ($query['type'] ?? null) === 'site';
    });

    Http::assertSent(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return $request->method() === 'POST'
            && str_contains($request->url(), 'network-config/v1alpha1/named-vlan/WCD_KIT')
            && ($query['object-type'] ?? null) === 'LOCAL'
            && json_decode($request->body(), true) === [
                'name' => 'WCD_KIT',
                'vlan' => ['vlan-id-ranges' => ['201']],
            ];
    });
});

test('migrations deploy wlan only offsets named vlans referenced by deployed wlan bodies', function () {
    seedMigrationSiteCache($this->client, 'Daytona Freezer', 'scope-freezer');

    fakeMigrationFreezerCentralApi(namedVlanProfiles: [
        [
            'name' => 'WCD_KIT',
            'vlan' => ['vlan-id-ranges' => ['1']],
        ],
        [
            'name' => 'WCD_RF',
            'vlan' => ['vlan-id-ranges' => ['4']],
        ],
    ]);

    $this->post(route('migrations.deploy-wlan'), [
        'scope_id' => 'scope-freezer',
        'profiles' => [
            [
                'ssid_profile_name' => 'DAYKIT',
                'body' => migrationWlanProfilePayload(),
            ],
        ],
        'parsed_controllers' => [],
    ])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('named_vlan_deploy_results', 1)
            ->where('named_vlan_deploy_results.0.name', 'WCD_KIT')
            ->where('named_vlan_deploy_results.0.status', 'success'));

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), 'network-config/v1alpha1/named-vlan/WCD_KIT');
    });

    Http::assertNotSent(function (Request $request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), 'network-config/v1alpha1/named-vlan/WCD_RF');
    });
});

test('migrations deploy wlan skips named vlan offset for non-freezer sites', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $this->post(route('migrations.deploy-wlan'), [
        'scope_id' => 'scope-site',
        'profiles' => [
            [
                'ssid_profile_name' => 'DAYKIT',
                'body' => migrationWlanProfilePayload(),
            ],
        ],
        'parsed_controllers' => [],
    ])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('named_vlan_deploy_results', []));

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), 'wlan-ssids/');
    });

    Http::assertNotSent(function (Request $request) {
        return str_contains($request->url(), 'named-vlan');
    });
});

test('migrations deploy wlan skips named vlan offset for hub-freezer sites', function () {
    seedMigrationSiteCache($this->client, 'Hub-Freezer', 'scope-hub-freezer');

    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $this->post(route('migrations.deploy-wlan'), [
        'scope_id' => 'scope-hub-freezer',
        'profiles' => [
            [
                'ssid_profile_name' => 'DAYKIT',
                'body' => migrationWlanProfilePayload(),
            ],
        ],
        'parsed_controllers' => [],
    ])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('named_vlan_deploy_results', []));

    Http::assertNotSent(function (Request $request) {
        return str_contains($request->url(), 'named-vlan');
    });
});

test('migrations deploy wlan step 0 returns json progress and partial deploy result', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $body = migrationWlanProfilePayload();

    $this->postJson(route('migrations.deploy-wlan.step', ['step' => 0]), [
        'scope_id' => 'scope-site',
        'profiles' => [
            [
                'ssid_profile_name' => 'DAYKIT',
                'body' => $body,
            ],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('progress.current', 1)
        ->assertJsonPath('progress.total', 1)
        ->assertJsonPath('progress.percent', 100)
        ->assertJsonPath('step.key', 'wlan-DAYKIT')
        ->assertJsonPath('step.status', 'success')
        ->assertJsonPath('partial.deploy_results.0.ssid', 'DAYKIT')
        ->assertJsonPath('partial.deploy_results.0.status', 'success');

    Http::assertSent(function (Request $request) use ($body) {
        return $request->method() === 'POST'
            && str_contains($request->url(), 'network-config/v1alpha1/wlan-ssids/DAYKIT')
            && json_decode($request->body(), true) === $body;
    });
});

test('migrations deploy wlan step endpoint deploys multiple profiles across steps', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $profiles = [
        [
            'ssid_profile_name' => 'DAYKIT',
            'body' => migrationWlanProfilePayload(),
        ],
        [
            'ssid_profile_name' => 'DAYRF',
            'body' => array_merge(migrationWlanProfilePayload(), [
                'essid' => ['name' => 'DAYRF'],
                'ssid' => 'DAYRF',
                'vlan-name' => 'WCD_RF',
            ]),
        ],
    ];

    $this->postJson(route('migrations.deploy-wlan.step', ['step' => 0]), [
        'scope_id' => 'scope-site',
        'profiles' => $profiles,
    ])
        ->assertOk()
        ->assertJsonPath('progress.current', 1)
        ->assertJsonPath('progress.total', 2)
        ->assertJsonPath('partial.deploy_results.0.ssid', 'DAYKIT');

    $this->postJson(route('migrations.deploy-wlan.step', ['step' => 1]), [
        'scope_id' => 'scope-site',
        'profiles' => $profiles,
    ])
        ->assertOk()
        ->assertJsonPath('progress.current', 2)
        ->assertJsonPath('progress.total', 2)
        ->assertJsonPath('progress.percent', 100)
        ->assertJsonPath('partial.deploy_results.0.ssid', 'DAYRF');

    Http::assertSentCount(2);
});

test('migrations deploy wlan step fetch increases total for freezer sites', function () {
    seedMigrationSiteCache($this->client, 'Daytona Freezer', 'scope-freezer');

    fakeMigrationFreezerCentralApi(namedVlanProfiles: [
        [
            'name' => 'WCD_KIT',
            'vlan' => ['vlan-id-ranges' => ['1']],
        ],
    ]);

    $profiles = [
        [
            'ssid_profile_name' => 'DAYKIT',
            'body' => migrationWlanProfilePayload(),
        ],
    ];

    $this->postJson(route('migrations.deploy-wlan.step', ['step' => 0]), [
        'scope_id' => 'scope-freezer',
        'profiles' => $profiles,
    ])
        ->assertOk()
        ->assertJsonPath('progress.current', 1)
        ->assertJsonPath('progress.total', 2);

    $fetchResponse = $this->postJson(route('migrations.deploy-wlan.step', ['step' => 1]), [
        'scope_id' => 'scope-freezer',
        'profiles' => $profiles,
        'context' => ['named_vlan_profiles' => []],
    ])
        ->assertOk()
        ->assertJsonPath('step.key', 'named-vlan-fetch')
        ->assertJsonPath('progress.total', 3)
        ->assertJsonPath('context.named_vlan_profiles.0.name', 'WCD_KIT');

    $this->postJson(route('migrations.deploy-wlan.step', ['step' => 2]), [
        'scope_id' => 'scope-freezer',
        'profiles' => $profiles,
        'context' => $fetchResponse->json('context'),
    ])
        ->assertOk()
        ->assertJsonPath('step.key', 'named-vlan-WCD_KIT')
        ->assertJsonPath('partial.named_vlan_deploy_results.0.name', 'WCD_KIT')
        ->assertJsonPath('partial.named_vlan_deploy_results.0.status', 'success')
        ->assertJsonPath('progress.percent', 100);

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), 'network-config/v1alpha1/named-vlan/WCD_KIT');
    });
});
