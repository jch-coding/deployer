<?php

use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\SwitchPort;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()
        ->has(Client::factory())
        ->create();
    $this->client = $this->user->clients()->first();
    $this->client->update(['current' => true]);
});

it('deletes a device interface and removes an orphan switch port', function () {
    $deployment = Deployment::factory()->for($this->client)->create();
    $device = Device::factory()->create([
        'deployment_id' => $deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
    ]);
    $switchPort = SwitchPort::factory()->create([
        'interface_mode' => 'ACCESS',
        'access_vlan' => 10,
    ]);
    $iface = DeviceInterface::factory()->for($device)->create([
        'switch_port_id' => $switchPort->id,
    ]);

    $this->actingAs($this->user)
        ->from(route('devices.show', $device))
        ->delete(route('devices.interfaces.destroy', [$device, $iface]))
        ->assertRedirect();

    $this->assertDatabaseMissing('device_interfaces', ['id' => $iface->id]);
    $this->assertDatabaseMissing('switch_ports', ['id' => $switchPort->id]);
});

it('does not delete a switch port still referenced by another device interface', function () {
    $deployment = Deployment::factory()->for($this->client)->create();
    $deviceA = Device::factory()->create([
        'deployment_id' => $deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
    ]);
    $deviceB = Device::factory()->create([
        'deployment_id' => $deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
    ]);
    $switchPort = SwitchPort::factory()->create([
        'interface_mode' => 'ACCESS',
        'access_vlan' => 20,
    ]);
    $ifaceA = DeviceInterface::factory()->for($deviceA)->create([
        'interface' => '1/1/1',
        'switch_port_id' => $switchPort->id,
    ]);
    DeviceInterface::factory()->for($deviceB)->create([
        'interface' => '1/1/1',
        'switch_port_id' => $switchPort->id,
    ]);

    $this->actingAs($this->user)
        ->delete(route('devices.interfaces.destroy', [$deviceA, $ifaceA]))
        ->assertRedirect();

    $this->assertDatabaseMissing('device_interfaces', ['id' => $ifaceA->id]);
    $this->assertDatabaseHas('switch_ports', ['id' => $switchPort->id]);
});

it('returns 403 when the user cannot manage the device', function () {
    $other = User::factory()
        ->has(Client::factory())
        ->create();
    $otherClient = $other->clients()->first();
    $otherClient->update(['current' => true]);

    $deployment = Deployment::factory()->for($this->client)->create();
    $device = Device::factory()->create([
        'deployment_id' => $deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
    ]);
    $iface = DeviceInterface::factory()->for($device)->create();

    $this->actingAs($other)
        ->delete(route('devices.interfaces.destroy', [$device, $iface]))
        ->assertForbidden();
});

it('returns 404 when the interface belongs to another device', function () {
    $deployment = Deployment::factory()->for($this->client)->create();
    $deviceOne = Device::factory()->create([
        'deployment_id' => $deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
    ]);
    $deviceTwo = Device::factory()->create([
        'deployment_id' => $deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
    ]);
    $ifaceOnTwo = DeviceInterface::factory()->for($deviceTwo)->create();

    $this->actingAs($this->user)
        ->delete(route('devices.interfaces.destroy', [$deviceOne, $ifaceOnTwo]))
        ->assertNotFound();

    $this->assertDatabaseHas('device_interfaces', ['id' => $ifaceOnTwo->id]);
});
