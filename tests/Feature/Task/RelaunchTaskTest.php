<?php

use App\Models\Client;
use App\Models\Device;
use App\Models\Task;
use App\Models\User;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;

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
