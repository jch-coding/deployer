<?php

use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()
        ->has(Client::factory())
        ->create();
    $this->client = $this->user->clients()->first();
    $this->client->update(['current' => true]);
});

it('shows the device page with interface rows for the current client', function () {
    $deployment = Deployment::factory()->for($this->client)->create();
    $device = Device::factory()->create([
        'deployment_id' => $deployment->id,
        'client_id' => $this->client->id,
    ]);
    DeviceInterface::factory()->for($device)->create([
        'interface' => '0/0/1',
        'shutdown_on_split' => true,
    ]);

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
            ->has('interfaces', fn (Assert $list) => $list
                ->has(0, fn (Assert $row) => $row
                    ->where('interface', '0/0/1')
                    ->where('shutdown_on_split', true)
                    ->etc())));
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
