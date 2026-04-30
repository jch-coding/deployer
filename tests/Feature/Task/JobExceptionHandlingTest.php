<?php

use App\Helper\CentralAPIHelper;
use App\Jobs\AssociateSiteAndNameJob;
use App\Jobs\ConfigureEthernetInterface;
use App\Jobs\UpdateSystemInfo;
use App\Models\Client;
use App\Models\Device;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()
        ->has(Client::factory())
        ->create();
    $this->client = $this->user->clients()->first();
    $this->client->update(['current' => true]);
    $this->deployment = $this->client->deployments()->create(['name' => 'Test Deployment']);
});

test('job logs uncaught exception and marks task failed', function () {
    $task = Task::factory()->recycle($this->deployment)->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 1,
    ]);
    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'scope_id' => 'scope-123',
    ]);
    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    $centralAPIHelper = Mockery::mock(CentralAPIHelper::class);
    $centralAPIHelper->shouldReceive('updateSystemInfo')
        ->once()
        ->andThrow(new RuntimeException('simulated uncaught job failure'));

    $job = new UpdateSystemInfo($device, $task, $centralAPIHelper);
    $job->handle();

    $task->refresh();

    expect($task->status)->toBe('FAILED');
    expect($task->status_log)->toContain('Unhandled exception in App\\Jobs\\UpdateSystemInfo');
    expect($task->status_log)->toContain('simulated uncaught job failure');
});

test('last failed device marks task failed when all devices have failed', function () {
    $task = Task::factory()->recycle($this->deployment)->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 1,
    ]);
    $task->timestamps = false;
    $task->update(['created_at' => now()->subMinutes(10)]);

    $deviceOne = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'scope_id' => 'scope-1',
    ]);
    $deviceTwo = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'scope_id' => 'scope-2',
    ]);
    $task->devices()->attach($deviceOne->id, ['status' => 'FAILED']);
    $task->devices()->attach($deviceTwo->id, ['status' => 'PENDING']);

    $task = $task->fresh();

    $job = new UpdateSystemInfo($deviceTwo, $task, Mockery::mock(CentralAPIHelper::class));
    $job->failed(null);

    expect($task->fresh()->status)->toBe('FAILED');
    expect($task->devices()->where('devices.id', $deviceOne->id)->first()->pivot->status)->toBe('FAILED');
    expect($task->devices()->where('devices.id', $deviceTwo->id)->first()->pivot->status)->toBe('FAILED');
});

test('task jobs use single try for exception paths', function () {
    expect((new UpdateSystemInfo(Device::factory()->make(), Task::factory()->make(), Mockery::mock(CentralAPIHelper::class)))->tries)->toBe(1);
    expect((new ConfigureEthernetInterface(fakeInterface(), Task::factory()->make(), Mockery::mock(CentralAPIHelper::class)))->tries)->toBe(1);
    expect((new AssociateSiteAndNameJob(Device::factory()->make(), Task::factory()->make(), Mockery::mock(CentralAPIHelper::class)))->tries)->toBe(1);
});

function fakeInterface()
{
    $device = Device::factory()->make();

    return new \App\Models\DeviceInterface([
        'device_id' => $device->id,
        'interface' => '1/1/1',
    ]);
}
