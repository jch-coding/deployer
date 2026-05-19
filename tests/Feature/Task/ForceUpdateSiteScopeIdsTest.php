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
        'expires_at' => now()->addHour(),
        'current' => true,
    ]);
    $this->deployment = Deployment::factory()->for($this->client)->create();
    $this->actingAs($this->user);
});

test('force update site scope ids updates all deployment sites from modern Central', function () {
    Http::fake([
        '*network-config/v1/sites*' => Http::response([
            'items' => [
                ['scopeName' => 'SiteA', 'scopeId' => 'scope-a-new'],
                ['scopeName' => 'SiteB', 'scopeId' => 'scope-b-new'],
            ],
        ], 200),
    ]);

    $siteA = Site::factory()->for($this->client)->create(['name' => 'SiteA', 'scope_id' => 'old-a']);
    $siteB = Site::factory()->for($this->client)->create(['name' => 'SiteB', 'scope_id' => null]);

    Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'site_id' => $siteA->id,
    ]);
    Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'site_id' => $siteB->id,
    ]);

    $this->post(route('tasks.force_update_site_scope_ids', $this->deployment), [
        'task_type' => 'ASSOCIATE_DEVICE_TO_SITE',
    ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Updated scope IDs: SiteA: scope-a-new, SiteB: scope-b-new.');

    expect($siteA->fresh()->scope_id)->toBe('scope-a-new')
        ->and($siteB->fresh()->scope_id)->toBe('scope-b-new');
});

test('force update site scope ids flashes success with site name and scope id for one site', function () {
    Http::fake([
        '*network-config/v1/sites*' => Http::response([
            'items' => [
                ['scopeName' => 'MySite', 'scopeId' => 'scope-xyz'],
            ],
        ], 200),
    ]);

    $site = Site::factory()->for($this->client)->create(['name' => 'MySite', 'scope_id' => null]);

    Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'site_id' => $site->id,
    ]);

    $this->post(route('tasks.force_update_site_scope_ids', $this->deployment), [
        'task_type' => 'ASSOCIATE_DEVICE_TO_SITE',
    ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Updated scope ID for MySite: scope-xyz.');

    expect($site->fresh()->scope_id)->toBe('scope-xyz');
});

test('force update site scope ids flashes error when modern Central lookup fails', function () {
    Http::fake([
        '*network-config/v1/sites*' => Http::response(['detail' => 'nope'], 500),
    ]);

    $site = Site::factory()->for($this->client)->create(['name' => 'MySite', 'scope_id' => 'existing']);

    Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'site_id' => $site->id,
    ]);

    $this->post(route('tasks.force_update_site_scope_ids', $this->deployment), [
        'task_type' => 'ASSOCIATE_SITE_AND_NAME',
    ])
        ->assertRedirect()
        ->assertSessionHas('error', 'Could not load site scope IDs from Central.');

    expect($site->fresh()->scope_id)->toBe('existing');
});

test('force update site scope ids rejects when current client does not match deployment', function () {
    Http::fake();

    $otherClient = Client::factory()->for($this->user)->create(['current' => false]);
    $otherDeployment = Deployment::factory()->for($otherClient)->create();

    $site = Site::factory()->for($this->client)->create(['name' => 'S']);

    Device::factory()->create([
        'client_id' => $otherClient->id,
        'user_id' => $this->user->id,
        'deployment_id' => $otherDeployment->id,
        'site_id' => $site->id,
    ]);

    $this->post(route('tasks.force_update_site_scope_ids', $otherDeployment), [
        'task_type' => 'ASSOCIATE_DEVICE_TO_SITE',
    ])
        ->assertRedirect()
        ->assertSessionHas('error', 'Please set current client to match this deployment before updating site scope IDs.');

    Http::assertNothingSent();
});
