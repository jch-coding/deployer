<?php

use App\DeviceFunction;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = Client::factory()->for($this->user)->create([
        'current' => true,
    ]);
    $this->deployment = Deployment::factory()->for($this->client)->create();
    $this->actingAs($this->user);
});

test('bulk update metadata updates site and group for selected devices', function () {
    Http::fake();

    $deviceA = Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'name' => 'Device A',
        'serial' => 'SERIAL-A-00000001',
        'device_function' => DeviceFunction::CAMPUS_AP->name,
        'group' => null,
    ]);
    $deviceB = Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'name' => 'Device B',
        'serial' => 'SERIAL-B-00000002',
        'device_function' => DeviceFunction::CAMPUS_AP->name,
        'group' => 'Old Group',
    ]);

    $this->from(route('deployments.show', $this->deployment))
        ->post(route('deployments.bulk-update-metadata', $this->deployment), [
            'device_ids' => [$deviceA->id],
            'site' => 'Warehouse',
            'group' => 'Edge Switches',
        ])
        ->assertRedirect(route('deployments.show', $this->deployment))
        ->assertSessionHas('success', 'Updated metadata for 1 device.');

    $deviceA->refresh();
    expect($deviceA->group)->toBe('Edge Switches')
        ->and($deviceA->site?->name)->toBe('Warehouse')
        ->and($deviceB->fresh()->group)->toBe('Old Group');

    $this->assertDatabaseHas('sites', [
        'client_id' => $this->client->id,
        'name' => 'Warehouse',
    ]);

    Http::assertNothingSent();
});

test('bulk update metadata sync all respects search filter', function () {
    $alpha = Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'name' => 'Alpha Device',
        'serial' => 'SERIAL-ALPHA-100',
        'device_function' => DeviceFunction::CAMPUS_AP->name,
        'group' => null,
    ]);
    $beta = Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'name' => 'Beta Device',
        'serial' => 'SERIAL-BETA-200',
        'device_function' => DeviceFunction::CAMPUS_AP->name,
        'group' => 'Keep Group',
    ]);

    $this->post(route('deployments.bulk-update-metadata', $this->deployment), [
        'sync_all' => true,
        'search' => 'Alpha',
        'group' => 'Matched Group',
    ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Updated metadata for 1 device.');

    expect($alpha->fresh()->group)->toBe('Matched Group')
        ->and($beta->fresh()->group)->toBe('Keep Group');
});

test('bulk update metadata can clear site and group', function () {
    $site = Site::factory()->for($this->client)->create(['name' => 'Old Site']);
    $device = Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'site_id' => $site->id,
        'group' => 'Old Group',
    ]);

    $this->post(route('deployments.bulk-update-metadata', $this->deployment), [
        'device_ids' => [$device->id],
        'site' => null,
        'group' => null,
    ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Updated metadata for 1 device.');

    $device->refresh();
    expect($device->site_id)->toBeNull()
        ->and($device->group)->toBeNull();
});

test('bulk update metadata rejects devices outside deployment', function () {
    $otherDeployment = Deployment::factory()->for($this->client)->create();
    $foreignDevice = Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $otherDeployment->id,
    ]);

    $this->post(route('deployments.bulk-update-metadata', $this->deployment), [
        'device_ids' => [$foreignDevice->id],
        'group' => 'Some Group',
    ])
        ->assertRedirect()
        ->assertSessionHas('error', 'One or more selected devices do not belong to this deployment.');
});

test('bulk update metadata rejects when current client does not match deployment', function () {
    $otherClient = Client::factory()->for($this->user)->create(['current' => false]);
    $otherDeployment = Deployment::factory()->for($otherClient)->create();
    $device = Device::factory()->create([
        'client_id' => $otherClient->id,
        'user_id' => $this->user->id,
        'deployment_id' => $otherDeployment->id,
    ]);

    $this->post(route('deployments.bulk-update-metadata', $otherDeployment), [
        'device_ids' => [$device->id],
        'group' => 'Some Group',
    ])
        ->assertRedirect()
        ->assertSessionHas('error', 'Please set current client to match this deployment before updating device metadata.');
});

test('bulk update metadata requires site or group', function () {
    $device = Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
    ]);

    $this->post(route('deployments.bulk-update-metadata', $this->deployment), [
        'device_ids' => [$device->id],
    ])
        ->assertSessionHasErrors('site');
});
