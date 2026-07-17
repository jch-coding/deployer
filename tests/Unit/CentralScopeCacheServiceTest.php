<?php

use App\CentralScopeCacheType;
use App\Helper\CentralAPIHelper;
use App\Models\CentralScopeCache;
use App\Models\Client;
use App\Services\CentralScopeCacheService;
use Illuminate\Support\Facades\Http;

it('getSites returns empty payload when cache is missing', function () {
    $client = Client::factory()->create();

    $payload = app(CentralScopeCacheService::class)->getSites($client);

    expect($payload['sites'])->toBe([])
        ->and($payload['error'])->toContain('not been refreshed')
        ->and($payload['refreshed_at'])->toBeNull();
});

it('refreshSites persists sites and timestamp', function () {
    Http::fake([
        '*network-config/v1/sites*' => Http::response([
            'items' => [
                ['scopeName' => 'HQ', 'scopeId' => 'scope-hq'],
            ],
        ], 200),
    ]);

    $client = Client::factory()->create([
        'bearer_token' => 'test-bearer-token',
        'expires_at' => now()->addHour(),
    ]);

    $result = app(CentralScopeCacheService::class)->refreshSites($client);

    expect($result['error'])->toBeNull()
        ->and($result['sites'])->toHaveCount(1)
        ->and($result['sites'][0]['scopeName'])->toBe('HQ')
        ->and($result['refreshed_at'])->not->toBeNull();

    $cache = CentralScopeCache::query()
        ->where('client_id', $client->id)
        ->where('type', CentralScopeCacheType::Sites)
        ->first();

    expect($cache)->not->toBeNull()
        ->and($cache->items)->toHaveCount(1)
        ->and($cache->refreshed_at)->not->toBeNull()
        ->and($cache->last_error)->toBeNull();
});

it('refreshGroups merges classic-only groups into device group options', function () {
    Http::fake(function ($request) {
        if (str_contains($request->url(), 'device-groups')) {
            return Http::response([
                'items' => [
                    ['scopeName' => 'Central Group', 'scopeId' => 'scope-group'],
                ],
            ], 200);
        }

        if (str_contains($request->url(), 'configuration/v2/groups')) {
            return Http::response([
                'data' => [['Classic Only Group', 'Central Group']],
            ], 200);
        }

        return Http::response([], 404);
    });

    $client = Client::factory()->create([
        'bearer_token' => 'test-bearer-token',
        'expires_at' => now()->addHour(),
        'classic_base_url' => \App\ClassicBaseUrl::US1,
        'classic_client_id' => 'classic-id',
        'classic_client_secret' => 'classic-secret',
        'classic_username' => 'user',
        'classic_password' => 'pass',
        'classic_refresh_token' => 'refresh',
        'classic_expires_in' => now()->addHour(),
        'classic_access_token' => 'access-token',
    ]);

    $result = app(CentralScopeCacheService::class)->refreshGroups($client);

    expect($result['error'])->toBeNull()
        ->and($result['device_group_options'])->toHaveCount(2)
        ->and($result['device_group_options'][0]['scopeName'])->toBe('Central Group')
        ->and($result['device_group_options'][0]['isClassic'])->toBeFalse()
        ->and($result['device_group_options'][1]['scopeName'])->toBe('Classic Only Group')
        ->and($result['device_group_options'][1]['isClassic'])->toBeTrue();
});

it('refreshSites records error when Central authentication fails', function () {
    $client = Client::factory()->create([
        'bearer_token' => null,
        'expires_at' => null,
    ]);

    $helper = mock(CentralAPIHelper::class, [$client])->makePartial();
    $helper->shouldReceive('collectScopeManagementSites')->once()->andReturn([
        'sites' => [],
        'error' => 'Could not authenticate with Central to load sites.',
    ]);

    $result = app(CentralScopeCacheService::class)->refreshSites($client, $helper);

    expect($result['error'])->toBe('Could not authenticate with Central to load sites.');

    $cache = CentralScopeCache::query()
        ->where('client_id', $client->id)
        ->where('type', CentralScopeCacheType::Sites)
        ->first();

    expect($cache)->not->toBeNull()
        ->and($cache->last_error)->toBe('Could not authenticate with Central to load sites.');
});
