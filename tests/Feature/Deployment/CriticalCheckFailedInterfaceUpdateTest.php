<?php

use App\InterfaceKind;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = Client::factory()->for($this->user)->create(['current' => true]);
    $this->deployment = Deployment::factory()->for($this->client)->create();
    $this->device = Device::factory()->for($this->deployment)->create();
    $this->actingAs($this->user);
});

test('patch critical check failed interfaces updates vlan ip address', function () {
    $interface = DeviceInterface::factory()->create([
        'device_id' => $this->device->id,
        'interface' => '20',
        'interface_kind' => InterfaceKind::VLAN,
        'ip_address' => '10.0.0.1/24',
    ]);

    $this->patchJson(route('deployments.critical_check.failed_interfaces', $this->deployment), [
        'updates' => [[
            'device_interface_id' => $interface->id,
            'kind' => 'vlan',
            'attributes' => ['ipv4.address' => '10.0.0.9/24'],
        ]],
    ])->assertOk()->assertJsonPath('ok', true);

    expect($interface->refresh()->ip_address)->toBe('10.0.0.9/24');
});

test('patch critical check failed interfaces rejects foreign deployment interfaces', function () {
    $otherDeployment = Deployment::factory()->for($this->client)->create();
    $otherDevice = Device::factory()->for($otherDeployment)->create();
    $foreign = DeviceInterface::factory()->create(['device_id' => $otherDevice->id]);

    $this->patchJson(route('deployments.critical_check.failed_interfaces', $this->deployment), [
        'updates' => [[
            'device_interface_id' => $foreign->id,
            'kind' => 'vlan',
            'attributes' => ['ipv4.address' => '10.0.0.9/24'],
        ]],
    ])->assertStatus(422);
});
