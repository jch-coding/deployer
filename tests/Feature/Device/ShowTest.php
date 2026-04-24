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
    DeviceInterface::factory()->for($device)->create(['interface' => '0/0/1']);

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
                    ->etc())));
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
