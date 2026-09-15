<?php

use App\BaseURL;
use App\CentralScopeCacheType;
use App\Models\CentralScopeCache;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = Client::factory()->for($this->user)->create([
        'current' => true,
        'base_url' => BaseURL::US1,
        'bearer_token' => 'test-bearer-token',
        'expires_at' => now()->addHour(),
    ]);
    $this->actingAs($this->user);
});

test('refresh mac registrations endpoint persists cache and redirects back with success', function () {
    Http::fake([
        '*network-config/v1alpha1/cnac-mac-reg/export*' => Http::response(
            "MAC Address,Client Name,Enabled,Static Tags\nAA-BB-CC-DD-EE-01,,true,TAG-A\n",
            200,
            ['Content-Type' => 'text/csv'],
        ),
    ]);

    $this->from(route('deployments.index'))
        ->post(route('central-scope-cache.mac-registrations.refresh'))
        ->assertRedirect(route('deployments.index'))
        ->assertSessionHas('success', 'Central NAC MAC registrations refreshed (1 entries).');

    $cache = CentralScopeCache::query()
        ->where('client_id', $this->client->id)
        ->where('type', CentralScopeCacheType::MacRegistrations)
        ->first();

    expect($cache)->not->toBeNull()
        ->and($cache->items)->toHaveCount(1)
        ->and($cache->items[0]['mac_address'])->toBe('aa:bb:cc:dd:ee:01')
        ->and($cache->refreshed_at)->not->toBeNull();
});

test('refresh mac registrations endpoint flashes error when Central export fails', function () {
    Http::fake([
        '*cnac-mac-reg/export*' => Http::response(['message' => 'export denied'], 403),
    ]);

    $this->from(route('deployments.index'))
        ->post(route('central-scope-cache.mac-registrations.refresh'))
        ->assertRedirect(route('deployments.index'))
        ->assertSessionHas('error', 'export denied');
});
