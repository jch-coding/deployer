<?php

use App\DeviceFunction;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = Client::factory()->for($this->user)->create([
        'current' => true,
    ]);
    $this->deployment = Deployment::factory()->for($this->client)->create([
        'name' => 'Source Deployment',
    ]);
    $this->targetDeployment = Deployment::factory()->for($this->client)->create([
        'name' => 'Target Deployment',
    ]);
    $this->actingAs($this->user);
});

test('bulk move moves selected devices to another deployment', function () {
    $deviceA = Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'name' => 'Device A',
        'serial' => 'SERIAL-A-00000001',
        'device_function' => DeviceFunction::CAMPUS_AP->name,
    ]);
    $deviceB = Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'name' => 'Device B',
        'serial' => 'SERIAL-B-00000002',
        'device_function' => DeviceFunction::CAMPUS_AP->name,
    ]);

    $this->from(route('deployments.show', $this->deployment))
        ->post(route('deployments.bulk-move', $this->deployment), [
            'device_ids' => [$deviceA->id],
            'target_deployment_id' => $this->targetDeployment->id,
        ])
        ->assertRedirect(route('deployments.show', $this->deployment))
        ->assertSessionHas('success', 'Moved 1 device to Target Deployment.');

    expect($deviceA->fresh()->deployment_id)->toBe($this->targetDeployment->id)
        ->and($deviceB->fresh()->deployment_id)->toBe($this->deployment->id);
});

test('bulk move sync all respects search filter', function () {
    $alpha = Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'name' => 'Alpha Device',
        'serial' => 'SERIAL-ALPHA-100',
        'device_function' => DeviceFunction::CAMPUS_AP->name,
    ]);
    $beta = Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'name' => 'Beta Device',
        'serial' => 'SERIAL-BETA-200',
        'device_function' => DeviceFunction::CAMPUS_AP->name,
    ]);

    $this->post(route('deployments.bulk-move', $this->deployment), [
        'sync_all' => true,
        'search' => 'Alpha',
        'target_deployment_id' => $this->targetDeployment->id,
    ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Moved 1 device to Target Deployment.');

    expect($alpha->fresh()->deployment_id)->toBe($this->targetDeployment->id)
        ->and($beta->fresh()->deployment_id)->toBe($this->deployment->id);
});

test('bulk move rejects target on a different client', function () {
    $otherClient = Client::factory()->for($this->user)->create(['current' => false]);
    $otherDeployment = Deployment::factory()->for($otherClient)->create([
        'name' => 'Other Client Deployment',
    ]);
    $device = Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
    ]);

    $this->post(route('deployments.bulk-move', $this->deployment), [
        'device_ids' => [$device->id],
        'target_deployment_id' => $otherDeployment->id,
    ])
        ->assertSessionHasErrors('target_deployment_id');

    expect($device->fresh()->deployment_id)->toBe($this->deployment->id);
});

test('bulk move rejects moving to the same deployment', function () {
    $device = Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
    ]);

    $this->post(route('deployments.bulk-move', $this->deployment), [
        'device_ids' => [$device->id],
        'target_deployment_id' => $this->deployment->id,
    ])
        ->assertSessionHasErrors('target_deployment_id');

    expect($device->fresh()->deployment_id)->toBe($this->deployment->id);
});

test('bulk move rejects devices outside deployment', function () {
    $foreignDevice = Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->targetDeployment->id,
    ]);

    $this->post(route('deployments.bulk-move', $this->deployment), [
        'device_ids' => [$foreignDevice->id],
        'target_deployment_id' => $this->targetDeployment->id,
    ])
        ->assertRedirect()
        ->assertSessionHas('error', 'One or more selected devices do not belong to this deployment.');

    expect($foreignDevice->fresh()->deployment_id)->toBe($this->targetDeployment->id);
});

test('bulk move rejects when current client does not match deployment', function () {
    $otherClient = Client::factory()->for($this->user)->create(['current' => false]);
    $otherDeployment = Deployment::factory()->for($otherClient)->create();
    $otherTarget = Deployment::factory()->for($otherClient)->create();
    $device = Device::factory()->create([
        'client_id' => $otherClient->id,
        'user_id' => $this->user->id,
        'deployment_id' => $otherDeployment->id,
    ]);

    $this->post(route('deployments.bulk-move', $otherDeployment), [
        'device_ids' => [$device->id],
        'target_deployment_id' => $otherTarget->id,
    ])
        ->assertRedirect()
        ->assertSessionHas('error', 'Please set current client to match this deployment before moving devices.');

    expect($device->fresh()->deployment_id)->toBe($otherDeployment->id);
});
