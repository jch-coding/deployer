<?php

use App\Helper\CentralAPIHelper;
use App\Jobs\UpdateSystemInfo;
use App\Models\Client;
use App\Models\Device;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Client\Response;

beforeEach(function () {
    $this->user = User::factory()->has(Client::factory())->create();
    $this->client = $this->user->clients()->first();
    $this->client->update(['current' => true]);
    $this->deployment = $this->client->deployments()->create(['name' => 'Name Task Deployment']);
});

it('queries Central for the device scope-id if the scope-id is missing', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'IN_PROGRESS',
    ]);
    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'scope_id' => null,
    ]);
    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    $centralApi = Mockery::mock(CentralAPIHelper::class);
    $successResponse = Mockery::mock(Response::class);
    $successResponse->shouldReceive('successful')->andReturn(true);
    $centralApi->shouldReceive('getScopeIdFromCentral')->once()->with($device)->andReturn([['scopeId' => '1234567890']]);
    $centralApi->shouldReceive('updateSystemInfo')->once()->with($device)->andReturn($successResponse);

    $job = new UpdateSystemInfo($device, $task, $centralApi);
    $job->handle();

    expect($device->fresh()->scope_id)->toBe('1234567890');
    expect((string) $task->fresh()->status_log)->toContain("System info for {$device->name} updated successfully");
    expect($task->fresh()->status)->toBe('COMPLETED');
});

it('does not fetch scope-id when the device already has one', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'IN_PROGRESS',
    ]);
    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'scope_id' => 'existing-scope-id',
    ]);
    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    $centralApi = Mockery::mock(CentralAPIHelper::class);
    $successResponse = Mockery::mock(Response::class);
    $successResponse->shouldReceive('successful')->andReturn(true);
    $centralApi->shouldReceive('getScopeIdFromCentral')->never();
    $centralApi->shouldReceive('updateSystemInfo')->once()->with($device)->andReturn($successResponse);

    $job = new UpdateSystemInfo($device, $task, $centralApi);
    $job->handle();

    expect($device->fresh()->scope_id)->toBe('existing-scope-id');
    expect((string) $task->fresh()->status_log)->toContain("System info for {$device->name} updated successfully");
    expect($task->fresh()->status)->toBe('COMPLETED');
});

it('keeps device pivot pending when system info update fails', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'IN_PROGRESS',
    ]);
    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'scope_id' => 'scope-id',
    ]);
    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    $centralApi = Mockery::mock(CentralAPIHelper::class);
    $failureResponse = Mockery::mock(Response::class);
    $failureResponse->shouldReceive('successful')->andReturn(false);
    $failureResponse->shouldReceive('json')->with('message')->andReturn('boom');
    $failureResponse->shouldReceive('body')->andReturn('');
    $postFailureResponse = Mockery::mock(Response::class);
    $postFailureResponse->shouldReceive('successful')->andReturn(false);
    $centralApi->shouldReceive('getScopeIdFromCentral')->never();
    $centralApi->shouldReceive('updateSystemInfo')->once()->with($device)->andReturn($failureResponse);
    $centralApi->shouldReceive('postSystemInfo')->once()->with($device)->andReturn($postFailureResponse);

    $job = new UpdateSystemInfo($device, $task, $centralApi);
    $job->handle();

    expect($task->devices()->find($device->id)->pivot->status)->toBe('PENDING');
});
