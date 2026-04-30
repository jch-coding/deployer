<?php

use App\ClassicBaseUrl;
use App\Helper\CentralAPIHelper;
use App\Jobs\MoveDevicesToGroupJob;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\Task;
use App\Models\User;
use App\TaskType;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

function moveJobFakeGroupsResponse(string $groupName = 'TestGroup', bool $ok = true): Response
{
    $payload = ['data' => [[$groupName, 'Other']]];
    $status = $ok ? 200 : 422;

    return new Response(new Psr7Response($status, ['Content-Type' => 'application/json'], json_encode($payload)));
}

function moveJobHttpJsonResponse(array $json, int $status = 200): Response
{
    return new Response(new Psr7Response($status, ['Content-Type' => 'application/json'], json_encode($json)));
}

it('partitions move requests and updates pivots per chunk, continuing when a chunk fails', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($client)->create();

    $task = Task::factory()->create([
        'deployment_id' => $deployment->id,
        'task_type' => TaskType::MOVE_DEVICE_TO_GROUP->name,
        'status' => 'IN_PROGRESS',
    ]);

    $devices = Device::factory()->count(26)->create([
        'client_id' => $client->id,
        'deployment_id' => $deployment->id,
        'user_id' => $user->id,
    ]);

    $deviceRows = $devices->map(fn (Device $d) => [
        'id' => $d->id,
        'serial' => $d->serial,
        'name' => $d->name,
    ])->all();

    $task->devices()->attach($devices->pluck('id')->all());

    $centralClient = mock(Client::class)->makePartial();
    $centralClient->shouldReceive('handleClassicBearerToken')->andReturn(true);

    $helper = mock(CentralAPIHelper::class, [$centralClient])->makePartial();
    $helper->shouldReceive('classic_get_groups')->once()->andReturn(moveJobFakeGroupsResponse('TestGroup'));

    $helper->shouldReceive('move_devices_to_group')
        ->once()
        ->with('TestGroup', \Mockery::on(fn ($serials) => count($serials) === 25))
        ->andReturn(moveJobHttpJsonResponse(['message' => 'batch failed'], 422));

    $helper->shouldReceive('move_devices_to_group')
        ->once()
        ->with('TestGroup', \Mockery::on(fn ($serials) => count($serials) === 1))
        ->andReturn(moveJobHttpJsonResponse([], 200));

    $job = new MoveDevicesToGroupJob($deviceRows, 'TestGroup', $task->fresh(), $helper);
    $job->handle();

    $failed = $task->devices()->wherePivot('status', 'FAILED')->count();
    $completed = $task->devices()->wherePivot('status', 'COMPLETED')->count();

    expect($failed)->toBe(25)
        ->and($completed)->toBe(1)
        ->and($task->fresh()->status)->toBe('IN_PROGRESS');
});

it('sets task to completed when every partition succeeds', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($client)->create();

    $task = Task::factory()->create([
        'deployment_id' => $deployment->id,
        'task_type' => TaskType::MOVE_DEVICE_TO_GROUP->name,
        'status' => 'IN_PROGRESS',
    ]);

    $devices = Device::factory()->count(2)->create([
        'client_id' => $client->id,
        'deployment_id' => $deployment->id,
        'user_id' => $user->id,
    ]);

    $deviceRows = $devices->map(fn (Device $d) => [
        'id' => $d->id,
        'serial' => $d->serial,
        'name' => $d->name,
    ])->all();

    $task->devices()->attach($devices->pluck('id')->all());

    $centralClient = mock(Client::class)->makePartial();
    $centralClient->shouldReceive('handleClassicBearerToken')->andReturn(true);

    $helper = mock(CentralAPIHelper::class, [$centralClient])->makePartial();
    $helper->shouldReceive('classic_get_groups')->once()->andReturn(moveJobFakeGroupsResponse('TestGroup'));

    $helper->shouldReceive('move_devices_to_group')
        ->once()
        ->with('TestGroup', \Mockery::on(fn ($serials) => count($serials) === 2))
        ->andReturn(moveJobHttpJsonResponse([], 200));

    $job = new MoveDevicesToGroupJob($deviceRows, 'TestGroup', $task->fresh(), $helper);
    $job->handle();

    expect($task->fresh()->status)->toBe('COMPLETED')
        ->and($task->devices()->wherePivot('status', 'COMPLETED')->count())->toBe(2);
});

it('fails the task when every partition fails', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($client)->create();

    $task = Task::factory()->create([
        'deployment_id' => $deployment->id,
        'task_type' => TaskType::MOVE_DEVICE_TO_GROUP->name,
        'status' => 'IN_PROGRESS',
    ]);

    $devices = Device::factory()->count(3)->create([
        'client_id' => $client->id,
        'deployment_id' => $deployment->id,
        'user_id' => $user->id,
    ]);

    $deviceRows = $devices->map(fn (Device $d) => [
        'id' => $d->id,
        'serial' => $d->serial,
        'name' => $d->name,
    ])->all();

    $task->devices()->attach($devices->pluck('id')->all());

    $centralClient = mock(Client::class)->makePartial();
    $centralClient->shouldReceive('handleClassicBearerToken')->andReturn(true);

    $helper = mock(CentralAPIHelper::class, [$centralClient])->makePartial();
    $helper->shouldReceive('classic_get_groups')->once()->andReturn(moveJobFakeGroupsResponse('TestGroup'));

    $helper->shouldReceive('move_devices_to_group')
        ->once()
        ->andReturn(moveJobHttpJsonResponse(['message' => 'error'], 500));

    $job = new MoveDevicesToGroupJob($deviceRows, 'TestGroup', $task->fresh(), $helper);
    $job->handle();

    expect($task->fresh()->status)->toBe('FAILED')
        ->and($task->devices()->wherePivot('status', 'FAILED')->count())->toBe(3);
});

it('treats token failure array response from move_devices_to_group as a failed chunk', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($client)->create();

    $task = Task::factory()->create([
        'deployment_id' => $deployment->id,
        'task_type' => TaskType::MOVE_DEVICE_TO_GROUP->name,
        'status' => 'IN_PROGRESS',
    ]);

    $device = Device::factory()->create([
        'client_id' => $client->id,
        'deployment_id' => $deployment->id,
        'user_id' => $user->id,
    ]);

    $task->devices()->attach([$device->id]);

    $centralClient = mock(Client::class)->makePartial();
    $centralClient->shouldReceive('handleClassicBearerToken')->andReturn(true);

    $helper = mock(CentralAPIHelper::class, [$centralClient])->makePartial();
    $helper->shouldReceive('classic_get_groups')->once()->andReturn(moveJobFakeGroupsResponse('TestGroup'));

    $helper->shouldReceive('move_devices_to_group')
        ->once()
        ->andReturn(['error' => 'failed to get access token from central.']);

    $deviceRows = [['id' => $device->id, 'serial' => $device->serial, 'name' => $device->name]];

    $job = new MoveDevicesToGroupJob($deviceRows, 'TestGroup', $task->fresh(), $helper);
    $job->handle();

    expect($task->devices()->wherePivot('status', 'FAILED')->count())->toBe(1)
        ->and($task->fresh()->status)->toBe('FAILED');
});

it('batches move requests using Http fake for classic API calls', function () {
    Http::fake([
        '*configuration/v2/groups*' => Http::response(['data' => [['HttpFakeGroup', 'Other']]], 200),
        '*configuration/v1/devices/move' => Http::sequence()
            ->push(['message' => 'first batch failed'], 422)
            ->push([], 200),
    ]);

    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create([
        'classic_base_url' => ClassicBaseUrl::US1,
        'classic_client_id' => 'classic-id',
        'classic_client_secret' => 'classic-secret',
        'classic_username' => 'user',
        'classic_password' => 'pass',
        'classic_refresh_token' => 'refresh',
        'classic_expires_in' => now()->addHour(),
        'classic_access_token' => 'access-token',
    ]);

    $deployment = Deployment::factory()->for($client)->create();

    $task = Task::factory()->create([
        'deployment_id' => $deployment->id,
        'task_type' => TaskType::MOVE_DEVICE_TO_GROUP->name,
        'status' => 'IN_PROGRESS',
    ]);

    $devices = Device::factory()->count(26)->create([
        'client_id' => $client->id,
        'deployment_id' => $deployment->id,
        'user_id' => $user->id,
    ]);

    $deviceRows = $devices->map(fn (Device $d) => [
        'id' => $d->id,
        'serial' => $d->serial,
        'name' => $d->name,
    ])->all();

    $task->devices()->attach($devices->pluck('id')->all());

    $helper = new CentralAPIHelper($client->fresh());
    $job = new MoveDevicesToGroupJob($deviceRows, 'HttpFakeGroup', $task->fresh(), $helper);
    $job->handle();

    expect($task->devices()->wherePivot('status', 'FAILED')->count())->toBe(25)
        ->and($task->devices()->wherePivot('status', 'COMPLETED')->count())->toBe(1)
        ->and($task->fresh()->status)->toBe('IN_PROGRESS');

    Http::assertSentCount(3);
});
