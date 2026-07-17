<?php

use App\Helper\CentralAPIHelper;
use App\Http\Controllers\TaskController;
use App\Jobs\PreprovisionDevicesToGroupJob;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\Task;
use App\Models\User;
use App\TaskType;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Bus;

function preprovisionJobJsonResponse(array $json, int $status = 200): Response
{
    return new Response(new Psr7Response($status, ['Content-Type' => 'application/json'], json_encode($json)));
}

function preprovisionJobCollectGroupNamesResult(string $groupName = 'TestGroup'): array
{
    return ['names' => [$groupName, 'Other']];
}

function preprovisionJobMakeTaskAndDevices(
    User $user,
    Client $client,
    Deployment $deployment,
    int $deviceCount = 1,
    string $groupName = 'TestGroup',
): array {
    $task = Task::factory()->create([
        'deployment_id' => $deployment->id,
        'task_type' => TaskType::PREPROVISION_DEVICE_TO_GROUP->name,
        'status' => 'IN_PROGRESS',
    ]);

    $devices = Device::factory()->count($deviceCount)->create([
        'client_id' => $client->id,
        'deployment_id' => $deployment->id,
        'user_id' => $user->id,
        'group' => $groupName,
    ]);

    $deviceRows = $devices->map(fn (Device $d) => [
        'id' => $d->id,
        'serial' => $d->serial,
        'name' => $d->name,
    ])->all();

    $task->devices()->attach($devices->pluck('id')->all(), ['status' => 'PENDING']);

    return [$task, $deviceRows, $devices];
}

it('releases the job without marking devices failed when preprovision POST fails', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($client)->create();

    [$task, $deviceRows, $devices] = preprovisionJobMakeTaskAndDevices($user, $client, $deployment);

    $centralClient = mock(Client::class)->makePartial();
    $helper = mock(CentralAPIHelper::class, [$centralClient])->makePartial();
    $helper->shouldReceive('classic_collect_all_group_names')->once()->andReturn(preprovisionJobCollectGroupNamesResult('TestGroup'));
    $helper->shouldReceive('preprovision_devices_to_group')
        ->once()
        ->with('TestGroup', \Mockery::on(fn ($serials) => count($serials) === 1))
        ->andReturn(preprovisionJobJsonResponse(['message' => 'rate limited'], 429));

    $job = new PreprovisionDevicesToGroupJob([$deviceRows], 'TestGroup', $task->fresh(), $helper);
    $job->withFakeQueueInteractions();
    $job->handle();

    $job->assertReleased(PreprovisionDevicesToGroupJob::BATCH_DELAY_SECONDS);

    expect($task->devices()->wherePivot('status', 'FAILED')->count())->toBe(0)
        ->and($task->devices()->wherePivot('status', 'PENDING')->count())->toBe($devices->count())
        ->and($task->fresh()->status)->toBe('IN_PROGRESS')
        ->and($task->fresh()->status_log)->toContain('Will retry');
});

it('marks devices completed when preprovision POST succeeds', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($client)->create();

    [$task, $deviceRows] = preprovisionJobMakeTaskAndDevices($user, $client, $deployment, 2);

    $centralClient = mock(Client::class)->makePartial();
    $helper = mock(CentralAPIHelper::class, [$centralClient])->makePartial();
    $helper->shouldReceive('classic_collect_all_group_names')->once()->andReturn(preprovisionJobCollectGroupNamesResult('TestGroup'));
    $helper->shouldReceive('preprovision_devices_to_group')
        ->once()
        ->with('TestGroup', \Mockery::on(fn ($serials) => count($serials) === 2))
        ->andReturn(preprovisionJobJsonResponse([], 201));

    $job = new PreprovisionDevicesToGroupJob([$deviceRows], 'TestGroup', $task->fresh(), $helper);
    $job->withFakeQueueInteractions();
    $job->handle();

    $job->assertNotReleased();

    expect($task->fresh()->status)->toBe('COMPLETED')
        ->and($task->devices()->wherePivot('status', 'COMPLETED')->count())->toBe(2);
});

it('marks all devices failed when group is not found in Central', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($client)->create();

    [$task, $deviceRows, $devices] = preprovisionJobMakeTaskAndDevices($user, $client, $deployment, 2, 'MissingGroup');

    $centralClient = mock(Client::class)->makePartial();
    $helper = mock(CentralAPIHelper::class, [$centralClient])->makePartial();
    $helper->shouldReceive('classic_collect_all_group_names')->once()->andReturn(preprovisionJobCollectGroupNamesResult('TestGroup'));
    $helper->shouldReceive('preprovision_devices_to_group')->never();

    $job = new PreprovisionDevicesToGroupJob([$deviceRows], 'MissingGroup', $task->fresh(), $helper);
    $job->withFakeQueueInteractions();
    $job->handle();

    $job->assertNotReleased();

    expect($task->fresh()->status)->toBe('FAILED')
        ->and($task->devices()->wherePivot('status', 'FAILED')->count())->toBe($devices->count());
});

it('preprovisions each chunk sequentially', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($client)->create();

    [$task, $deviceRows] = preprovisionJobMakeTaskAndDevices($user, $client, $deployment, 2);
    $chunkOne = [$deviceRows[0]];
    $chunkTwo = [$deviceRows[1]];

    $centralClient = mock(Client::class)->makePartial();
    $helper = mock(CentralAPIHelper::class, [$centralClient])->makePartial();
    $helper->shouldReceive('classic_collect_all_group_names')->once()->andReturn(preprovisionJobCollectGroupNamesResult('TestGroup'));
    $helper->shouldReceive('preprovision_devices_to_group')
        ->twice()
        ->ordered()
        ->with('TestGroup', \Mockery::type('array'))
        ->andReturn(preprovisionJobJsonResponse([], 201));

    $job = new PreprovisionDevicesToGroupJob([$chunkOne, $chunkTwo], 'TestGroup', $task->fresh(), $helper);
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

    [$task, $deviceRows, $devices] = preprovisionJobMakeTaskAndDevices($user, $client, $deployment, 2);
    $chunkOne = [$deviceRows[0]];
    $chunkTwo = [$deviceRows[1]];

    $task->devices()->updateExistingPivot($devices->first()->id, ['status' => 'COMPLETED']);

    $centralClient = mock(Client::class)->makePartial();
    $helper = mock(CentralAPIHelper::class, [$centralClient])->makePartial();
    $helper->shouldReceive('classic_collect_all_group_names')->once()->andReturn(preprovisionJobCollectGroupNamesResult('TestGroup'));
    $helper->shouldReceive('preprovision_devices_to_group')
        ->once()
        ->with('TestGroup', \Mockery::on(fn ($serials) => $serials === [$deviceRows[1]['serial']]))
        ->andReturn(preprovisionJobJsonResponse([], 201));

    $job = new PreprovisionDevicesToGroupJob([$chunkOne, $chunkTwo], 'TestGroup', $task->fresh(), $helper);
    $job->withFakeQueueInteractions();
    $job->handle();

    $job->assertNotReleased();

    expect($task->devices()->wherePivot('status', 'COMPLETED')->count())->toBe(2);
});

it('dispatchJob creates one preprovision job per group with all chunks', function () {
    Bus::fake();

    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($client)->create();
    $devices = Device::factory()->count(26)->create([
        'client_id' => $client->id,
        'deployment_id' => $deployment->id,
        'user_id' => $user->id,
        'group' => 'central-group',
    ]);

    $task = Task::factory()->create([
        'deployment_id' => $deployment->id,
        'task_type' => TaskType::PREPROVISION_DEVICE_TO_GROUP->name,
        'status' => 'IN_PROGRESS',
    ]);
    $task->devices()->attach($devices->pluck('id')->all(), ['status' => 'PENDING']);

    (new TaskController())->dispatchJob($task->fresh());

    Bus::assertBatched(function ($batch): bool {
        if ($batch->jobs->count() !== 1) {
            return false;
        }

        $job = $batch->jobs->first();

        return $job instanceof PreprovisionDevicesToGroupJob
            && $job->group_name === 'central-group'
            && count($job->device_chunks) === 2
            && count($job->device_chunks[0]) === 25
            && count($job->device_chunks[1]) === 1;
    });
});
