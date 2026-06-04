<?php

use App\ClassicBaseUrl;
use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\LicensingInventoryDevice;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

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
        'current' => true,
    ]);
    $this->deployment = Deployment::factory()->for($this->client)->create(['name' => 'Main']);
    $this->actingAs($this->user);
    $this->withoutVite();
});

test('licensing index redirects when no current client is set', function () {
    $this->client->update(['current' => false]);

    $this->get(route('licensing.index'))
        ->assertRedirect(route('clients.index'));
});

test('licensing index renders device inventory enriched with subscription metadata', function () {
    fakeLicensingCentralApis(
        devices: [[
            'serial' => 'SN-LIC-001',
            'model' => 'AP-515',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'device_type' => 'IAP',
            'services' => ['advanced_ap'],
            'subscription_key' => 'KEY-001',
            'licensed' => true,
        ]],
        subscriptions: [[
            'subscription_key' => 'KEY-001',
            'sku' => 'Q9Y65AAE',
            'license_type' => 'Advanced AP',
            'start_date' => 1780425040000,
            'end_date' => 1940697055000,
            'status' => 'OK',
            'subscription_type' => 'NONE',
            'available' => 10,
            'acpapp_name' => 'nms',
            'tags' => [
                'pool-a' => 'pool-a-value',
                'pool-b' => 'pool-b-value',
            ],
        ]],
    );

    Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'serial' => 'SN-LIC-001',
        'sku' => 'JL660A',
    ]);

    $this->get(route('licensing.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Licensing/Index')
            ->has('devices', 1)
            ->where('devices.0.serial', 'SN-LIC-001')
            ->where('devices.0.subscription_sku', 'Q9Y65AAE')
            ->where('devices.0.license_type', 'Advanced AP')
            ->where('devices.0.device_sku', 'JL660A')
            ->where('enabled_services.0', 'advanced_ap')
            ->has('available_subscriptions', 1)
            ->where('available_subscriptions.0.subscription_key', 'KEY-001')
            ->where('available_subscriptions.0.tags', ['pool-a', 'pool-b'])
            ->where('devices.0.tags', ['pool-a', 'pool-b'])
            ->where('subscription_summary.total_devices', 1));

    expect($this->client->refresh()->licensing_synced_at)->not->toBeNull();
    expect(ClientSubscription::where('client_id', $this->client->id)->count())->toBe(1);
    expect(LicensingInventoryDevice::where('client_id', $this->client->id)->count())->toBe(1);
});

test('licensing index serves from database cache without calling central on second visit', function () {
    seedLicensingCache(
        $this->client,
        devices: [[
            'serial' => 'SN-CACHED',
            'model' => 'AP-515',
            'device_type' => 'IAP',
            'services' => ['advanced_ap'],
            'subscription_key' => 'KEY-CACHED',
        ]],
        subscriptions: [[
            'subscription_key' => 'KEY-CACHED',
            'sku' => 'Q9Y65AAE',
            'license_type' => 'Advanced AP',
            'status' => 'OK',
            'available' => 5,
        ]],
    );

    Http::fake(function () {
        return Http::response(['error' => 'central should not be called'], 500);
    });

    $this->get(route('licensing.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('devices', 1)
            ->where('devices.0.serial', 'SN-CACHED')
            ->where('licensing_synced_at', fn ($value) => $value !== null));
});

test('licensing index filters by subscription_sku and license_type', function () {
    fakeLicensingCentralApis(
        devices: [
            [
                'serial' => 'SN-A',
                'model' => 'AP-515',
                'device_type' => 'IAP',
                'services' => ['advanced_ap'],
                'subscription_key' => 'KEY-A',
            ],
            [
                'serial' => 'SN-B',
                'model' => 'CX-6300',
                'device_type' => 'MAS',
                'services' => ['advanced_switch_6300'],
                'subscription_key' => 'KEY-B',
            ],
        ],
        subscriptions: [
            [
                'subscription_key' => 'KEY-A',
                'sku' => 'Q9Y65AAE',
                'license_type' => 'Advanced AP',
                'start_date' => 1780425040000,
                'end_date' => 1940697055000,
                'status' => 'OK',
            ],
            [
                'subscription_key' => 'KEY-B',
                'sku' => 'OTHER-SKU',
                'license_type' => 'Advanced Switch',
                'start_date' => 1780425040000,
                'end_date' => 1940697055000,
                'status' => 'OK',
            ],
        ],
    );

    $this->get(route('licensing.index', [
        'subscription_sku' => 'Q9Y65AAE',
        'license_type' => 'Advanced AP',
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('devices', 1)
            ->where('devices.0.serial', 'SN-A')
            ->where('filters.subscription_sku', 'Q9Y65AAE')
            ->where('filters.license_type', 'Advanced AP'));
});

test('licensing assign patches GreenLake devices with subscription id', function () {
    $this->client->update([
        'licensing_enabled_services' => ['advanced_ap'],
        'licensing_synced_at' => now(),
        'licensing_sync_error' => null,
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
    ]);

    $this->client->clientSubscriptions()->create([
        'subscription_key' => 'KEY-POOL',
        'greenlake_subscription_id' => 'gl-sub-pool',
        'subscription_sku' => 'Q9Y65AAE',
        'license_type' => 'Advanced AP',
        'status' => 'OK',
        'available' => 10,
    ]);

    LicensingInventoryDevice::create([
        'client_id' => $this->client->id,
        'serial' => 'SN-001',
        'greenlake_device_id' => 'gl-dev-001',
        'model' => 'AP-515',
        'device_type' => 'IAP',
        'name' => 'SN-001',
        'licensed' => true,
        'assigned_services' => ['advanced_ap'],
        'subscription_key' => 'KEY-POOL',
    ]);

    LicensingInventoryDevice::create([
        'client_id' => $this->client->id,
        'serial' => 'SN-002',
        'greenlake_device_id' => 'gl-dev-002',
        'model' => 'AP-515',
        'device_type' => 'IAP',
        'name' => 'SN-002',
        'licensed' => false,
        'assigned_services' => [],
        'subscription_key' => '',
    ]);

    Http::fake([
        'https://global.api.greenlake.hpe.com/*' => Http::response([], 200),
    ]);

    $this->post(route('licensing.assign'), [
        'subscription_key' => 'KEY-POOL',
        'serials' => ['SN-001', 'SN-002'],
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        return $request->method() === 'PATCH'
            && str_contains($request->url(), '/devices/v1/devices')
            && ($request->data()['subscription'][0]['id'] ?? '') === 'gl-sub-pool';
    });
});

test('licensing index filters by serial number device name subscription key tags and model', function () {
    seedLicensingCache(
        $this->client,
        devices: [
            [
                'serial' => 'SN-FILTER-A',
                'model' => 'AP-515',
                'name' => 'Lobby AP',
                'device_type' => 'IAP',
                'services' => ['advanced_ap'],
                'subscription_key' => 'KEY-TAG-A',
            ],
            [
                'serial' => 'SN-FILTER-B',
                'model' => 'CX-6300',
                'name' => 'Core Switch',
                'device_type' => 'MAS',
                'services' => ['advanced_switch_6300'],
                'subscription_key' => 'KEY-TAG-B',
            ],
        ],
        subscriptions: [
            [
                'subscription_key' => 'KEY-TAG-A',
                'sku' => 'Q9Y65AAE',
                'license_type' => 'Advanced AP',
                'status' => 'OK',
                'available' => 5,
                'tags' => ['pool-a' => 'a', 'pool-b' => 'b'],
            ],
            [
                'subscription_key' => 'KEY-TAG-B',
                'sku' => 'OTHER-SKU',
                'license_type' => 'Advanced Switch',
                'status' => 'OK',
                'available' => 5,
                'tags' => ['pool-c' => 'c'],
            ],
        ],
    );

    $this->get(route('licensing.index', [
        'serial_number' => 'filter-a',
        'device_name' => 'lobby',
        'subscription_key' => 'key-tag-a',
        'subscription_tags' => 'pool-a,pool-b',
        'model' => 'ap-515',
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('devices', 1)
            ->where('devices.0.serial', 'SN-FILTER-A')
            ->where('filters.serial_number', 'filter-a')
            ->where('filters.device_name', 'lobby')
            ->where('filters.subscription_key', 'key-tag-a')
            ->where('filters.subscription_tags', 'pool-a,pool-b')
            ->where('filters.model', 'ap-515')
            ->where('has_active_filters', true));
});

test('licensing queue route is removed', function () {
    $this->post('/licensing/queue', [
        'action' => 'assign',
        'service_name' => 'advanced_ap',
        'deployment_id' => 1,
        'device_ids' => [1],
    ])->assertNotFound();
});
