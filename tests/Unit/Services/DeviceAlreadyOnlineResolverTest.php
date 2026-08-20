<?php

use App\DeviceFunction;
use App\Models\CentralWebhookEvent;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\User;
use App\Services\Provisioning\ClassicDeviceOnlineService;
use App\Services\Provisioning\DeviceAlreadyOnlineResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->has(Client::factory())->create();
    $this->client = $this->user->clients()->first();
    $this->deployment = Deployment::factory()->for($this->client)->create();
    $this->resolver = new DeviceAlreadyOnlineResolver(new ClassicDeviceOnlineService);
});

it('marks devices online from accepted webhooks without querying central', function () {
    $device = Device::factory()->for($this->deployment)->create([
        'client_id' => $this->client->id,
        'serial' => 'SNACCEPT1',
        'device_function' => DeviceFunction::CAMPUS_AP->name,
    ]);

    CentralWebhookEvent::query()->create([
        'client_id' => $this->client->id,
        'payload' => ['alert_type' => 'New AP detected'],
        'alert_type' => 'New AP detected',
        'serial' => 'SNACCEPT1',
        'disposition' => 'accepted',
        'created_at' => now(),
    ]);

    Http::fake();

    $reasons = $this->resolver->resolve($this->client, collect([$device]), false);

    expect($reasons)->toBe([$device->id => 'Already online (webhook).']);
    Http::assertNothingSent();
});

it('ignores non-accepted webhooks and other serials', function () {
    $device = Device::factory()->for($this->deployment)->create([
        'client_id' => $this->client->id,
        'serial' => 'SNDEVICE1',
        'device_function' => DeviceFunction::CAMPUS_AP->name,
    ]);

    CentralWebhookEvent::query()->create([
        'client_id' => $this->client->id,
        'payload' => ['alert_type' => 'AP Disconnected'],
        'alert_type' => 'AP Disconnected',
        'serial' => 'SNDEVICE1',
        'disposition' => 'ignored',
        'created_at' => now(),
    ]);

    CentralWebhookEvent::query()->create([
        'client_id' => $this->client->id,
        'payload' => ['alert_type' => 'New AP detected'],
        'alert_type' => 'New AP detected',
        'serial' => 'OTHERSERIAL',
        'disposition' => 'accepted',
        'created_at' => now(),
    ]);

    expect($this->resolver->resolve($this->client, collect([$device]), false))->toBe([]);
});

it('marks devices online from central when opted in', function () {
    $this->client->update([
        'classic_base_url' => \App\ClassicBaseUrl::US_WEST4,
        'classic_client_id' => 'classic-client-id',
        'classic_client_secret' => 'classic-client-secret',
        'classic_username' => 'classic-user',
        'classic_password' => 'classic-password',
        'classic_refresh_token' => 'refresh-token',
        'classic_access_token' => 'access-token',
        'classic_expires_in' => now()->addHour(),
    ]);

    $device = Device::factory()->for($this->deployment)->create([
        'client_id' => $this->client->id,
        'serial' => 'SNAPUP1',
        'device_function' => DeviceFunction::CAMPUS_AP->name,
    ]);

    Http::fake([
        '*monitoring/v2/aps*' => Http::response([
            'aps' => [
                ['serial' => 'SNAPUP1', 'status' => 'Up'],
            ],
        ], 200),
        '*monitoring/v1/switches*' => Http::response(['switches' => []], 200),
    ]);

    $reasons = $this->resolver->resolve($this->client, collect([$device]), true);

    expect($reasons)->toBe([$device->id => 'Already online in Central (Up).']);
});

it('prefers webhook reason over central when both apply', function () {
    $this->client->update([
        'classic_base_url' => \App\ClassicBaseUrl::US_WEST4,
        'classic_client_id' => 'classic-client-id',
        'classic_client_secret' => 'classic-client-secret',
        'classic_username' => 'classic-user',
        'classic_password' => 'classic-password',
        'classic_refresh_token' => 'refresh-token',
        'classic_access_token' => 'access-token',
        'classic_expires_in' => now()->addHour(),
    ]);

    $device = Device::factory()->for($this->deployment)->create([
        'client_id' => $this->client->id,
        'serial' => 'SNBOTH1',
        'device_function' => DeviceFunction::CAMPUS_AP->name,
    ]);

    CentralWebhookEvent::query()->create([
        'client_id' => $this->client->id,
        'payload' => ['alert_type' => 'New AP detected'],
        'alert_type' => 'New AP detected',
        'serial' => 'SNBOTH1',
        'disposition' => 'accepted',
        'created_at' => now(),
    ]);

    Http::fake([
        '*monitoring/v2/aps*' => Http::response([
            'aps' => [
                ['serial' => 'SNBOTH1', 'status' => 'Up'],
            ],
        ], 200),
    ]);

    $reasons = $this->resolver->resolve($this->client, collect([$device]), true);

    expect($reasons)->toBe([$device->id => 'Already online (webhook).']);
});

it('returns no central reasons when central responds with an error', function () {
    $this->client->update([
        'classic_base_url' => \App\ClassicBaseUrl::US_WEST4,
        'classic_client_id' => 'classic-client-id',
        'classic_client_secret' => 'classic-client-secret',
        'classic_username' => 'classic-user',
        'classic_password' => 'classic-password',
        'classic_refresh_token' => 'refresh-token',
        'classic_access_token' => 'access-token',
        'classic_expires_in' => now()->addHour(),
    ]);

    $device = Device::factory()->for($this->deployment)->create([
        'client_id' => $this->client->id,
        'serial' => 'SNERR1',
        'device_function' => DeviceFunction::CAMPUS_AP->name,
    ]);

    Http::fake([
        '*monitoring/v2/aps*' => Http::response(['error' => 'boom'], 500),
        '*monitoring/v1/switches*' => Http::response(['error' => 'boom'], 500),
    ]);

    expect($this->resolver->resolve($this->client, collect([$device]), true))->toBe([]);
});
