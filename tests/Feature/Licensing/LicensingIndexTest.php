<?php

use App\ClassicBaseUrl;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Client\Request;
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
        'current' => true,
    ]);
    $this->deployment = Deployment::factory()->for($this->client)->create(['name' => 'Main']);
    $this->actingAs($this->user);
    $this->withoutVite();
});

function fakeLicensingCentralApis(array $devices = [], array $subscriptions = []): void
{
    Http::fake(function (Request $request) use ($devices, $subscriptions) {
        if (str_contains($request->url(), '/platform/licensing/v1/services/enabled')) {
            return Http::response([
                'services' => [
                    'services' => ['advanced_ap', 'advanced_switch_6300'],
                ],
            ], 200);
        }

        if (str_contains($request->url(), '/platform/licensing/v1/subscriptions')) {
            return Http::response([
                'subscriptions' => $subscriptions,
            ], 200);
        }

        if (str_contains($request->url(), '/platform/device_inventory/v1/devices')) {
            return Http::response([
                'total' => count($devices),
                'devices' => $devices,
            ], 200);
        }

        return Http::response([], 404);
    });
}

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
            ->where('subscription_summary.total_devices', 1));
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

test('licensing assign posts to classic assign endpoint in serial chunks', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/platform/licensing/v1/services/enabled')) {
            return Http::response(['services' => ['services' => ['advanced_ap']]], 200);
        }

        if (str_contains($request->url(), '/platform/licensing/v1/subscriptions/assign')) {
            expect($request->method())->toBe('POST');
            expect($request->data())->toMatchArray([
                'serials' => ['SN-001', 'SN-002'],
                'service_name' => ['advanced_ap'],
            ]);

            return Http::response(['message' => 'ok'], 200);
        }

        if (str_contains($request->url(), '/platform/licensing/v1/subscriptions') && $request->method() === 'GET') {
            return Http::response(['subscriptions' => []], 200);
        }

        if (str_contains($request->url(), '/platform/device_inventory/v1/devices')) {
            return Http::response(['total' => 0, 'devices' => []], 200);
        }

        return Http::response([], 404);
    });

    $this->post(route('licensing.assign'), [
        'service_name' => 'advanced_ap',
        'serials' => ['SN-001', 'SN-002'],
    ])
        ->assertRedirect()
        ->assertSessionHas('success');
});

test('licensing queue creates assign subscription task with licensing_service_name', function () {
    fakeLicensingCentralApis();

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/platform/licensing/v1/services/enabled')) {
            return Http::response(['services' => ['services' => ['advanced_ap']]], 200);
        }

        if (str_contains($request->url(), '/platform/licensing/v1/subscriptions/assign')) {
            return Http::response(['message' => 'ok'], 200);
        }

        if (str_contains($request->url(), '/platform/licensing/v1/subscriptions') && $request->method() === 'GET') {
            return Http::response(['subscriptions' => []], 200);
        }

        if (str_contains($request->url(), '/platform/device_inventory/v1/devices')) {
            return Http::response(['total' => 0, 'devices' => []], 200);
        }

        return Http::response([], 404);
    });

    $device = Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'serial' => 'SN-QUEUE-001',
    ]);

    $response = $this->post(route('licensing.queue'), [
        'action' => 'assign',
        'service_name' => 'advanced_ap',
        'deployment_id' => $this->deployment->id,
        'device_ids' => [$device->id],
        'deployment_time' => 3,
    ]);

    $task = Task::query()->latest('id')->first();

    expect($task)->not->toBeNull()
        ->and($task->task_type)->toBe('ASSIGN_SUBSCRIPTION')
        ->and($task->licensing_service_name)->toBe('advanced_ap');

    $response->assertRedirect(route('tasks.show', $task));
});
