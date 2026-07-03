<?php

use App\Helper\CentralAPIHelper;
use App\Jobs\PreprovisionDevicesToGroupJob;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\Task;
use App\Models\User;
use App\TaskType;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;

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

    $job = new PreprovisionDevicesToGroupJob($deviceRows, 'TestGroup', $task->fresh(), $helper);
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

    $job = new PreprovisionDevicesToGroupJob($deviceRows, 'TestGroup', $task->fresh(), $helper);
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

    $job = new PreprovisionDevicesToGroupJob($deviceRows, 'MissingGroup', $task->fresh(), $helper);
    $job->withFakeQueueInteractions();
    $job->handle();

    $job->assertNotReleased();

    expect($task->fresh()->status)->toBe('FAILED')
        ->and($task->devices()->wherePivot('status', 'FAILED')->count())->toBe($devices->count());
});

it('applies staggered delays to preprovision chunk jobs', function () {
    $task = Task::factory()->create();
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $helper = new CentralAPIHelper($client->refresh());
    $taskController = new App\Http\Controllers\TaskController();

    $payload = [
        'keys' => ['group-a'],
        'chunked_devices_by_group' => [
            [
                [['id' => 1, 'serial' => 'CN1', 'name' => 'd1']],
                [['id' => 2, 'serial' => 'CN2', 'name' => 'd2']],
                [['id' => 3, 'serial' => 'CN3', 'name' => 'd3']],
            ],
        ],
    ];

    $jobs = $taskController->create_jobs_by_grouped_chunks($payload, $task, $helper, PreprovisionDevicesToGroupJob::class);

    foreach (array_values($jobs) as $index => $job) {
        $job->delay(now()->addSeconds(PreprovisionDevicesToGroupJob::BATCH_DELAY_SECONDS * $index));
    }

    expect($jobs)->toHaveCount(3)
        ->and($jobs[0]->delay->diffInSeconds(now()))->toBeLessThanOrEqual(1)
        ->and($jobs[1]->delay->diffInSeconds(now()->addSeconds(PreprovisionDevicesToGroupJob::BATCH_DELAY_SECONDS)))->toBeLessThanOrEqual(1)
        ->and($jobs[2]->delay->diffInSeconds(now()->addSeconds(PreprovisionDevicesToGroupJob::BATCH_DELAY_SECONDS * 2)))->toBeLessThanOrEqual(1);
});
