<?php

use App\BaseURL;
use App\CentralScopeCacheType;
use App\Models\CentralScopeCache;
use App\Models\Client;
use App\Services\CentralScopeCacheService;
use Illuminate\Support\Facades\Http;

it('getMacRegistrations returns empty payload when cache is missing', function () {
    $client = Client::factory()->create();

    $payload = app(CentralScopeCacheService::class)->getMacRegistrations($client);

    expect($payload['entries'])->toBe([])
        ->and($payload['error'])->toContain('not been refreshed')
        ->and($payload['refreshed_at'])->toBeNull();
});

it('refreshMacRegistrations persists listed MAC registration entries', function () {
    Http::fake([
        '*network-config/v1alpha1/cnac-mac-reg?*' => Http::response([
            'count' => 1,
            'items' => [
                [
                    'macAddress' => 'AA-BB-CC-DD-EE-01',
                    'displayName' => 'Phone',
                    'enable' => true,
                    'staticTags' => ['TAG-A', 'TAG-B'],
                ],
            ],
        ], 200),
    ]);

    $client = Client::factory()->create([
        'base_url' => BaseURL::US1,
        'bearer_token' => 'test-bearer-token',
        'expires_at' => now()->addHour(),
    ]);

    $result = app(CentralScopeCacheService::class)->refreshMacRegistrations($client);

    expect($result['error'])->toBeNull()
        ->and($result['entries'])->toHaveCount(1)
        ->and($result['entries'][0]['mac_address'])->toBe('aa:bb:cc:dd:ee:01')
        ->and($result['entries'][0]['static_tags'])->toBe(['TAG-A', 'TAG-B'])
        ->and($result['refreshed_at'])->not->toBeNull();

    $cache = CentralScopeCache::query()
        ->where('client_id', $client->id)
        ->where('type', CentralScopeCacheType::MacRegistrations)
        ->first();

    expect($cache)->not->toBeNull()
        ->and($cache->items)->toHaveCount(1)
        ->and($cache->last_error)->toBeNull();
});

it('refreshMacRegistrations pages through list next cursors', function () {
    Http::fake(function (\Illuminate\Http\Client\Request $request) {
        $url = $request->url();
        if (! str_contains($url, 'cnac-mac-reg') || str_contains($url, '/export') || str_contains($url, '/import')) {
            return Http::response(['message' => 'unexpected url'], 404);
        }

        if (str_contains($url, 'next=')) {
            return Http::response([
                'count' => 1,
                'items' => [
                    [
                        'macAddress' => 'AA-BB-CC-DD-EE-02',
                        'displayName' => 'Second',
                        'enable' => true,
                        'staticTags' => [],
                    ],
                ],
                'next' => null,
            ], 200);
        }

        return Http::response([
            'count' => 1,
            'items' => [
                [
                    'macAddress' => 'AA-BB-CC-DD-EE-01',
                    'displayName' => 'First',
                    'enable' => true,
                    'staticTags' => ['TAG-A'],
                ],
            ],
            'next' => 'cursor-2',
        ], 200);
    });

    $client = Client::factory()->create([
        'base_url' => BaseURL::US1,
        'bearer_token' => 'test-bearer-token',
        'expires_at' => now()->addHour(),
    ]);

    $result = app(CentralScopeCacheService::class)->refreshMacRegistrations($client);

    expect($result['error'])->toBeNull()
        ->and($result['entries'])->toHaveCount(2)
        ->and($result['entries'][0]['mac_address'])->toBe('aa:bb:cc:dd:ee:01')
        ->and($result['entries'][1]['mac_address'])->toBe('aa:bb:cc:dd:ee:02');
});

it('lookupMacs returns registered and missing entries keyed by normalized MAC', function () {
    $client = Client::factory()->create();

    CentralScopeCache::query()->create([
        'client_id' => $client->id,
        'type' => CentralScopeCacheType::MacRegistrations,
        'items' => [
            [
                'mac_address' => 'aa:bb:cc:dd:ee:01',
                'client_name' => 'Phone',
                'enabled' => true,
                'static_tags' => ['TAG-A'],
            ],
        ],
        'refreshed_at' => now(),
        'last_error' => null,
    ]);

    $lookups = app(CentralScopeCacheService::class)->lookupMacs($client, [
        'AA-BB-CC-DD-EE-01',
        '11:22:33:44:55:66',
    ]);

    expect($lookups['aa:bb:cc:dd:ee:01'])->toMatchArray([
        'mac_address' => 'aa:bb:cc:dd:ee:01',
        'client_name' => 'Phone',
        'static_tags' => ['TAG-A'],
    ])
        ->and($lookups['11:22:33:44:55:66'])->toBeNull();
});

it('ensureMacRegistrations refreshes when cache row is missing', function () {
    Http::fake([
        '*cnac-mac-reg?*' => Http::response([
            'count' => 1,
            'items' => [
                [
                    'macAddress' => 'AA-BB-CC-DD-EE-FF',
                    'displayName' => '',
                    'enable' => true,
                    'staticTags' => [],
                ],
            ],
        ], 200),
    ]);

    $client = Client::factory()->create([
        'base_url' => BaseURL::US1,
        'bearer_token' => 'token',
        'expires_at' => now()->addHour(),
    ]);

    $result = app(CentralScopeCacheService::class)->ensureMacRegistrations($client);

    expect($result['error'])->toBeNull()
        ->and($result['entries'])->toHaveCount(1)
        ->and($result['entries'][0]['mac_address'])->toBe('aa:bb:cc:dd:ee:ff');
});
