<?php

use App\DeviceFunction;
use App\Helper\CentralAPIHelper;
use App\Jobs\ConfigureMirrorSessionJob;
use App\Models\Client;
use App\Models\Device;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Client\Response;

beforeEach(function () {
    $this->user = User::factory()->has(Client::factory())->create();
    $this->client = $this->user->clients()->first();
    $this->client->update(['current' => true]);
    $this->deployment = $this->client->deployments()->create(['name' => 'Mirror Task Deployment']);
});

function makeMirrorTaskDevice(Task $task, Client $client, array $overrides = []): Device
{
    $device = Device::factory()->create(array_merge([
        'deployment_id' => $task->deployment_id,
        'client_id' => $client->id,
        'scope_id' => 'device-scope-id',
        'name' => 'NY1-MDF-CORE-SW1',
        'serial' => 'CORE123456',
        'device_function' => DeviceFunction::CORE_SWITCH,
    ], $overrides));

    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    return $device;
}

it('creates mirror session in fallback mode and marks device completed', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'CONFIGURE_MIRROR_SESSION',
        'status' => 'IN_PROGRESS',
        'mirror_fallback_mode' => true,
    ]);

    $device = makeMirrorTaskDevice($task, $this->client);

    $successResponse = Mockery::mock(Response::class);
    $successResponse->shouldReceive('successful')->andReturn(true);

    $centralApi = Mockery::mock(CentralAPIHelper::class);
    $centralApi->shouldReceive('resolveMirrorSettings')
        ->once()
        ->with(Mockery::on(fn (Device $d) => $d->is($device)), true)
        ->andReturn([
            'name' => 'NY1-MDF-CORE-SW1-DARKTRACE-SPAN',
            'session_id' => 1,
            'dst_ports' => ['1/1/43'],
            'vlan_ids' => [10, 20],
        ]);
    $centralApi->shouldReceive('post_mirror')
        ->once()
        ->with(
            Mockery::on(function (array $payload) use ($device): bool {
                return $payload['name'] === 'NY1-MDF-CORE-SW1-DARKTRACE-SPAN'
                    && $payload['session']['session-id'] === 1
                    && $payload['session']['session-destination']['destination-switch-serial'] === $device->serial
                    && $payload['session']['session-destination']['eth-interfaces'] === [
                        ['eth-interface' => '1/1/43'],
                    ]
                    && $payload['session']['session-sources']['source-switch-interface'] === $device->serial
                    && $payload['session']['session-sources']['vlans'] === [
                        ['direction' => 'BOTH', 'vlan-id' => 10],
                        ['direction' => 'BOTH', 'vlan-id' => 20],
                    ];
            }),
            [
                'object-type' => 'LOCAL',
                'scope-id' => $device->scope_id,
                'device-function' => CentralAPIHelper::deviceFunctionQueryValue($device),
            ]
        )
        ->andReturn($successResponse);

    $job = new ConfigureMirrorSessionJob($device, $task, $centralApi, true);
    $job->handle();

    expect($task->devices()->find($device->id)->pivot->status)->toBe('COMPLETED')
        ->and($task->fresh()->status)->toBe('COMPLETED');
});

it('uses explicit mirror settings from the database in explicit mode', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'CONFIGURE_MIRROR_SESSION',
        'status' => 'IN_PROGRESS',
        'mirror_fallback_mode' => false,
    ]);

    $device = makeMirrorTaskDevice($task, $this->client, [
        'name' => 'NY1-MDF-CORE-SW1',
        'mirror_dst_ports' => '1/1/10&1/1/11',
        'mirror_session_id' => 2,
        'mirror_name' => 'custom-mirror',
        'mirror_vlans' => '100&200-202',
    ]);

    $successResponse = Mockery::mock(Response::class);
    $successResponse->shouldReceive('successful')->andReturn(true);

    $centralApi = Mockery::mock(CentralAPIHelper::class);
    $centralApi->shouldReceive('resolveMirrorSettings')
        ->once()
        ->with(Mockery::on(fn (Device $d) => $d->is($device)), false)
        ->andReturn([
            'name' => 'custom-mirror',
            'session_id' => 2,
            'dst_ports' => ['1/1/10', '1/1/11'],
            'vlan_ids' => [100, 200, 201, 202],
        ]);
    $centralApi->shouldReceive('post_mirror')->once()->andReturn($successResponse);

    $job = new ConfigureMirrorSessionJob($device, $task, $centralApi, false);
    $job->handle();

    expect($task->devices()->find($device->id)->pivot->status)->toBe('COMPLETED');
});

it('marks device failed when mirror settings cannot be resolved', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'CONFIGURE_MIRROR_SESSION',
        'status' => 'IN_PROGRESS',
        'mirror_fallback_mode' => true,
    ]);

    $device = makeMirrorTaskDevice($task, $this->client);

    $centralApi = Mockery::mock(CentralAPIHelper::class);
    $centralApi->shouldReceive('resolveMirrorSettings')
        ->once()
        ->andReturn(['error' => 'Cannot determine mirror destination ports.']);
    $centralApi->shouldNotReceive('post_mirror');

    $job = new ConfigureMirrorSessionJob($device, $task, $centralApi, true);
    $job->handle();

    expect($task->devices()->find($device->id)->pivot->status)->toBe('FAILED');
});
