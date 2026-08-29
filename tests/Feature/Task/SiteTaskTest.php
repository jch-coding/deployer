<?php

use App\ClassicBaseUrl;
use App\Jobs\CreateSiteJob;
use App\Jobs\UpdateSiteJob;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
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
        'expires_at' => now()->addHour(),
        'current' => true,
    ]);
    $this->deployment = Deployment::factory()->for($this->client)->create();
    $this->actingAs($this->user);
});

test('classic sites endpoint returns deployment site details from classic central', function () {
    Http::fake([
        '*central/v2/sites*' => Http::sequence()
            ->push([
                'sites' => [[
                    'site_id' => 10,
                    'site_name' => 'Warehouse',
                    'site_address' => [
                        'address' => '123 Main',
                        'city' => 'Denver',
                        'state' => 'CO',
                        'country' => 'US',
                        'zipcode' => '80202',
                    ],
                    'geolocation' => [
                        'latitude' => '39.7392',
                        'longitude' => '-104.9903',
                    ],
                ]],
            ], 200)
            ->push(['sites' => []], 200),
    ]);

    $site = Site::factory()->for($this->client)->create(['name' => 'Warehouse']);
    Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'site_id' => $site->id,
    ]);

    $this->getJson(route('tasks.classic_sites', $this->deployment))
        ->assertOk()
        ->assertJsonPath('sites.0.site_name', 'Warehouse')
        ->assertJsonPath('sites.0.site_id', 10)
        ->assertJsonPath('sites.0.site_address.city', 'Denver');
});

test('create site task stores site details without devices and dispatches jobs', function () {
    Bus::fake();

    $site = Site::factory()->for($this->client)->create(['name' => 'Warehouse']);
    Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'site_id' => $site->id,
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'CREATE_SITE',
        'deployment_time' => 10,
        'sites' => [[
            'site_name' => 'Warehouse',
            'site_address' => [
                'address' => '123 Main',
                'city' => 'Denver',
                'state' => 'CO',
                'country' => 'US',
                'zipcode' => '80202',
            ],
        ]],
    ]);

    $response->assertSessionHasNoErrors();
    $task = $this->deployment->refresh()->tasks()->first();

    expect($task)->not->toBeNull()
        ->and($task->task_type)->toBe('CREATE_SITE')
        ->and($task->devices()->count())->toBe(0)
        ->and($task->site_details)->toHaveCount(1)
        ->and($task->site_details[0]['site_name'])->toBe('Warehouse')
        ->and($task->site_details[0]['status'])->toBe('PENDING');

    Bus::assertBatched(function ($batch): bool {
        return $batch->jobs->count() === 1
            && $batch->jobs->first() instanceof CreateSiteJob;
    });
});

test('update site task requires classic site id', function () {
    $site = Site::factory()->for($this->client)->create(['name' => 'Warehouse']);
    Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'site_id' => $site->id,
    ]);

    $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'UPDATE_SITE',
        'deployment_time' => 10,
        'sites' => [[
            'site_name' => 'Warehouse',
            'site_address' => [
                'address' => '123 Main',
                'city' => 'Denver',
                'state' => 'CO',
                'country' => 'US',
                'zipcode' => '80202',
            ],
        ]],
    ])->assertSessionHasErrors(['sites.0.site_id']);
});

test('update site task stores site id and dispatches update jobs', function () {
    Bus::fake();

    $site = Site::factory()->for($this->client)->create(['name' => 'Warehouse']);
    Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'site_id' => $site->id,
    ]);

    $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'UPDATE_SITE',
        'deployment_time' => 10,
        'sites' => [[
            'site_name' => 'Warehouse',
            'site_id' => 55,
            'site_address' => [
                'address' => '123 Main',
                'city' => 'Denver',
                'state' => 'CO',
                'country' => 'US',
                'zipcode' => '80202',
            ],
        ]],
    ])->assertSessionHasNoErrors();

    $task = $this->deployment->refresh()->tasks()->first();

    expect($task->task_type)->toBe('UPDATE_SITE')
        ->and($task->site_details[0]['site_id'])->toBe(55);

    Bus::assertBatched(function ($batch): bool {
        return $batch->jobs->count() === 1
            && $batch->jobs->first() instanceof UpdateSiteJob;
    });
});

test('site task rejects site names not on deployment', function () {
    $site = Site::factory()->for($this->client)->create(['name' => 'Warehouse']);
    Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'site_id' => $site->id,
    ]);

    $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'CREATE_SITE',
        'deployment_time' => 10,
        'sites' => [[
            'site_name' => 'OtherSite',
            'site_address' => [
                'address' => '123 Main',
                'city' => 'Denver',
                'state' => 'CO',
                'country' => 'US',
                'zipcode' => '80202',
            ],
        ]],
    ])->assertSessionHasErrors(['sites.0.site_name']);
});
