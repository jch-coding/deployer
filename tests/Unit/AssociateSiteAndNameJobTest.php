<?php

use App\DeviceFunction;
use App\Helper\CentralAPIHelper;
use App\Jobs\AssociateSiteAndNameJob;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\Site;
use App\Models\Task;
use App\Models\User;
use App\TaskType;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;

function associateJobJsonResponse(array $json, int $status = 200): Response
{
    return new Response(new Psr7Response($status, ['Content-Type' => 'application/json'], json_encode($json)));
}

function associateJobMakeDeviceTaskSite(
    User $user,
    Client $client,
    Deployment $deployment,
    ?Site $site,
    array $deviceOverrides = [],
    array $taskOverrides = []
): array {
    $task = Task::factory()->create(array_merge([
        'deployment_id' => $deployment->id,
        'task_type' => TaskType::ASSOCIATE_SITE_AND_NAME->name,
        'status' => 'IN_PROGRESS',
    ], $taskOverrides));

    $device = Device::factory()->create(array_merge([
        'deployment_id' => $deployment->id,
        'client_id' => $client->id,
        'user_id' => $user->id,
        'site_id' => $site?->id,
        'device_function' => DeviceFunction::ACCESS_SWITCH->name,
    ], $deviceOverrides));

    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    return [$task, $device];
}

it('maps device function strings to classic central device types', function (string $deviceFunction, string $expected) {
    $job = new AssociateSiteAndNameJob(
        new Device,
        new Task,
        mock(CentralAPIHelper::class)
    );

    expect($job->get_device_type($deviceFunction))->toBe($expected);
})->with([
    [DeviceFunction::ACCESS_SWITCH->name, 'SWITCH'],
    [DeviceFunction::CAMPUS_AP->name, 'IAP'],
    [DeviceFunction::VPNC->name, 'CONTROLLER'],
]);

it('fails the queue job when classic_get_sites returns a token error array', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($client)->create();
    $site = Site::factory()->create(['name' => 'Alpha', 'classic_id' => null]);

    [$task, $device] = associateJobMakeDeviceTaskSite($user, $client, $deployment, $site);

    $centralClient = mock(Client::class)->makePartial();
    $helper = mock(CentralAPIHelper::class, [$centralClient])->makePartial();
    $helper->shouldReceive('classic_get_sites')->once()->andReturn(['error' => 'failed to get access token from central.']);

    $job = new AssociateSiteAndNameJob($device->fresh(['site']), $task->fresh(), $helper);
    $job->withFakeQueueInteractions();
    $job->handle();

    $job->assertFailed();
});

it('fails the queue job when classic_get_sites response is not successful', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($client)->create();
    $site = Site::factory()->create(['name' => 'Alpha', 'classic_id' => null]);

    [$task, $device] = associateJobMakeDeviceTaskSite($user, $client, $deployment, $site);

    $centralClient = mock(Client::class)->makePartial();
    $helper = mock(CentralAPIHelper::class, [$centralClient])->makePartial();
    $helper->shouldReceive('classic_get_sites')->once()->andReturn(associateJobJsonResponse(['message' => 'upstream error'], 502));

    $job = new AssociateSiteAndNameJob($device->fresh(['site']), $task->fresh(), $helper);
    $job->withFakeQueueInteractions();
    $job->handle();

    $job->assertFailed();
});

it('fails the queue job when the deployment site is not present in classic central sites', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($client)->create();
    $site = Site::factory()->create(['name' => 'Missing Here', 'classic_id' => null]);

    [$task, $device] = associateJobMakeDeviceTaskSite($user, $client, $deployment, $site);

    $centralClient = mock(Client::class)->makePartial();
    $helper = mock(CentralAPIHelper::class, [$centralClient])->makePartial();
    $helper->shouldReceive('classic_get_sites')->once()->andReturn(
        associateJobJsonResponse(['sites' => [['site_name' => 'Other Site', 'site_id' => 1]]], 200)
    );

    $job = new AssociateSiteAndNameJob($device->fresh(['site']), $task->fresh(), $helper);
    $job->withFakeQueueInteractions();
    $job->handle();

    $job->assertFailed();
});

it('persists classic_id from classic central then stops when associate fails without requesting scope id', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($client)->create();
    $site = Site::factory()->create(['name' => 'Rome', 'classic_id' => null]);

    [$task, $device] = associateJobMakeDeviceTaskSite($user, $client, $deployment, $site, [
        'scope_id' => null,
    ]);

    $centralClient = mock(Client::class)->makePartial();
    $helper = mock(CentralAPIHelper::class, [$centralClient])->makePartial();
    $helper->shouldReceive('classic_get_sites')->once()->andReturn(
        associateJobJsonResponse(['sites' => [['site_name' => 'Rome', 'site_id' => 4242]]], 200)
    );
    $helper->shouldReceive('classic_associate_device_to_site')->once()->andReturn(
        associateJobJsonResponse(['message' => 'associate failed'], 400)
    );
    $helper->shouldReceive('getScopeIdFromCentral')->never();
    $helper->shouldReceive('postSystemInfo')->never();

    $job = new AssociateSiteAndNameJob($device->fresh(['site']), $task->fresh(), $helper);
    $job->withFakeQueueInteractions();
    $job->handle();

    expect($site->fresh()->classic_id)->toBe(4242);
    // Task DB default wait_time is 1 minute → release(60 * 1)
    $job->assertReleased(60);
});

it('releases the job when associate fails and device already has classic site id', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($client)->create();
    $site = Site::factory()->create(['name' => 'Paris', 'classic_id' => 99]);

    [$task, $device] = associateJobMakeDeviceTaskSite($user, $client, $deployment, $site, [
        'scope_id' => null,
    ]);

    $centralClient = mock(Client::class)->makePartial();
    $helper = mock(CentralAPIHelper::class, [$centralClient])->makePartial();
    $helper->shouldReceive('classic_get_sites')->never();
    $helper->shouldReceive('classic_associate_device_to_site')->once()->andReturn(
        associateJobJsonResponse(['message' => 'bad'], 500)
    );
    $helper->shouldReceive('getScopeIdFromCentral')->never();
    $helper->shouldReceive('postSystemInfo')->never();

    $job = new AssociateSiteAndNameJob($device->fresh(['site']), $task->fresh(), $helper);
    $job->withFakeQueueInteractions();
    $job->handle();

    $job->assertReleased(60);
});

it('completes device pivot when scope id already exists and postSystemInfo succeeds', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($client)->create();
    $site = Site::factory()->create(['name' => 'London', 'classic_id' => 1]);

    [$task, $device] = associateJobMakeDeviceTaskSite($user, $client, $deployment, $site, [
        'scope_id' => 'scope-existing',
    ]);

    $centralClient = mock(Client::class)->makePartial();
    $helper = mock(CentralAPIHelper::class, [$centralClient])->makePartial();
    $helper->shouldReceive('classic_get_sites')->never();
    $helper->shouldReceive('classic_associate_device_to_site')->never();
    $helper->shouldReceive('getScopeIdFromCentral')->never();
    $helper->shouldReceive('postSystemInfo')->once()->with(\Mockery::type(Device::class))->andReturn(
        associateJobJsonResponse([], 200)
    );

    $job = new AssociateSiteAndNameJob($device->fresh(['site']), $task->fresh(), $helper);
    $job->withFakeQueueInteractions();
    $job->handle();

    expect($task->devices()->where('devices.id', $device->id)->first()->pivot->status)->toBe('COMPLETED');
    $job->assertNotReleased();
    $job->assertNotFailed();
});

it('releases the job when postSystemInfo returns a token error array', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($client)->create();
    $site = Site::factory()->create(['name' => 'Berlin', 'classic_id' => 1]);

    [$task, $device] = associateJobMakeDeviceTaskSite($user, $client, $deployment, $site, [
        'scope_id' => 'scope-x',
    ]);

    $centralClient = mock(Client::class)->makePartial();
    $helper = mock(CentralAPIHelper::class, [$centralClient])->makePartial();
    $helper->shouldReceive('postSystemInfo')->once()->andReturn(['error' => 'failed to get access token from central.']);

    $job = new AssociateSiteAndNameJob($device->fresh(['site']), $task->fresh(), $helper);
    $job->withFakeQueueInteractions();
    $job->handle();

    $job->assertReleased(60);
    expect($task->devices()->where('devices.id', $device->id)->first()->pivot->status)->toBe('PENDING');
});

it('runs associate then scope lookup then postSystemInfo when starting without scope id', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($client)->create();
    $site = Site::factory()->create(['name' => 'Madrid', 'classic_id' => 77]);

    [$task, $device] = associateJobMakeDeviceTaskSite($user, $client, $deployment, $site, [
        'scope_id' => null,
    ]);

    $centralClient = mock(Client::class)->makePartial();
    $helper = mock(CentralAPIHelper::class, [$centralClient])->makePartial();
    $helper->shouldReceive('classic_get_sites')->never();
    $helper->shouldReceive('classic_associate_device_to_site')->once()->andReturn(associateJobJsonResponse([], 200));
    $helper->shouldReceive('getScopeIdFromCentral')->once()->andReturn([['scopeId' => 'scope-from-central']]);
    $helper->shouldReceive('postSystemInfo')->once()->andReturn(associateJobJsonResponse([], 200));

    $job = new AssociateSiteAndNameJob($device->fresh(['site']), $task->fresh(), $helper);
    $job->withFakeQueueInteractions();
    $job->handle();

    expect($device->fresh()->scope_id)->toBe('scope-from-central');
    expect($task->devices()->where('devices.id', $device->id)->first()->pivot->status)->toBe('COMPLETED');
    $job->assertNotReleased();
    $job->assertNotFailed();
});
