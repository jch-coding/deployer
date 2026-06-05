<?php

use App\DeviceFunction;
use App\Helper\CentralAPIHelper;
use App\Jobs\CreateVsxProfileJob;
use App\Models\Client;
use App\Models\Device;
use App\Models\Site;
use App\Models\Task;
use App\Models\User;
use App\VsxRole;
use Illuminate\Http\Client\Response;

beforeEach(function () {
    $this->user = User::factory()->has(Client::factory())->create();
    $this->client = $this->user->clients()->first();
    $this->client->update(['current' => true]);
    $this->deployment = $this->client->deployments()->create(['name' => 'VSX Task Deployment']);
});

function makeVsxDevicePair(Task $task, Client $client, array $primaryOverrides = [], array $secondaryOverrides = []): array
{
    $site = Site::factory()->for($client)->create([
        'scope_id' => 'site-scope-id',
    ]);

    $primary = Device::factory()->create(array_merge([
        'deployment_id' => $task->deployment_id,
        'client_id' => $client->id,
        'site_id' => $site->id,
        'scope_id' => 'primary-scope',
        'group' => 'WHSE-TEST-ACCESS',
        'name' => 'Primary-SVR-SW',
        'serial' => 'PRIMARY123',
        'device_function' => DeviceFunction::ACCESS_SWITCH,
        'vsx_profile' => 'vsx-pair-1',
        'vsx_role' => VsxRole::VSX_PRIMARY->name,
        'vsx_system_mac' => '02:00:00:00:00:01',
    ], $primaryOverrides));

    $secondary = Device::factory()->create(array_merge([
        'deployment_id' => $task->deployment_id,
        'client_id' => $client->id,
        'site_id' => $site->id,
        'scope_id' => 'secondary-scope',
        'group' => 'WHSE-TEST-ACCESS',
        'name' => 'Secondary-SVR-SW',
        'serial' => 'SECONDARY123',
        'device_function' => DeviceFunction::ACCESS_SWITCH,
        'vsx_profile' => 'vsx-pair-1',
        'vsx_role' => VsxRole::VSX_SECONDARY->name,
        'vsx_system_mac' => '02:00:00:00:00:01',
    ], $secondaryOverrides));

    $task->devices()->attach($primary->id, ['status' => 'PENDING']);
    $task->devices()->attach($secondary->id, ['status' => 'PENDING']);

    return [$primary, $secondary, $site];
}

function mockSuccessfulVsxPrerequisites(CentralAPIHelper $centralApi): void
{
    $centralApi->shouldReceive('ensureVsxKeepAliveVrf')->andReturn(['ok' => true]);
    $centralApi->shouldReceive('ensureVsxIslLag')->andReturn(['ok' => true]);
    $centralApi->shouldReceive('ensureVsxKeepaliveLag')->andReturn(['ok' => true]);
}

it('creates vsx profile and marks both devices completed on success', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'CREATE_VSX_PROFILE',
        'status' => 'IN_PROGRESS',
    ]);

    [$primary, $secondary] = makeVsxDevicePair($task, $this->client);

    $successResponse = Mockery::mock(Response::class);
    $successResponse->shouldReceive('ok')->andReturn(true);

    $centralApi = Mockery::mock(CentralAPIHelper::class);
    $centralApi->shouldReceive('ensureVsxKeepAliveVrf')->twice()->andReturn(['ok' => true]);
    $centralApi->shouldReceive('ensureVsxIslLag')
        ->twice()
        ->with(Mockery::type(Device::class), Mockery::type(Device::class), ['1/1/21', '1/1/22'])
        ->andReturn(['ok' => true]);
    $centralApi->shouldReceive('ensureVsxKeepaliveLag')
        ->twice()
        ->with(Mockery::type(Device::class), Mockery::type(Device::class), Mockery::type(App\VsxRole::class), ['1/1/23', '1/1/24'])
        ->andReturn(['ok' => true]);
    $centralApi->shouldReceive('post_vsx_profile')
        ->once()
        ->with(Mockery::type('array'), 'site-scope-id')
        ->andReturn($successResponse);

    $job = new CreateVsxProfileJob(
        'vsx-pair-1',
        collect([$primary, $secondary]),
        $task,
        $centralApi
    );
    $job->handle();

    expect($task->devices()->find($primary->id)->pivot->status)->toBe('COMPLETED')
        ->and($task->devices()->find($secondary->id)->pivot->status)->toBe('COMPLETED')
        ->and($task->fresh()->status)->toBe('COMPLETED');
});

it('aborts when vrf creation fails', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'CREATE_VSX_PROFILE',
        'status' => 'IN_PROGRESS',
    ]);

    [$primary, $secondary] = makeVsxDevicePair($task, $this->client);

    $centralApi = Mockery::mock(CentralAPIHelper::class);
    $centralApi->shouldReceive('ensureVsxKeepAliveVrf')
        ->andReturn(['error' => 'WHSE-VSX-Keep-Alive VRF creation failed at group level for Primary-SVR-SW']);
    $centralApi->shouldNotReceive('post_vsx_profile');

    $job = new CreateVsxProfileJob(
        'vsx-pair-1',
        collect([$primary, $secondary]),
        $task,
        $centralApi
    );
    $job->handle();

    expect($task->fresh()->status)->toBe('FAILED')
        ->and($task->devices()->find($primary->id)->pivot->status)->toBe('FAILED')
        ->and($task->devices()->find($secondary->id)->pivot->status)->toBe('FAILED');
});

it('fails validation when device has no group', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'CREATE_VSX_PROFILE',
        'status' => 'IN_PROGRESS',
    ]);

    [$primary, $secondary] = makeVsxDevicePair($task, $this->client, ['group' => null]);

    $centralApi = Mockery::mock(CentralAPIHelper::class);
    $centralApi->shouldNotReceive('post_vsx_profile');

    $job = new CreateVsxProfileJob(
        'vsx-pair-1',
        collect([$primary, $secondary]),
        $task,
        $centralApi
    );
    $job->handle();

    expect($task->fresh()->status)->toBe('FAILED');
});

it('fails validation when only one device is provided', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'CREATE_VSX_PROFILE',
        'status' => 'IN_PROGRESS',
    ]);

    [$primary] = makeVsxDevicePair($task, $this->client);

    $centralApi = Mockery::mock(CentralAPIHelper::class);
    $centralApi->shouldNotReceive('post_vsx_profile');

    $job = new CreateVsxProfileJob(
        'vsx-pair-1',
        collect([$primary]),
        $task,
        $centralApi
    );
    $job->handle();

    expect($task->fresh()->status)->toBe('FAILED');
});

it('aborts when lag ensure fails', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'CREATE_VSX_PROFILE',
        'status' => 'IN_PROGRESS',
    ]);

    [$primary, $secondary] = makeVsxDevicePair($task, $this->client);

    $centralApi = Mockery::mock(CentralAPIHelper::class);
    $centralApi->shouldReceive('ensureVsxKeepAliveVrf')->andReturn(['ok' => true]);
    $centralApi->shouldReceive('ensureVsxIslLag')
        ->andReturn(['error' => 'LAG 256 inter-switch-link on Primary-SVR-SW does not match expected configuration']);
    $centralApi->shouldNotReceive('post_vsx_profile');

    $job = new CreateVsxProfileJob(
        'vsx-pair-1',
        collect([$primary, $secondary]),
        $task,
        $centralApi
    );
    $job->handle();

    expect($task->fresh()->status)->toBe('FAILED');
});

it('fails validation when device name does not indicate vsx lag ports', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'CREATE_VSX_PROFILE',
        'status' => 'IN_PROGRESS',
    ]);

    [$primary, $secondary] = makeVsxDevicePair($task, $this->client, [
        'name' => 'Primary-SW',
    ], [
        'name' => 'Secondary-SW',
    ]);

    $centralApi = Mockery::mock(CentralAPIHelper::class);
    $centralApi->shouldNotReceive('post_vsx_profile');

    $job = new CreateVsxProfileJob(
        'vsx-pair-1',
        collect([$primary, $secondary]),
        $task,
        $centralApi
    );
    $job->handle();

    expect($task->fresh()->status)->toBe('FAILED');
});
