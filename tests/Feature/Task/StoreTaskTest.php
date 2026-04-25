<?php

use App\Models\Client;
use App\Models\Device;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()
        ->has(Client::factory())
        ->create();
    $this->client = $this->user->clients()->first();
    $this->client->update(['current' => true]);
    $this->deployment = $this->client->deployments()->create(['name' => 'Test Deployment']);
    $this->actingAs($this->user);
});

test('creating a task with devices stores the task and attaches the devices', function () {
    $devices = Device::factory(2)->create(['deployment_id' => $this->deployment->id, 'client_id' => $this->client->id]);
    $response = $this->post(route('tasks.store', $this->deployment), [
        'name' => 'Test Task',
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'deployment_time' => 1,
        'devices' => $devices->map(fn ($device) => ['id' => $device->id])->toArray(),
    ]);
    $response->assertSessionHasNoErrors();
    $task = $this->deployment->refresh()->tasks()->first();
    expect($task)->not()->toBeNull();
    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'IN_PROGRESS',
    ]);
    $this->assertCount(2, $task->devices);
    $this->assertEquals('PENDING', $task->devices()->find($devices[0])->pivot->status);
    $this->assertEquals('PENDING', $task->devices()->find($devices[1])->pivot->status);
    expect($task->batch_id)->not()->toBeNull();
});
