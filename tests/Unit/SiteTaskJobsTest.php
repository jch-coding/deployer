<?php

use App\ClassicBaseUrl;
use App\Helper\CentralAPIHelper;
use App\Jobs\CreateSiteJob;
use App\Jobs\UpdateSiteJob;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\Task;
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
        'expires_at' => now()->addHour(),
        'current' => true,
    ]);
    $this->deployment = Deployment::factory()->for($this->client)->create();
    $this->helper = new CentralAPIHelper($this->client);
});

test('create site job persists classic id and completes task', function () {
    Http::fake([
        '*central/v2/sites' => Http::response(['site_id' => 9001], 201),
    ]);

    $site = Site::factory()->for($this->client)->create(['name' => 'Warehouse', 'classic_id' => null]);
    $siteDetail = [
        'site_name' => 'Warehouse',
        'site_address' => [
            'address' => '123 Main',
            'city' => 'Denver',
            'state' => 'CO',
            'country' => 'US',
            'zipcode' => '80202',
        ],
        'status' => 'PENDING',
    ];

    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'CREATE_SITE',
        'site_details' => [$siteDetail],
        'status' => 'IN_PROGRESS',
    ]);

    (new CreateSiteJob($siteDetail, $task, $this->helper))->handle();

    expect($site->fresh()->classic_id)->toBe(9001)
        ->and($task->fresh()->status)->toBe('COMPLETED')
        ->and($task->fresh()->site_details[0]['status'])->toBe('COMPLETED');
});

test('update site job patches full body and completes task', function () {
    Http::fake([
        '*central/v2/sites/55' => Http::response(['site_id' => 55], 200),
    ]);

    $siteDetail = [
        'site_name' => 'Warehouse',
        'site_id' => 55,
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
        'status' => 'PENDING',
    ];

    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'UPDATE_SITE',
        'site_details' => [$siteDetail],
        'status' => 'IN_PROGRESS',
    ]);

    (new UpdateSiteJob($siteDetail, $task, $this->helper))->handle();

    expect($task->fresh()->status)->toBe('COMPLETED')
        ->and($task->fresh()->site_details[0]['status'])->toBe('COMPLETED');

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), 'central/v2/sites/55') || $request->method() !== 'PATCH') {
            return false;
        }

        $body = $request->data();

        return ($body['site_name'] ?? null) === 'Warehouse'
            && ($body['site_address']['address'] ?? null) === '123 Main'
            && ($body['geolocation']['latitude'] ?? null) === '39.7392';
    });
});

test('update site job marks task failed when classic patch fails', function () {
    Http::fake([
        '*central/v2/sites/55' => Http::response(['description' => 'nope'], 400),
    ]);

    $siteDetail = [
        'site_name' => 'Warehouse',
        'site_id' => 55,
        'site_address' => [
            'address' => '123 Main',
            'city' => 'Denver',
            'state' => 'CO',
            'country' => 'US',
            'zipcode' => '80202',
        ],
        'status' => 'PENDING',
    ];

    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'UPDATE_SITE',
        'site_details' => [$siteDetail],
        'status' => 'IN_PROGRESS',
    ]);

    (new UpdateSiteJob($siteDetail, $task, $this->helper))->handle();

    expect($task->fresh()->status)->toBe('FAILED')
        ->and($task->fresh()->site_details[0]['status'])->toBe('FAILED');
});
