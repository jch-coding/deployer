<?php

use App\Helper\GreenLakeAPIHelper;
use App\Jobs\AddLocationToGreenLakeDevicesJob;
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
    $this->deployment = $this->client->deployments()->create(['name' => 'GreenLake Add Location']);
});

it('forwards task greenlake_location_id into the GreenLake v2beta1 patch payload', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'ADD_LOCATION_TO_GREENLAKE_DEVICES',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 5,
        'greenlake_location_id' => 'loc-nyc',
        'greenlake_location_name' => 'Warehouse NYC',
    ]);

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'LOCSN001',
    ]);
    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    LicensingInventoryDevice::factory()->for($this->client)->create([
        'serial' => $device->serial,
        'greenlake_device_id' => 'gl-loc-1',
        'deployer_device_id' => $device->id,
    ]);

    Http::fake([
        GreenLakeAPIHelper::BASE_URL.'/devices/v2beta1/devices*' => Http::response([
            'transactionId' => 'async-loc-ok',
        ], 202, [
            'Location' => '/devices/v1/async-operations/async-loc-ok',
        ]),
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/async-operations/async-loc-ok' => Http::response([
            'id' => 'async-loc-ok',
            'status' => 'SUCCEEDED',
            'suggestedPollingIntervalSeconds' => 0,
            'result' => [
                'succeededDevices' => [['id' => 'gl-loc-1']],
            ],
        ], 200),
    ]);

    $helper = new GreenLakeAPIHelper($this->client);
    $job = new AddLocationToGreenLakeDevicesJob(
        [[
            'id' => $device->id,
            'serial' => $device->serial,
        ]],
        $task,
        $helper,
    );
    $job->addLocation();

    expect($task->fresh()->status)->toBe('COMPLETED')
        ->and($task->devices()->where('devices.id', $device->id)->first()->pivot->status)->toBe('COMPLETED');

    Http::assertSent(function (Request $request) {
        if ($request->method() !== 'PATCH' || ! str_contains($request->url(), '/devices/v2beta1/devices')) {
            return false;
        }

        $body = $request->data();

        return ($body['location']['id'] ?? null) === 'loc-nyc'
            && str_contains($request->url(), 'id=gl-loc-1');
    });
});

it('fails the task when no greenlake_location_id is provided', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'ADD_LOCATION_TO_GREENLAKE_DEVICES',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 5,
        'greenlake_location_id' => null,
        'greenlake_location_name' => null,
    ]);

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'LOCSN002',
    ]);
    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    LicensingInventoryDevice::factory()->for($this->client)->create([
        'serial' => $device->serial,
        'greenlake_device_id' => 'gl-loc-2',
        'deployer_device_id' => $device->id,
    ]);

    Http::fake();

    $helper = new GreenLakeAPIHelper($this->client);
    $job = new AddLocationToGreenLakeDevicesJob(
        [[
            'id' => $device->id,
            'serial' => $device->serial,
        ]],
        $task,
        $helper,
    );
    $job->addLocation();

    expect($task->fresh()->status)->toBe('FAILED');
    Http::assertNothingSent();
});

it('marks devices failed when GreenLake location update fails', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'ADD_LOCATION_TO_GREENLAKE_DEVICES',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 5,
        'greenlake_location_id' => 'loc-nyc',
        'greenlake_location_name' => 'Warehouse NYC',
    ]);

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'LOCSN003',
    ]);
    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    LicensingInventoryDevice::factory()->for($this->client)->create([
        'serial' => $device->serial,
        'greenlake_device_id' => 'gl-loc-3',
        'deployer_device_id' => $device->id,
    ]);

    Http::fake([
        GreenLakeAPIHelper::BASE_URL.'/devices/v2beta1/devices*' => Http::response([
            'transactionId' => 'async-loc-fail',
        ], 202, [
            'Location' => '/devices/v1/async-operations/async-loc-fail',
        ]),
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/async-operations/async-loc-fail' => Http::response([
            'id' => 'async-loc-fail',
            'status' => 'FAILED',
            'suggestedPollingIntervalSeconds' => 0,
            'result' => [
                'failedDevices' => [['id' => 'gl-loc-3']],
            ],
        ], 200),
    ]);

    $helper = new GreenLakeAPIHelper($this->client);
    $job = new AddLocationToGreenLakeDevicesJob(
        [[
            'id' => $device->id,
            'serial' => $device->serial,
        ]],
        $task,
        $helper,
    );
    $job->addLocation();

    expect($task->fresh()->status)->toBe('FAILED')
        ->and($task->devices()->where('devices.id', $device->id)->first()->pivot->status)->toBe('FAILED');
});
