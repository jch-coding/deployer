<?php

use App\Helper\GreenLakeAPIHelper;
use App\Jobs\AssignServiceToGreenLakeDevicesJob;
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
    $this->deployment = $this->client->deployments()->create(['name' => 'GreenLake Assign Service']);
});

it('forwards task greenlake application id and region into the GreenLake v2beta1 patch payload', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'ASSIGN_SERVICE_TO_GREENLAKE_DEVICES',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 5,
        'greenlake_application_id' => 'app-central',
        'greenlake_application_region' => 'us-west',
        'greenlake_application_name' => 'Aruba Central (us-west)',
    ]);

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'SVCSN001',
    ]);
    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    LicensingInventoryDevice::factory()->for($this->client)->create([
        'serial' => $device->serial,
        'greenlake_device_id' => 'gl-svc-1',
        'deployer_device_id' => $device->id,
    ]);

    Http::fake([
        GreenLakeAPIHelper::BASE_URL.'/devices/v2beta1/devices*' => Http::response([
            'transactionId' => 'async-svc-ok',
        ], 202, [
            'Location' => '/devices/v1/async-operations/async-svc-ok',
        ]),
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/async-operations/async-svc-ok' => Http::response([
            'id' => 'async-svc-ok',
            'status' => 'SUCCEEDED',
            'suggestedPollingIntervalSeconds' => 0,
            'result' => [
                'succeededDevices' => [['id' => 'gl-svc-1']],
            ],
        ], 200),
    ]);

    $helper = new GreenLakeAPIHelper($this->client);
    $job = new AssignServiceToGreenLakeDevicesJob(
        [[
            'id' => $device->id,
            'serial' => $device->serial,
        ]],
        $task,
        $helper,
    );
    $job->assignService();

    expect($task->fresh()->status)->toBe('COMPLETED')
        ->and($task->devices()->where('devices.id', $device->id)->first()->pivot->status)->toBe('COMPLETED');

    Http::assertSent(function (Request $request) {
        if ($request->method() !== 'PATCH' || ! str_contains($request->url(), '/devices/v2beta1/devices')) {
            return false;
        }

        $body = $request->data();

        return ($body['application']['id'] ?? null) === 'app-central'
            && ($body['region'] ?? null) === 'us-west'
            && str_contains($request->url(), 'id=gl-svc-1');
    });
});

it('fails the task when no greenlake application fields are provided', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'ASSIGN_SERVICE_TO_GREENLAKE_DEVICES',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 5,
        'greenlake_application_id' => null,
        'greenlake_application_region' => null,
        'greenlake_application_name' => null,
    ]);

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'SVCSN002',
    ]);
    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    LicensingInventoryDevice::factory()->for($this->client)->create([
        'serial' => $device->serial,
        'greenlake_device_id' => 'gl-svc-2',
        'deployer_device_id' => $device->id,
    ]);

    Http::fake();

    $helper = new GreenLakeAPIHelper($this->client);
    $job = new AssignServiceToGreenLakeDevicesJob(
        [[
            'id' => $device->id,
            'serial' => $device->serial,
        ]],
        $task,
        $helper,
    );
    $job->assignService();

    expect($task->fresh()->status)->toBe('FAILED');
    Http::assertNothingSent();
});

it('marks devices failed when GreenLake service assignment fails', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'ASSIGN_SERVICE_TO_GREENLAKE_DEVICES',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 5,
        'greenlake_application_id' => 'app-central',
        'greenlake_application_region' => 'us-west',
        'greenlake_application_name' => 'Aruba Central (us-west)',
    ]);

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'SVCSN003',
    ]);
    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    LicensingInventoryDevice::factory()->for($this->client)->create([
        'serial' => $device->serial,
        'greenlake_device_id' => 'gl-svc-3',
        'deployer_device_id' => $device->id,
    ]);

    Http::fake([
        GreenLakeAPIHelper::BASE_URL.'/devices/v2beta1/devices*' => Http::response([
            'transactionId' => 'async-svc-fail',
        ], 202, [
            'Location' => '/devices/v1/async-operations/async-svc-fail',
        ]),
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/async-operations/async-svc-fail' => Http::response([
            'id' => 'async-svc-fail',
            'status' => 'FAILED',
            'suggestedPollingIntervalSeconds' => 0,
            'result' => [
                'failedDevices' => [['id' => 'gl-svc-3']],
            ],
        ], 200),
    ]);

    $helper = new GreenLakeAPIHelper($this->client);
    $job = new AssignServiceToGreenLakeDevicesJob(
        [[
            'id' => $device->id,
            'serial' => $device->serial,
        ]],
        $task,
        $helper,
    );
    $job->assignService();

    expect($task->fresh()->status)->toBe('FAILED')
        ->and($task->devices()->where('devices.id', $device->id)->first()->pivot->status)->toBe('FAILED');
});
