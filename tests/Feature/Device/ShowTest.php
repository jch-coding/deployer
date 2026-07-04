<?php

use App\InterfaceKind;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\Site;
use App\Models\User;
use App\SwitchSKU;
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

    seedCentralScopeCache($this->client);
});

it('shows the device page with interface rows for the current client', function () {
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
            ->has('interfaces', 25)
            ->where('interfaces.0.interface', '0/0/1')
            ->where('interfaces.0.shutdown_on_split', true)
            ->where('interfaces.0.interface_kind', InterfaceKind::ETHERNET->value));
});

it('includes interface_kind for vlan lag and ethernet interfaces', function () {
    $deployment = Deployment::factory()->for($this->client)->create();
    $device = Device::factory()->create([
        'deployment_id' => $deployment->id,
        'client_id' => $this->client->id,
    ]);
    DeviceInterface::factory()->for($device)->create([
        'interface' => 'vlan-100',
        'interface_kind' => InterfaceKind::VLAN,
        'ip_address' => '192.0.2.1/24',
    ]);
    DeviceInterface::factory()->for($device)->create([
        'interface' => 'lag-1',
        'interface_kind' => InterfaceKind::LAG,
    ]);
    DeviceInterface::factory()->for($device)->create([
        'interface' => '1/1/1',
        'interface_kind' => InterfaceKind::ETHERNET,
    ]);

    $this->actingAs($this->user)
        ->get(route('devices.show', $device))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Device/Show')
            ->has('interfaces', 3)
            ->where('interfaces.0.interface_kind', InterfaceKind::ETHERNET->value)
            ->where('interfaces.1.interface_kind', InterfaceKind::LAG->value)
            ->where('interfaces.2.interface_kind', InterfaceKind::VLAN->value));
});

it('includes central sites and device groups on show', function () {
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

it('updates device site and group via metadata patch without calling Central', function () {
    Http::fake();

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
        ->and($device->site->name)->toBe('Central Site');

    Http::assertNothingSent();
});

it('clears device site via metadata patch', function () {
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
