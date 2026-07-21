<?php

use App\Helper\GreenLakeAPIHelper;
use App\Jobs\AddTagsToGreenLakeDevicesJob;
use App\Models\Client;
use App\Models\Device;
use App\Models\LicensingInventoryDevice;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->has(Client::factory())->create();
    $this->client = $this->user->clients()->first();
    $this->client->update([
        'current' => true,
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
    ]);
    $this->deployment = $this->client->deployments()->create(['name' => 'GreenLake Add Tags']);
});

it('forwards task greenlake_tags into the GreenLake v2beta1 patch payload', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'ADD_TAGS_TO_GREENLAKE_DEVICES',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 5,
        'greenlake_tags' => [
            'Environment' => 'prod',
            'Site' => '',
        ],
    ]);

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'TAGSN001',
    ]);
    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    LicensingInventoryDevice::factory()->for($this->client)->create([
        'serial' => $device->serial,
        'greenlake_device_id' => 'gl-tag-1',
        'deployer_device_id' => $device->id,
    ]);

    Http::fake([
        GreenLakeAPIHelper::BASE_URL.'/devices/v2beta1/devices*' => Http::response([
            'transactionId' => 'async-tags-ok',
        ], 202, [
            'Location' => '/devices/v1/async-operations/async-tags-ok',
        ]),
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/async-operations/async-tags-ok' => Http::response([
            'id' => 'async-tags-ok',
            'status' => 'SUCCEEDED',
            'suggestedPollingIntervalSeconds' => 0,
            'result' => [
                'succeededDevices' => [['id' => 'gl-tag-1']],
            ],
        ], 200),
    ]);

    $helper = new GreenLakeAPIHelper($this->client);
    $job = new AddTagsToGreenLakeDevicesJob(
        [[
            'id' => $device->id,
            'serial' => $device->serial,
        ]],
        $task,
        $helper,
    );
    $job->addTags();

    expect($task->fresh()->status)->toBe('COMPLETED')
        ->and($task->devices()->where('devices.id', $device->id)->first()->pivot->status)->toBe('COMPLETED');

    Http::assertSent(function (Request $request) {
        if ($request->method() !== 'PATCH' || ! str_contains($request->url(), '/devices/v2beta1/devices')) {
            return false;
        }

        $body = $request->data();

        return ($body['tags']['Environment'] ?? null) === 'prod'
            && ($body['tags']['Site'] ?? null) === ''
            && str_contains($request->url(), 'id=gl-tag-1');
    });
});

it('marks devices failed when GreenLake tag update fails', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'ADD_TAGS_TO_GREENLAKE_DEVICES',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 5,
        'greenlake_tags' => ['Environment' => 'lab'],
    ]);

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'TAGSN002',
    ]);
    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    LicensingInventoryDevice::factory()->for($this->client)->create([
        'serial' => $device->serial,
        'greenlake_device_id' => 'gl-tag-2',
        'deployer_device_id' => $device->id,
    ]);

    Http::fake([
        GreenLakeAPIHelper::BASE_URL.'/devices/v2beta1/devices*' => Http::response([
            'transactionId' => 'async-tags-fail',
        ], 202, [
            'Location' => '/devices/v1/async-operations/async-tags-fail',
        ]),
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/async-operations/async-tags-fail' => Http::response([
            'id' => 'async-tags-fail',
            'status' => 'FAILED',
            'suggestedPollingIntervalSeconds' => 0,
            'result' => [
                'failedDevices' => [['id' => 'gl-tag-2']],
            ],
        ], 200),
    ]);

    $helper = new GreenLakeAPIHelper($this->client);
    $job = new AddTagsToGreenLakeDevicesJob(
        [[
            'id' => $device->id,
            'serial' => $device->serial,
        ]],
        $task,
        $helper,
    );
    $job->addTags();

    expect($task->fresh()->status)->toBe('FAILED')
        ->and($task->devices()->where('devices.id', $device->id)->first()->pivot->status)->toBe('FAILED');
});
