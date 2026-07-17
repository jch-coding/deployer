<?php

use App\Helper\GreenLakeAPIHelper;
use App\Jobs\AddDevicesToGreenLakeInventoryJob;
use App\Models\Client;
use App\Models\Device;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->has(Client::factory())->create();
    $this->client = $this->user->clients()->first();
    $this->client->update([
        'current' => true,
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
    ]);
    $this->deployment = $this->client->deployments()->create(['name' => 'GreenLake Add Devices']);
});

it('marks devices completed when GreenLake async add succeeds', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'ADD_DEVICES_TO_GREENLAKE_INVENTORY',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 5,
    ]);

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'ADDSN001',
        'mac_address' => 'aa:bb:cc:dd:ee:01',
    ]);
    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    Http::fake([
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/devices' => Http::response([
            'transactionId' => 'async-ok',
        ], 202),
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/async-operations/async-ok' => Http::response([
            'id' => 'async-ok',
            'status' => 'SUCCEEDED',
            'suggestedPollingIntervalSeconds' => 0,
            'result' => [
                'succeeded' => [['serialNumber' => 'ADDSN001']],
            ],
        ], 200),
    ]);

    $helper = new GreenLakeAPIHelper($this->client);
    $job = new AddDevicesToGreenLakeInventoryJob(
        [[
            'id' => $device->id,
            'serial' => $device->serial,
            'mac_address' => $device->mac_address,
        ]],
        $task,
        $helper,
    );
    $job->addDevices();

    expect($task->fresh()->status)->toBe('COMPLETED')
        ->and($task->devices()->where('devices.id', $device->id)->first()->pivot->status)->toBe('COMPLETED');
});

it('marks devices failed when GreenLake async add fails', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'ADD_DEVICES_TO_GREENLAKE_INVENTORY',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 5,
    ]);

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'ADDSN002',
        'mac_address' => 'aa:bb:cc:dd:ee:02',
    ]);
    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    Http::fake([
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/devices' => Http::response([
            'transactionId' => 'async-fail',
        ], 202),
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/async-operations/async-fail' => Http::response([
            'id' => 'async-fail',
            'status' => 'FAILED',
            'suggestedPollingIntervalSeconds' => 0,
            'result' => [
                'failed' => [['serialNumber' => 'ADDSN002']],
            ],
        ], 200),
    ]);

    $helper = new GreenLakeAPIHelper($this->client);
    $job = new AddDevicesToGreenLakeInventoryJob(
        [[
            'id' => $device->id,
            'serial' => $device->serial,
            'mac_address' => $device->mac_address,
        ]],
        $task,
        $helper,
    );
    $job->addDevices();

    expect($task->devices()->where('devices.id', $device->id)->first()->pivot->status)->toBe('FAILED')
        ->and($task->fresh()->status)->toBe('FAILED');
});
