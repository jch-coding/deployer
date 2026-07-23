<?php

use App\Helper\CentralAPIHelper;
use App\Http\Controllers\TaskController;
use App\Jobs\AssignDeviceFunctionJob;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\Task;
use App\Models\User;
use App\TaskType;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Bus;

function assignDeviceFunctionJobJsonResponse(array $json, int $status = 200): Response
{
    return new Response(new Psr7Response($status, ['Content-Type' => 'application/json'], json_encode($json)));
}

function assignDeviceFunctionJobMakeTaskAndDevices(
    User $user,
    Client $client,
    Deployment $deployment,
    int $deviceCount = 1,
    string $deviceFunction = 'ACCESS_SWITCH',
): array {
    $task = Task::factory()->create([
        'deployment_id' => $deployment->id,
        'task_type' => TaskType::ASSIGN_DEVICE_FUNCTION->name,
        'status' => 'IN_PROGRESS',
    ]);

    $devices = Device::factory()->count($deviceCount)->create([
        'client_id' => $client->id,
        'deployment_id' => $deployment->id,
        'user_id' => $user->id,
        'device_function' => $deviceFunction,
    ]);

    $deviceRows = $devices->map(fn (Device $d) => [
        'id' => $d->id,
        'serial' => $d->serial,
        'name' => $d->name,
    ])->all();

    $task->devices()->attach($devices->pluck('id')->all(), ['status' => 'PENDING']);

    return [$task, $deviceRows, $devices];
}

it('releases the job without marking devices failed when assign POST fails', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($client)->create();

    [$task, $deviceRows, $devices] = assignDeviceFunctionJobMakeTaskAndDevices($user, $client, $deployment);

    $centralClient = mock(Client::class)->makePartial();
    $helper = mock(CentralAPIHelper::class, [$centralClient])->makePartial();
    $helper->shouldReceive('assignDeviceFunction')
        ->once()
        ->with(\Mockery::on(fn ($serials) => count($serials) === 1), 'ACCESS_SWITCH')
        ->andReturn(assignDeviceFunctionJobJsonResponse(['message' => 'rate limited'], 429));

    $job = new AssignDeviceFunctionJob([$deviceRows], 'ACCESS_SWITCH', $task->fresh(), $helper);
    $job->withFakeQueueInteractions();
    $job->handle();

    $job->assertReleased(AssignDeviceFunctionJob::BATCH_DELAY_SECONDS);

    expect($task->devices()->wherePivot('status', 'FAILED')->count())->toBe(0)
        ->and($task->devices()->wherePivot('status', 'PENDING')->count())->toBe($devices->count())
        ->and($task->fresh()->status)->toBe('IN_PROGRESS')
        ->and($task->fresh()->status_log)->toContain('Will retry');
});

it('marks devices completed when assign POST succeeds', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($client)->create();

    [$task, $deviceRows] = assignDeviceFunctionJobMakeTaskAndDevices($user, $client, $deployment, 2);

    $centralClient = mock(Client::class)->makePartial();
    $helper = mock(CentralAPIHelper::class, [$centralClient])->makePartial();
    $helper->shouldReceive('assignDeviceFunction')
        ->once()
        ->with(\Mockery::on(fn ($serials) => count($serials) === 2), 'ACCESS_SWITCH')
        ->andReturn(assignDeviceFunctionJobJsonResponse([], 200));

    $job = new AssignDeviceFunctionJob([$deviceRows], 'ACCESS_SWITCH', $task->fresh(), $helper);
    $job->withFakeQueueInteractions();
    $job->handle();

    $job->assertNotReleased();

    expect($task->fresh()->status)->toBe('COMPLETED')
        ->and($task->devices()->wherePivot('status', 'COMPLETED')->count())->toBe(2);
});

it('assigns each chunk sequentially', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($client)->create();

    [$task, $deviceRows] = assignDeviceFunctionJobMakeTaskAndDevices($user, $client, $deployment, 2);
    $chunkOne = [$deviceRows[0]];
    $chunkTwo = [$deviceRows[1]];

    $centralClient = mock(Client::class)->makePartial();
    $helper = mock(CentralAPIHelper::class, [$centralClient])->makePartial();
    $helper->shouldReceive('assignDeviceFunction')
        ->twice()
        ->ordered()
        ->with(\Mockery::type('array'), 'ACCESS_SWITCH')
        ->andReturn(assignDeviceFunctionJobJsonResponse([], 200));

    $job = new AssignDeviceFunctionJob([$chunkOne, $chunkTwo], 'ACCESS_SWITCH', $task->fresh(), $helper);
    $job->withFakeQueueInteractions();
    $job->handle();

    $job->assertNotReleased();

    expect($task->fresh()->status)->toBe('COMPLETED')
        ->and($task->devices()->wherePivot('status', 'COMPLETED')->count())->toBe(2);
});

it('skips chunks whose devices are already completed on retry', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($client)->create();

    [$task, $deviceRows, $devices] = assignDeviceFunctionJobMakeTaskAndDevices($user, $client, $deployment, 2);
    $chunkOne = [$deviceRows[0]];
    $chunkTwo = [$deviceRows[1]];

    $task->devices()->updateExistingPivot($devices->first()->id, ['status' => 'COMPLETED']);

    $centralClient = mock(Client::class)->makePartial();
    $helper = mock(CentralAPIHelper::class, [$centralClient])->makePartial();
    $helper->shouldReceive('assignDeviceFunction')
        ->once()
        ->with(\Mockery::on(fn ($serials) => $serials === [$deviceRows[1]['serial']]), 'ACCESS_SWITCH')
        ->andReturn(assignDeviceFunctionJobJsonResponse([], 200));

    $job = new AssignDeviceFunctionJob([$chunkOne, $chunkTwo], 'ACCESS_SWITCH', $task->fresh(), $helper);
    $job->withFakeQueueInteractions();
    $job->handle();

    $job->assertNotReleased();

    expect($task->devices()->wherePivot('status', 'COMPLETED')->count())->toBe(2);
});

it('marks all devices failed when the job fails', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($client)->create();

    [$task, $deviceRows, $devices] = assignDeviceFunctionJobMakeTaskAndDevices($user, $client, $deployment, 2);
    $chunkWithPreservedKeys = [
        25 => $deviceRows[0],
        26 => $deviceRows[1],
    ];

    $centralClient = mock(Client::class)->makePartial();
    $helper = mock(CentralAPIHelper::class, [$centralClient])->makePartial();

    $job = new AssignDeviceFunctionJob([$chunkWithPreservedKeys], 'ACCESS_SWITCH', $task->fresh(), $helper);
    $job->failed(new RuntimeException('worker timeout'));

    expect($task->fresh()->status)->toBe('FAILED')
        ->and($task->devices()->wherePivot('status', 'FAILED')->count())->toBe($devices->count());
});

it('dispatchJob creates one assign job per device function with all chunks', function () {
    Bus::fake();

    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($client)->create();
    $devices = Device::factory()->count(26)->create([
        'client_id' => $client->id,
        'deployment_id' => $deployment->id,
        'user_id' => $user->id,
        'device_function' => 'ACCESS_SWITCH',
    ]);

    $task = Task::factory()->create([
        'deployment_id' => $deployment->id,
        'task_type' => TaskType::ASSIGN_DEVICE_FUNCTION->name,
        'status' => 'IN_PROGRESS',
    ]);
    $task->devices()->attach($devices->pluck('id')->all(), ['status' => 'PENDING']);

    (new TaskController())->dispatchJob($task->fresh());

    Bus::assertBatched(function ($batch): bool {
        if ($batch->jobs->count() !== 1) {
            return false;
        }

        $job = $batch->jobs->first();

        return $job instanceof AssignDeviceFunctionJob
            && $job->device_function === 'ACCESS_SWITCH'
            && count($job->device_chunks) === 2
            && count($job->device_chunks[0]) === 25
            && count($job->device_chunks[1]) === 1;
    });
});
