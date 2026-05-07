<?php

use App\ClassicBaseUrl;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\Site;
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

test('check central sites flashes success when all device site names exist in Central', function () {
    Http::fake([
        '*central/v2/sites*' => Http::sequence()
            ->push(['sites' => [['site_id' => '1', 'site_name' => 'MySite']]], 200)
            ->push(['sites' => []], 200),
    ]);

    $site = Site::factory()->create(['name' => 'MySite']);

    Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'site_id' => $site->id,
    ]);

    $this->post(route('tasks.check_central_sites', $this->deployment), [
        'task_type' => 'ASSOCIATE_SITE_AND_NAME',
    ])
        ->assertRedirect()
        ->assertSessionHas('success', 'All site names exist in Central.');
});

test('check central sites flashes error listing sites missing in Central', function () {
    Http::fake([
        '*central/v2/sites*' => Http::sequence()
            ->push(['sites' => [['site_id' => '1', 'site_name' => 'ExistsOnly']]], 200)
            ->push(['sites' => []], 200),
    ]);

    $site = Site::factory()->create(['name' => 'NotInCentral']);

    Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'site_id' => $site->id,
    ]);

    $this->post(route('tasks.check_central_sites', $this->deployment), [
        'task_type' => 'ASSOCIATE_DEVICE_TO_SITE',
    ])
        ->assertRedirect()
        ->assertSessionHas('error', 'These sites were not found in Central: NotInCentral.');
});

test('check central sites flashes error when Central sites request fails', function () {
    Http::fake([
        '*central/v2/sites*' => Http::response(['detail' => 'nope'], 500),
    ]);

    $site = Site::factory()->create(['name' => 'Any']);

    Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'site_id' => $site->id,
    ]);

    $this->post(route('tasks.check_central_sites', $this->deployment), [
        'task_type' => 'ASSOCIATE_DEVICE_TO_SITE',
    ])
        ->assertRedirect()
        ->assertSessionHas('error', 'Could not load sites from Central.');
});

test('check central sites flashes error when devices have no site assigned', function () {
    Http::fake();

    Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'site_id' => null,
        'name' => 'NoSiteDevice',
    ]);

    $this->post(route('tasks.check_central_sites', $this->deployment), [
        'task_type' => 'ASSOCIATE_DEVICE_TO_SITE',
    ])
        ->assertRedirect()
        ->assertSessionHas('error', 'These devices have no site assigned: NoSiteDevice.');

    Http::assertNothingSent();
});

test('check central sites rejects when current client does not match deployment', function () {
    Http::fake();

    $otherClient = Client::factory()->for($this->user)->create(['current' => false]);
    $otherDeployment = Deployment::factory()->for($otherClient)->create();

    $site = Site::factory()->create(['name' => 'S']);

    Device::factory()->create([
        'client_id' => $otherClient->id,
        'user_id' => $this->user->id,
        'deployment_id' => $otherDeployment->id,
        'site_id' => $site->id,
    ]);

    $this->post(route('tasks.check_central_sites', $otherDeployment), [
        'task_type' => 'ASSOCIATE_DEVICE_TO_SITE',
    ])
        ->assertRedirect()
        ->assertSessionHas('error', 'Please set current client to match this deployment before checking sites.');

    Http::assertNothingSent();
});
