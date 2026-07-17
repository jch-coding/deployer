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
    $this->actingAs($this->user);
});

function createVlanInterface(
    Deployment $deployment,
    string $deviceName,
    string $vlanId,
    ?string $ipAddress,
): DeviceInterface {
    $device = Device::factory()->create([
        'client_id' => $deployment->client_id,
        'user_id' => $deployment->client->user_id,
        'deployment_id' => $deployment->id,
        'name' => $deviceName,
    ]);

    return DeviceInterface::factory()->create([
        'device_id' => $device->id,
        'interface' => $vlanId,
        'interface_kind' => InterfaceKind::VLAN,
        'ip_address' => $ipAddress,
    ]);
}

test('check vlan ip addresses flashes success when all vlan ips are unique', function () {
    createVlanInterface($this->deployment, 'switch-a', '100', '10.0.0.1');
    createVlanInterface($this->deployment, 'switch-b', '200', '10.0.0.2');

    $this->post(route('tasks.check_vlan_ip_addresses', $this->deployment), [
        'task_type' => 'CONFIGURE_VLAN_INTERFACE',
    ])
        ->assertRedirect()
        ->assertSessionHas('success', 'All VLAN IP addresses are unique across devices.');
});

test('check vlan ip addresses flashes error when two devices share the same ip', function () {
    createVlanInterface($this->deployment, 'switch-a', '100', '10.0.0.1');
    createVlanInterface($this->deployment, 'switch-b', '200', '10.0.0.1');

    $this->post(route('tasks.check_vlan_ip_addresses', $this->deployment), [
        'task_type' => 'CONFIGURE_VLAN_INTERFACE',
    ])
        ->assertRedirect()
        ->assertSessionHas(
            'error',
            'Duplicate VLAN IP 10.0.0.1: switch-a (VLAN 100), switch-b (VLAN 200).',
        );
});

test('check vlan ip addresses ignores empty ip addresses', function () {
    createVlanInterface($this->deployment, 'switch-a', '100', null);
    createVlanInterface($this->deployment, 'switch-b', '200', '');

    $this->post(route('tasks.check_vlan_ip_addresses', $this->deployment), [
        'task_type' => 'CONFIGURE_VLAN_INTERFACE',
    ])
        ->assertRedirect()
        ->assertSessionHas('success', 'All VLAN IP addresses are unique across devices.');
});

test('check vlan ip addresses flashes success when deployment has no vlan interfaces', function () {
    $this->post(route('tasks.check_vlan_ip_addresses', $this->deployment), [
        'task_type' => 'CONFIGURE_VLAN_INTERFACE',
    ])
        ->assertRedirect()
        ->assertSessionHas('success', 'All VLAN IP addresses are unique across devices.');
});

test('check vlan ip addresses accepts CONFIGURE_ALL_INTERFACE task type', function () {
    createVlanInterface($this->deployment, 'switch-a', '100', '10.0.0.1');

    $this->post(route('tasks.check_vlan_ip_addresses', $this->deployment), [
        'task_type' => 'CONFIGURE_ALL_INTERFACE',
    ])
        ->assertRedirect()
        ->assertSessionHas('success', 'All VLAN IP addresses are unique across devices.');
});

test('check vlan ip addresses rejects when current client does not match deployment', function () {
    $otherClient = Client::factory()->for($this->user)->create(['current' => false]);
    $otherDeployment = Deployment::factory()->for($otherClient)->create();

    createVlanInterface($otherDeployment, 'switch-a', '100', '10.0.0.1');

    $this->post(route('tasks.check_vlan_ip_addresses', $otherDeployment), [
        'task_type' => 'CONFIGURE_VLAN_INTERFACE',
    ])
        ->assertRedirect()
        ->assertSessionHas(
            'error',
            'Please set current client to match this deployment before checking VLAN IP addresses.',
        );
});
