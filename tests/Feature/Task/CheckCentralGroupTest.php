<?php

use App\ClassicBaseUrl;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = Client::factory()->for($this->user)->create([
        'classic_base_url' => ClassicBaseUrl::US1,
        'classic_client_id' => 'classic-id',
        'classic_client_secret' => 'classic-secret',
        'classic_username' => 'user',
        'classic_password' => 'pass',
        'classic_refresh_token' => 'refresh',
        'classic_expires_in' => now()->addHour(),
        'classic_access_token' => 'access-token',
        'current' => true,
    ]);
    $this->deployment = Deployment::factory()->for($this->client)->create();
    $this->actingAs($this->user);
});

test('check central group flashes success when all device groups exist in Central', function () {
    Http::fake([
        '*configuration/v2/groups*' => Http::sequence()
            ->push(['data' => [['MyGroup', 'Other']]], 200)
            ->push(['data' => []], 200),
    ]);

    Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'group' => 'MyGroup',
    ]);

    $this->post(route('tasks.check_central_group', $this->deployment), [
        'task_type' => 'PREPROVISION_DEVICE_TO_GROUP',
    ])
        ->assertRedirect()
        ->assertSessionHas('success', 'All group names exist in Central.');
});

test('check central group flashes error listing groups missing in Central', function () {
    Http::fake([
        '*configuration/v2/groups*' => Http::response(['data' => [['ExistsOnly']]], 200),
    ]);

    Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'group' => 'NotInCentral',
    ]);

    $this->post(route('tasks.check_central_group', $this->deployment), [
        'task_type' => 'MOVE_DEVICE_TO_GROUP',
    ])
        ->assertRedirect()
        ->assertSessionHas('error', 'These groups were not found in Central: NotInCentral.');
});

test('check central group flashes error when Central groups request fails', function () {
    Http::fake([
        '*configuration/v2/groups*' => Http::response(['detail' => 'nope'], 500),
    ]);

    Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'group' => 'Any',
    ]);

    $this->post(route('tasks.check_central_group', $this->deployment), [
        'task_type' => 'PREPROVISION_DEVICE_TO_GROUP',
    ])
        ->assertRedirect()
        ->assertSessionHas('error', 'Could not load groups from Central.');
});

test('check central group flashes error when no group names on deployment devices', function () {
    Http::fake();

    Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'group' => null,
    ]);

    $this->post(route('tasks.check_central_group', $this->deployment), [
        'task_type' => 'MOVE_DEVICE_TO_GROUP',
    ])
        ->assertRedirect()
        ->assertSessionHas('error', 'No group names are set on devices in this deployment.');

    Http::assertNothingSent();
});

test('check central group uses prefixed WHSE groups for ADD_VLANS_TO_DEVICE_GROUP', function () {
    Http::fake([
        '*configuration/v2/groups*' => Http::sequence()
            ->push(['data' => [[
                'WHSE-SAC-ACCESS',
                'WHSE-SAC-CORE',
                'WHSE-SAC-MGMT',
                'WHSE-SAC-DMZ',
                'WHSE-SAC-SERVER',
            ]]], 200)
            ->push(['data' => []], 200),
    ]);

    $this->post(route('tasks.check_central_group', $this->deployment), [
        'task_type' => 'ADD_VLANS_TO_DEVICE_GROUP',
        'vlan_site_prefix' => 'SAC',
    ])
        ->assertRedirect()
        ->assertSessionHas('success', 'All group names exist in Central.');
});

test('check central group rejects invalid vlan_site_prefix for ADD_VLANS_TO_DEVICE_GROUP', function () {
    Http::fake();

    $this->post(route('tasks.check_central_group', $this->deployment), [
        'task_type' => 'ADD_VLANS_TO_DEVICE_GROUP',
        'vlan_site_prefix' => 'BAD PREFIX',
    ])
        ->assertRedirect()
        ->assertSessionHas('error', 'Invalid site prefix.');

    Http::assertNothingSent();
});

test('check central group rejects when current client does not match deployment', function () {
    Http::fake();

    $otherClient = Client::factory()->for($this->user)->create(['current' => false]);
    $otherDeployment = Deployment::factory()->for($otherClient)->create();

    Device::factory()->create([
        'client_id' => $otherClient->id,
        'user_id' => $this->user->id,
        'deployment_id' => $otherDeployment->id,
        'group' => 'G',
    ]);

    $this->post(route('tasks.check_central_group', $otherDeployment), [
        'task_type' => 'PREPROVISION_DEVICE_TO_GROUP',
    ])
        ->assertRedirect()
        ->assertSessionHas('error', 'Please set current client to match this deployment before checking groups.');

    Http::assertNothingSent();
});
