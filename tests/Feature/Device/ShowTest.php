<?php

use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\Site;
use App\Models\User;
use App\SwitchSKU;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()
        ->has(Client::factory())
        ->create();
    $this->client = $this->user->clients()->first();
    $this->client->update([
        'current' => true,
        'bearer_token' => 'test-bearer-token',
        'expires_at' => now()->addHour(),
    ]);
});

function fakeCentralScopeManagementApis(): void
{
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'network-config/v1/sites')) {
            return Http::response([
                'items' => [
                    ['scopeName' => 'Central Site', 'scopeId' => 'scope-site'],
                ],
            ], 200);
        }

        if (str_contains($request->url(), 'device-groups')) {
            return Http::response([
                'items' => [
                    ['scopeName' => 'Central Group', 'scopeId' => 'scope-group'],
                ],
            ], 200);
        }

        return Http::response([], 404);
    });
}

it('shows the device page with interface rows for the current client', function () {
    fakeCentralScopeManagementApis();

    $deployment = Deployment::factory()->for($this->client)->create();
    $device = Device::factory()->create([
        'deployment_id' => $deployment->id,
        'client_id' => $this->client->id,
    ]);
    DeviceInterface::factory()
        ->for($device)
        ->count(25)
        ->sequence(fn ($sequence) => ['interface' => '1/1/'.($sequence->index + 1)])
        ->create();
    DeviceInterface::query()
        ->where('device_id', $device->id)
        ->orderBy('id')
        ->first()
        ?->update(['interface' => '0/0/1', 'shutdown_on_split' => true]);

    $this->actingAs($this->user)
        ->get(route('devices.show', $device))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Device/Show')
            ->has('device', fn (Assert $d) => $d
                ->where('id', $device->id)
                ->where('name', $device->name)
                ->etc())
            ->has('deployment', fn (Assert $d) => $d
                ->where('id', $deployment->id)
                ->where('name', $deployment->name))
            ->has('interfaces', fn (Assert $interfaces) => $interfaces
                ->where('current_page', 1)
                ->where('per_page', 20)
                ->where('total', 25)
                ->has('data', 20)
                ->has('data.0', fn (Assert $row) => $row
                    ->where('interface', '0/0/1')
                    ->where('shutdown_on_split', true)
                    ->etc())
                ->has('links')
                ->etc()));
});

it('includes central sites and device groups on show', function () {
    fakeCentralScopeManagementApis();

    $deployment = Deployment::factory()->for($this->client)->create();
    $device = Device::factory()->create([
        'deployment_id' => $deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('devices.show', $device))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Device/Show')
            ->where('central_sites_error', null)
            ->where('central_device_groups_error', null)
            ->has('central_sites', 1)
            ->has('central_device_groups', 1)
            ->where('central_sites.0.scopeName', 'Central Site')
            ->where('central_device_groups.0.scopeName', 'Central Group'));
});

it('includes sku in device props when set', function () {
    fakeCentralScopeManagementApis();

    $deployment = Deployment::factory()->for($this->client)->create();
    $device = Device::factory()->create([
        'deployment_id' => $deployment->id,
        'client_id' => $this->client->id,
        'sku' => SwitchSKU::JL660A,
    ]);

    $this->actingAs($this->user)
        ->get(route('devices.show', $device))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Device/Show')
            ->has('device', fn (Assert $d) => $d
                ->where('sku', 'JL660A')
                ->etc()));
});

it('updates shutdown_on_split from the interface edit endpoint', function () {
    $deployment = Deployment::factory()->for($this->client)->create();
    $device = Device::factory()->create([
        'deployment_id' => $deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
    ]);
    $interface = DeviceInterface::factory()->for($device)->create([
        'shutdown_on_split' => false,
    ]);

    $this->actingAs($this->user)
        ->patch(route('devices.interfaces.update', $device), [
            'updates' => [
                [
                    'id' => $interface->id,
                    'shutdown_on_split' => true,
                ],
            ],
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('device_interfaces', [
        'id' => $interface->id,
        'shutdown_on_split' => true,
    ]);
});

it('updates device site and group via metadata patch', function () {
    fakeCentralScopeManagementApis();

    $deployment = Deployment::factory()->for($this->client)->create();
    $device = Device::factory()->create([
        'deployment_id' => $deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'group' => null,
    ]);

    $this->actingAs($this->user)
        ->from(route('devices.show', $device))
        ->patch(route('devices.update-metadata', $device), [
            'site' => 'Central Site',
            'group' => 'Central Group',
        ])
        ->assertRedirect(route('devices.show', $device));

    $device->refresh()->load('site');

    expect($device->group)->toBe('Central Group')
        ->and($device->site)->not->toBeNull()
        ->and($device->site->name)->toBe('Central Site')
        ->and($device->site->scope_id)->toBe('scope-site');
});

it('clears device site via metadata patch', function () {
    fakeCentralScopeManagementApis();

    $deployment = Deployment::factory()->for($this->client)->create();
    $site = Site::factory()->for($this->client)->create(['name' => 'Old Site']);
    $device = Device::factory()->create([
        'deployment_id' => $deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'site_id' => $site->id,
    ]);

    $this->actingAs($this->user)
        ->from(route('devices.show', $device))
        ->patch(route('devices.update-metadata', $device), [
            'site' => null,
        ])
        ->assertRedirect(route('devices.show', $device));

    expect($device->fresh()->site_id)->toBeNull();
});

it('returns forbidden when patching metadata for another clients device', function () {
    $otherUser = User::factory()
        ->has(Client::factory())
        ->create();
    $otherClient = $otherUser->clients()->first();

    $deployment = Deployment::factory()->for($otherClient)->create();
    $device = Device::factory()->create([
        'deployment_id' => $deployment->id,
        'client_id' => $otherClient->id,
        'user_id' => $otherUser->id,
    ]);

    $this->actingAs($this->user)
        ->patch(route('devices.update-metadata', $device), [
            'group' => 'Central Group',
        ])
        ->assertForbidden();
});

it('returns forbidden when the device belongs to another client', function () {
    $otherUser = User::factory()
        ->has(Client::factory())
        ->create();
    $otherClient = $otherUser->clients()->first();

    $deployment = Deployment::factory()->for($otherClient)->create();
    $device = Device::factory()->create([
        'deployment_id' => $deployment->id,
        'client_id' => $otherClient->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('devices.show', $device))
        ->assertForbidden();
});
