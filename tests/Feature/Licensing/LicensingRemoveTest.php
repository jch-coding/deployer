<?php

use App\ClassicBaseUrl;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\LicensingInventoryDevice;
use App\Models\User;
use Illuminate\Http\Client\Request;
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
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
        'licensing_synced_at' => now(),
        'licensing_enabled_services' => ['advanced_ap'],
        'current' => true,
    ]);
    Deployment::factory()->for($this->client)->create(['name' => 'Main']);
    $this->actingAs($this->user);
    $this->withoutVite();
});

test('licensing remove unassigns on GreenLake and deletes inventory rows', function () {
    LicensingInventoryDevice::create([
        'client_id' => $this->client->id,
        'serial' => 'SN-REMOVE-001',
        'greenlake_device_id' => 'gl-dev-remove-001',
        'model' => 'AP-515',
        'device_type' => 'IAP',
        'name' => 'SN-REMOVE-001',
        'licensed' => true,
        'assigned_services' => ['advanced_ap'],
        'subscription_key' => 'KEY-REMOVE',
    ]);

    LicensingInventoryDevice::create([
        'client_id' => $this->client->id,
        'serial' => 'SN-REMOVE-002',
        'greenlake_device_id' => 'gl-dev-remove-002',
        'model' => 'AP-515',
        'device_type' => 'IAP',
        'name' => 'SN-REMOVE-002',
        'licensed' => false,
        'assigned_services' => [],
        'subscription_key' => '',
    ]);

    Http::fake([
        'https://global.api.greenlake.hpe.com/*' => Http::response([], 200),
    ]);

    $this->from(route('licensing.index'))
        ->post(route('licensing.remove'), [
            'serials' => ['SN-REMOVE-001', 'SN-REMOVE-002'],
        ])
        ->assertRedirect(route('licensing.index'))
        ->assertSessionHas('success');

    expect(LicensingInventoryDevice::where('client_id', $this->client->id)->count())->toBe(0);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'PATCH'
            && str_contains($request->url(), 'id=gl-dev-remove-001')
            && ($request->data()['subscription'] ?? null) === [];
    });

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'PATCH'
            && str_contains($request->url(), 'id=gl-dev-remove-001')
            && array_key_exists('application', $request->data())
            && $request->data()['application'] === null;
    });
});

test('licensing remove requires GreenLake device id', function () {
    LicensingInventoryDevice::create([
        'client_id' => $this->client->id,
        'serial' => 'SN-NO-GL',
        'greenlake_device_id' => '',
        'model' => 'AP-515',
        'device_type' => 'IAP',
        'name' => 'SN-NO-GL',
        'licensed' => false,
        'assigned_services' => [],
        'subscription_key' => '',
    ]);

    $this->from(route('licensing.index'))
        ->post(route('licensing.remove'), [
            'serials' => ['SN-NO-GL'],
        ])
        ->assertRedirect(route('licensing.index'))
        ->assertSessionHasErrors('serials');

    expect(LicensingInventoryDevice::where('client_id', $this->client->id)->count())->toBe(1);
});
