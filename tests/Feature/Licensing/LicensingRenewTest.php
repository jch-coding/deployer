<?php

use App\ClassicBaseUrl;
use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\Deployment;
use App\Models\LicensingInventoryDevice;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = Client::factory()->for($this->user)->create([
        'classic_base_url' => ClassicBaseUrl::US1,
        'classic_client_id' => 'classic-id',
        'classic_client_secret' => 'classic-secret',
        'classic_username' => 'user',
        'classic_password' => 'pass',
        'classic_refresh_token' => 'refresh',
        'classic_expires_in' => now()->addHour(),
        'classic_access_token' => 'access-token',
        'current' => true,
    ]);
    Deployment::factory()->for($this->client)->create(['name' => 'Main']);
    $this->actingAs($this->user);
    $this->withoutVite();
});

test('licensing renew redirects when no current client is set', function () {
    $this->client->update(['current' => false]);

    $this->post(route('licensing.renew'))
        ->assertRedirect(route('clients.index'));
});

test('licensing renew syncs data from central into database', function () {
    fakeLicensingCentralApis(
        devices: [[
            'serial' => 'SN-RENEW-001',
            'model' => 'AP-515',
            'device_type' => 'IAP',
            'services' => ['advanced_ap'],
            'subscription_key' => 'KEY-RENEW',
            'licensed' => true,
        ]],
        subscriptions: [[
            'subscription_key' => 'KEY-RENEW',
            'sku' => 'Q9Y65AAE',
            'license_type' => 'Advanced AP',
            'status' => 'OK',
            'available' => 8,
        ]],
    );

    $this->from(route('licensing.index'))
        ->post(route('licensing.renew'))
        ->assertRedirect(route('licensing.index'))
        ->assertSessionHas('success');

    $this->client->refresh();

    expect($this->client->licensing_synced_at)->not->toBeNull()
        ->and($this->client->licensing_sync_error)->toBeNull()
        ->and($this->client->licensing_enabled_services)->toContain('advanced_ap')
        ->and(ClientSubscription::where('client_id', $this->client->id)->count())->toBe(1)
        ->and(LicensingInventoryDevice::where('client_id', $this->client->id)->count())->toBe(1);
});

test('licensing renew flashes error when central sync fails', function () {
    Http::fake([
        '*' => Http::response(['message' => 'unauthorized'], 401),
    ]);

    $this->from(route('licensing.index'))
        ->post(route('licensing.renew'))
        ->assertRedirect(route('licensing.index'))
        ->assertSessionHas('error');

    expect($this->client->refresh()->licensing_sync_error)->not->toBeNull();
});
