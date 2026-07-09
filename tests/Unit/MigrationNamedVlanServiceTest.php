<?php

use App\BaseURL;
use App\Helper\CentralAPIHelper;
use App\Models\Client;
use App\Services\MigrationNamedVlanService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('identifies freezer sites excluding hub-freezer', function () {
    expect(MigrationNamedVlanService::isFreezerSite('Daytona Freezer'))->toBeTrue()
        ->and(MigrationNamedVlanService::isFreezerSite('Store Freezer 01'))->toBeTrue()
        ->and(MigrationNamedVlanService::isFreezerSite('Hub-Freezer'))->toBeFalse()
        ->and(MigrationNamedVlanService::isFreezerSite('Central Site'))->toBeFalse();
});

it('offsets single vlan id ranges by adding 200', function () {
    expect(MigrationNamedVlanService::offsetVlanIdRanges(['1', '4']))
        ->toBe(['201', '204']);
});

it('offsets both ends of vlan id range strings', function () {
    expect(MigrationNamedVlanService::offsetVlanIdRanges(['20-30', '1']))
        ->toBe(['220-230', '201']);
});

it('preserves unparseable vlan id range entries', function () {
    expect(MigrationNamedVlanService::offsetVlanIdRanges(['invalid']))
        ->toBe(['invalid']);
});

it('extracts unique vlan names from wlan profile bodies', function () {
    $profiles = [
        ['ssid_profile_name' => 'DAYKIT', 'body' => ['vlan-name' => 'WCD_KIT']],
        ['ssid_profile_name' => 'DAYRF', 'body' => ['vlan-name' => 'WCD_RF']],
        ['ssid_profile_name' => 'DAYKIT2', 'body' => ['vlan-name' => 'WCD_KIT']],
    ];

    expect(MigrationNamedVlanService::vlanNamesFromWlanProfiles($profiles))
        ->toBe(['WCD_KIT', 'WCD_RF']);
});

/**
 * @return array<string, mixed>
 */
function migrationHierarchyResponseFixture(string $siteScopeId, string $collectionScopeId): array
{
    return [
        'items' => [
            [
                'id' => 'hierarchy0',
                'type' => 'network-config/hierarchy',
                'hierarchy' => [
                    [
                        'scopeName' => 'Daytona Freezer',
                        'scopeType' => 'site',
                        'childCount' => 1,
                        'scopeId' => $siteScopeId,
                        'hostName' => '',
                    ],
                    [
                        'scopeName' => 'WCD',
                        'scopeType' => 'site_collection',
                        'childCount' => 10,
                        'scopeId' => $collectionScopeId,
                        'hostName' => '',
                    ],
                ],
            ],
        ],
    ];
}

/**
 * @return array<int, array{ssid_profile_name: string, body: array<string, mixed>}>
 */
function migrationWlanProfilesForNamedVlanTests(): array
{
    return [
        [
            'ssid_profile_name' => 'DAYKIT',
            'body' => ['vlan-name' => 'WCD_KIT'],
        ],
        [
            'ssid_profile_name' => 'DAYRF',
            'body' => ['vlan-name' => 'WCD_RF'],
        ],
    ];
}

it('deploys offset named vlan profiles referenced by wlan bodies', function () {
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET' && str_contains($request->url(), 'network-config/v1/hierarchy')) {
            return Http::response(migrationHierarchyResponseFixture('scope-freezer', 'scope-collection'), 200);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), 'named-vlan') && ! str_contains($request->url(), 'named-vlan/')) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $scopeId = $query['scope-id'] ?? null;

            if ($scopeId === 'scope-collection') {
                return Http::response([
                    'profile' => [
                        [
                            'name' => 'WCD_KIT',
                            'vlan' => ['vlan-id-ranges' => ['1', '20-30']],
                        ],
                        [
                            'name' => 'WCD_RF',
                            'vlan' => ['vlan-id-ranges' => ['4']],
                        ],
                    ],
                ], 200);
            }

            return Http::response(['profile' => []], 200);
        }

        return Http::response(['ok' => true], 200);
    });

    $client = Client::factory()->create([
        'base_url' => BaseURL::US1,
        'bearer_token' => 'test-bearer-token',
        'expires_at' => now()->addHour(),
    ]);

    $helper = new CentralAPIHelper($client);
    $results = app(MigrationNamedVlanService::class)->deployOffsetNamedVlans(
        $helper,
        'scope-freezer',
        migrationWlanProfilesForNamedVlanTests(),
    );

    expect($results)->toHaveCount(2)
        ->and($results[0])->toMatchArray([
            'name' => 'WCD_KIT',
            'status' => 'success',
        ])
        ->and($results[1])->toMatchArray([
            'name' => 'WCD_RF',
            'status' => 'success',
        ]);

    Http::assertSent(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return $request->method() === 'GET'
            && str_contains($request->url(), 'network-config/v1/hierarchy')
            && ($query['id'] ?? null) === 'scope-freezer'
            && ($query['type'] ?? null) === 'site';
    });

    Http::assertSent(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return $request->method() === 'GET'
            && str_contains($request->url(), 'network-config/v1alpha1/named-vlan')
            && ! str_contains($request->url(), 'network-config/v1alpha1/named-vlan/')
            && ($query['view-type'] ?? null) === 'LOCAL'
            && ($query['scope-id'] ?? null) === 'scope-freezer'
            && ($query['device-function'] ?? null) === 'CAMPUS_AP'
            && ! array_key_exists('object-type', $query);
    });

    Http::assertSent(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return $request->method() === 'GET'
            && str_contains($request->url(), 'network-config/v1alpha1/named-vlan')
            && ! str_contains($request->url(), 'network-config/v1alpha1/named-vlan/')
            && ($query['scope-id'] ?? null) === 'scope-collection'
            && ($query['object-type'] ?? null) === 'SHARED';
    });

    Http::assertSent(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return $request->method() === 'POST'
            && str_contains($request->url(), 'network-config/v1alpha1/named-vlan/WCD_KIT')
            && ($query['view-type'] ?? null) === 'LOCAL'
            && ($query['object-type'] ?? null) === 'LOCAL'
            && ($query['scope-id'] ?? null) === 'scope-freezer'
            && ($query['device-function'] ?? null) === 'CAMPUS_AP'
            && json_decode($request->body(), true) === [
                'name' => 'WCD_KIT',
                'vlan' => ['vlan-id-ranges' => ['201', '220-230']],
            ];
    });
});

it('prefers site named vlan profiles over site collection profiles with the same name', function () {
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET' && str_contains($request->url(), 'network-config/v1/hierarchy')) {
            return Http::response(migrationHierarchyResponseFixture('scope-freezer', 'scope-collection'), 200);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), 'named-vlan') && ! str_contains($request->url(), 'named-vlan/')) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $scopeId = $query['scope-id'] ?? null;

            if ($scopeId === 'scope-freezer') {
                return Http::response([
                    'profile' => [
                        [
                            'name' => 'WCD_KIT',
                            'vlan' => ['vlan-id-ranges' => ['9']],
                        ],
                    ],
                ], 200);
            }

            if ($scopeId === 'scope-collection') {
                return Http::response([
                    'profile' => [
                        [
                            'name' => 'WCD_KIT',
                            'vlan' => ['vlan-id-ranges' => ['1']],
                        ],
                    ],
                ], 200);
            }
        }

        return Http::response(['ok' => true], 200);
    });

    $client = Client::factory()->create([
        'base_url' => BaseURL::US1,
        'bearer_token' => 'test-bearer-token',
        'expires_at' => now()->addHour(),
    ]);

    $helper = new CentralAPIHelper($client);
    $results = app(MigrationNamedVlanService::class)->deployOffsetNamedVlans(
        $helper,
        'scope-freezer',
        [
            ['ssid_profile_name' => 'DAYKIT', 'body' => ['vlan-name' => 'WCD_KIT']],
        ],
    );

    expect($results)->toHaveCount(1)
        ->and($results[0]['status'])->toBe('success');

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), 'network-config/v1alpha1/named-vlan/WCD_KIT')
            && json_decode($request->body(), true) === [
                'name' => 'WCD_KIT',
                'vlan' => ['vlan-id-ranges' => ['209']],
            ];
    });
});

it('only deploys named vlan profiles referenced in wlan bodies', function () {
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET' && str_contains($request->url(), 'network-config/v1/hierarchy')) {
            return Http::response(migrationHierarchyResponseFixture('scope-freezer', 'scope-collection'), 200);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), 'named-vlan') && ! str_contains($request->url(), 'named-vlan/')) {
            return Http::response([
                'profile' => [
                    [
                        'name' => 'WCD_KIT',
                        'vlan' => ['vlan-id-ranges' => ['1']],
                    ],
                    [
                        'name' => 'WCD_RF',
                        'vlan' => ['vlan-id-ranges' => ['4']],
                    ],
                ],
            ], 200);
        }

        return Http::response(['ok' => true], 200);
    });

    $client = Client::factory()->create([
        'base_url' => BaseURL::US1,
        'bearer_token' => 'test-bearer-token',
        'expires_at' => now()->addHour(),
    ]);

    $helper = new CentralAPIHelper($client);
    $results = app(MigrationNamedVlanService::class)->deployOffsetNamedVlans(
        $helper,
        'scope-freezer',
        [
            ['ssid_profile_name' => 'DAYKIT', 'body' => ['vlan-name' => 'WCD_KIT']],
        ],
    );

    expect($results)->toHaveCount(1)
        ->and($results[0]['name'])->toBe('WCD_KIT');

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), 'network-config/v1alpha1/named-vlan/WCD_KIT');
    });

    Http::assertNotSent(function (Request $request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), 'network-config/v1alpha1/named-vlan/WCD_RF');
    });
});

it('falls back to site named vlan fetch when hierarchy has no site collection', function () {
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET' && str_contains($request->url(), 'network-config/v1/hierarchy')) {
            return Http::response([
                'items' => [
                    [
                        'hierarchy' => [
                            [
                                'scopeName' => 'Daytona Freezer',
                                'scopeType' => 'site',
                                'scopeId' => 'scope-freezer',
                            ],
                        ],
                    ],
                ],
            ], 200);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), 'named-vlan') && ! str_contains($request->url(), 'named-vlan/')) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            if (($query['scope-id'] ?? null) === 'scope-freezer') {
                return Http::response([
                    'profile' => [
                        [
                            'name' => 'WCD_KIT',
                            'vlan' => ['vlan-id-ranges' => ['1']],
                        ],
                    ],
                ], 200);
            }
        }

        return Http::response(['ok' => true], 200);
    });

    $client = Client::factory()->create([
        'base_url' => BaseURL::US1,
        'bearer_token' => 'test-bearer-token',
        'expires_at' => now()->addHour(),
    ]);

    $helper = new CentralAPIHelper($client);
    $fetch = app(MigrationNamedVlanService::class)->fetchNamedVlanProfilesForFreezerSite($helper, 'scope-freezer');

    expect($fetch['error'])->toBeNull()
        ->and($fetch['profiles'])->toHaveCount(1)
        ->and($fetch['profiles'][0]['name'])->toBe('WCD_KIT');

    Http::assertSentCount(2);
});
