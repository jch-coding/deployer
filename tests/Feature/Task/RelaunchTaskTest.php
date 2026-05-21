<?php

use App\InterfaceKind;
use App\Models\Client;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\Task;
use App\Models\User;
use App\Services\RelaunchFailedCriticalConfigService;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()
        ->has(Client::factory())
        ->create();
    $this->client = $this->user->clients()->first();
    $this->client->update(['current' => true]);
    $this->deployment = $this->client->deployments()->create(['name' => 'Test Deployment']);
    $this->actingAs($this->user);
});

test('relaunching a failed task sets status in progress, updates batch id, and dispatches only non-completed pivots', function () {
    Bus::fake();

    $devices = Device::factory(2)->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
    ]);

    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'FAILED',
        'batch_id' => 'old-batch-id',
    ]);

    $task->devices()->attach($devices[0]->id, ['status' => 'COMPLETED']);
    $task->devices()->attach($devices[1]->id, ['status' => 'FAILED']);

    $this->post(route('tasks.relaunch', $task))
        ->assertRedirect(route('tasks.show', $task));

    $task->refresh();
    expect($task->status)->toBe('IN_PROGRESS');
    expect($task->batch_id)->not->toBeNull();
    expect($task->batch_id)->not->toBe('old-batch-id');

    Bus::assertBatched(fn (PendingBatch $batch) => count($batch->jobs) === 1);

    $task->load('devices');
    expect($task->devices->firstWhere('id', $devices[0]->id)->pivot->status)->toBe('COMPLETED');
    expect($task->devices->firstWhere('id', $devices[1]->id)->pivot->status)->toBe('PENDING');
});

test('relaunching a cancelled task sets status in progress and redirects to show', function () {
    Bus::fake();

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
    ]);

    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'CANCELLED',
        'batch_id' => 'old-batch-id',
    ]);

    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    $this->post(route('tasks.relaunch', $task))
        ->assertRedirect(route('tasks.show', $task));

    $task->refresh();
    expect($task->status)->toBe('IN_PROGRESS');
    expect($task->batch_id)->not->toBeNull();
    expect($task->batch_id)->not->toBe('old-batch-id');
});

test('relaunching a timed out task sets status in progress and redirects to show', function () {
    Bus::fake();

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
    ]);

    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'TIMED_OUT',
        'batch_id' => 'old-batch-id',
    ]);

    $task->devices()->attach($device->id, ['status' => 'TIMED_OUT']);

    $this->post(route('tasks.relaunch', $task))
        ->assertRedirect(route('tasks.show', $task));

    $task->refresh();
    expect($task->status)->toBe('IN_PROGRESS');
    expect($task->batch_id)->not->toBeNull();
    expect($task->batch_id)->not->toBe('old-batch-id');

    $task->load('devices');
    expect($task->devices->first()->pivot->status)->toBe('PENDING');
});

test('relaunch with custom timers persists deployment_time and wait_time', function () {
    Bus::fake();

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
    ]);

    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'FAILED',
        'deployment_time' => 1,
        'wait_time' => 1,
    ]);

    $task->devices()->attach($device->id, ['status' => 'FAILED']);

    $this->post(route('tasks.relaunch', $task), [
        'deployment_time' => 90,
        'wait_time' => 5,
    ])->assertRedirect(route('tasks.show', $task));

    $task->refresh();
    expect($task->status)->toBe('IN_PROGRESS')
        ->and($task->deployment_time)->toBe(90)
        ->and($task->wait_time)->toBe(5);
});

test('relaunch rejects non-failed-and-non-cancelled task statuses', function () {
    Bus::fake();

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
    ]);

    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'IN_PROGRESS',
    ]);

    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    $this->from(route('tasks.index'))
        ->post(route('tasks.relaunch', $task))
        ->assertRedirect(route('tasks.index'))
        ->assertSessionHas('error', 'Only failed, timed out, or cancelled tasks can be relaunched.');

    $task->refresh();
    expect($task->status)->toBe('IN_PROGRESS');
    Bus::assertNothingBatched();
});

test('relaunching a failed composite remediation group relaunches all siblings', function () {
    Bus::fake();

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
    ]);
    $lagInterface = DeviceInterface::factory()->create([
        'device_id' => $device->id,
        'interface_kind' => InterfaceKind::LAG,
    ]);
    $vlanInterface = DeviceInterface::factory()->create([
        'device_id' => $device->id,
        'interface_kind' => InterfaceKind::VLAN,
    ]);

    $group = (string) Str::uuid();
    $jobQueue = 'q0';
    $first = Task::factory()->for($this->deployment)->create([
        'task_type' => 'CONFIGURE_LAG_INTERFACE',
        'status' => 'FAILED',
        'job_queue' => $jobQueue,
        'composite_group_id' => $group,
        'composite_kind' => RelaunchFailedCriticalConfigService::COMPOSITE_KIND,
        'composite_order' => 1,
    ]);
    $second = Task::factory()->for($this->deployment)->create([
        'task_type' => 'CONFIGURE_VLAN_INTERFACE',
        'status' => 'FAILED',
        'job_queue' => $jobQueue,
        'composite_group_id' => $group,
        'composite_kind' => RelaunchFailedCriticalConfigService::COMPOSITE_KIND,
        'composite_order' => 2,
    ]);

    $first->devices()->attach($device->id, ['status' => 'FAILED']);
    $first->deviceInterfaces()->attach([$lagInterface->id => ['status' => 'FAILED']]);
    $second->devices()->attach($device->id, ['status' => 'FAILED']);
    $second->deviceInterfaces()->attach([$vlanInterface->id => ['status' => 'FAILED']]);

    $this->post(route('tasks.relaunch', $first))
        ->assertRedirect(route('tasks.show', $first));

    expect($first->refresh()->status)->toBe('IN_PROGRESS');
    expect($second->refresh()->status)->toBe('IN_PROGRESS');

    $batches = Bus::batched(fn (PendingBatch $batch) => count($batch->jobs) === 1);
    expect($batches)->toHaveCount(2);
});
