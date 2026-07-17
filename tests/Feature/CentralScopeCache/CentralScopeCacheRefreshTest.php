<?php

use App\CentralScopeCacheType;
use App\Models\CentralScopeCache;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = Client::factory()->for($this->user)->create([
        'current' => true,
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
    $this->actingAs($this->user);
});

test('refresh sites endpoint persists cache and redirects back with success', function () {
    Http::fake([
        '*network-config/v1/sites*' => Http::response([
            'items' => [
                ['scopeName' => 'HQ', 'scopeId' => 'scope-hq'],
            ],
        ], 200),
    ]);

    $this->from(route('sites.index'))
        ->post(route('central-scope-cache.sites.refresh'))
        ->assertRedirect(route('sites.index'))
        ->assertSessionHas('success', 'Central sites refreshed.');

    $cache = CentralScopeCache::query()
        ->where('client_id', $this->client->id)
        ->where('type', CentralScopeCacheType::Sites)
        ->first();

    expect($cache)->not->toBeNull()
        ->and($cache->items)->toHaveCount(1)
        ->and($cache->items[0]['scopeName'])->toBe('HQ')
        ->and($cache->refreshed_at)->not->toBeNull();
});

test('refresh groups endpoint persists merged group options', function () {
    Http::fake(function (Request $request) {
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

    $this->from(route('deployments.index'))
        ->post(route('central-scope-cache.groups.refresh'))
        ->assertRedirect(route('deployments.index'))
        ->assertSessionHas('success', 'Central groups refreshed.');

    $cache = CentralScopeCache::query()
        ->where('client_id', $this->client->id)
        ->where('type', CentralScopeCacheType::Groups)
        ->first();

    expect($cache)->not->toBeNull()
        ->and($cache->items['device_group_options'])->toHaveCount(2)
        ->and($cache->items['device_group_options'][1]['scopeName'])->toBe('Classic Only Group');
});

test('refresh sites endpoint redirects to clients when no current client is set', function () {
    $this->client->update(['current' => false]);

    $this->post(route('central-scope-cache.sites.refresh'))
        ->assertRedirect(route('clients.index'))
        ->assertSessionHas('error');
});

test('refresh sites endpoint returns error when Central fails', function () {
    Http::fake([
        '*network-config/v1/sites*' => Http::response([], 403),
    ]);

    $this->from(route('sites.index'))
        ->post(route('central-scope-cache.sites.refresh'))
        ->assertRedirect(route('sites.index'))
        ->assertSessionHas('error');
});
