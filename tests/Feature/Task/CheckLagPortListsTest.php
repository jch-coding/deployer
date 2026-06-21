<?php

use App\InterfaceKind;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\LacpProfile;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = Client::factory()->for($this->user)->create(['current' => true]);
    $this->deployment = Deployment::factory()->for($this->client)->create();
    $this->actingAs($this->user);
});

function createDeploymentDevice(Deployment $deployment, string $deviceName): Device
{
    return Device::factory()->create([
        'client_id' => $deployment->client_id,
        'user_id' => $deployment->client->user_id,
        'deployment_id' => $deployment->id,
        'name' => $deviceName,
    ]);
}

function createLagInterface(
    Device $device,
    string $lagInterface,
    string $portList,
): DeviceInterface {
    $lacpProfile = LacpProfile::factory()->create([
        'port_list' => $portList,
    ]);

    return DeviceInterface::factory()->create([
        'device_id' => $device->id,
        'interface' => $lagInterface,
        'interface_kind' => InterfaceKind::LAG,
        'lacp_profile_id' => $lacpProfile->id,
    ]);
}

test('check lag port lists flashes success when port lists are disjoint on a device', function () {
    $device = createDeploymentDevice($this->deployment, 'switch-a');
    createLagInterface($device, '10', '1/1/1&1/1/2');
    createLagInterface($device, '20', '1/1/3&1/1/4');

    $this->post(route('tasks.check_lag_port_lists', $this->deployment), [
        'task_type' => 'CONFIGURE_LAG_INTERFACE',
    ])
        ->assertRedirect()
        ->assertSessionHas('success', 'All LAG port lists are unique on each device.');
});

test('check lag port lists flashes error when two lags on the same device share a port', function () {
    $device = createDeploymentDevice($this->deployment, 'switch-a');
    createLagInterface($device, '10', '1/1/1&1/1/2');
    createLagInterface($device, '20', '1/1/2&1/1/3');

    $this->post(route('tasks.check_lag_port_lists', $this->deployment), [
        'task_type' => 'CONFIGURE_LAG_INTERFACE',
    ])
        ->assertRedirect()
        ->assertSessionHas(
            'error',
            'On switch-a, port 1/1/2 is shared by LAG interfaces 10 and 20.',
        );
});

test('check lag port lists detects overlap via range expansion', function () {
    $device = createDeploymentDevice($this->deployment, 'switch-a');
    createLagInterface($device, '10', '1/1/1-1/1/2');
    createLagInterface($device, '20', '1/1/2&1/1/3');

    $this->post(route('tasks.check_lag_port_lists', $this->deployment), [
        'task_type' => 'CONFIGURE_LAG_INTERFACE',
    ])
        ->assertRedirect()
        ->assertSessionHas(
            'error',
            'On switch-a, port 1/1/2 is shared by LAG interfaces 10 and 20.',
        );
});

test('check lag port lists allows the same port on different devices', function () {
    createLagInterface(createDeploymentDevice($this->deployment, 'switch-a'), '10', '1/1/1&1/1/2');
    createLagInterface(createDeploymentDevice($this->deployment, 'switch-b'), '10', '1/1/1&1/1/2');

    $this->post(route('tasks.check_lag_port_lists', $this->deployment), [
        'task_type' => 'CONFIGURE_LAG_INTERFACE',
    ])
        ->assertRedirect()
        ->assertSessionHas('success', 'All LAG port lists are unique on each device.');
});

test('check lag port lists flashes success when deployment has no lag interfaces', function () {
    $this->post(route('tasks.check_lag_port_lists', $this->deployment), [
        'task_type' => 'CONFIGURE_LAG_INTERFACE',
    ])
        ->assertRedirect()
        ->assertSessionHas('success', 'All LAG port lists are unique on each device.');
});

test('check lag port lists accepts CONFIGURE_ALL_INTERFACE task type', function () {
    createLagInterface(createDeploymentDevice($this->deployment, 'switch-a'), '10', '1/1/1&1/1/2');

    $this->post(route('tasks.check_lag_port_lists', $this->deployment), [
        'task_type' => 'CONFIGURE_ALL_INTERFACE',
    ])
        ->assertRedirect()
        ->assertSessionHas('success', 'All LAG port lists are unique on each device.');
});

test('check lag port lists rejects when current client does not match deployment', function () {
    $otherClient = Client::factory()->for($this->user)->create(['current' => false]);
    $otherDeployment = Deployment::factory()->for($otherClient)->create();

    createLagInterface(createDeploymentDevice($otherDeployment, 'switch-a'), '10', '1/1/1&1/1/2');

    $this->post(route('tasks.check_lag_port_lists', $otherDeployment), [
        'task_type' => 'CONFIGURE_LAG_INTERFACE',
    ])
        ->assertRedirect()
        ->assertSessionHas(
            'error',
            'Please set current client to match this deployment before checking LAG port lists.',
        );
});
