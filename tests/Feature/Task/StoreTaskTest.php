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
    $this->deployment = $this->client->deployments()->for($this->client)->create(['name' => 'Test Deployment']);
    $this->actingAs($this->user);
});

test('creating a task with devices stores the task and attaches the devices', function () {
    $devices = Device::factory(2)->create(['deployment_id' => $this->deployment->id, 'client_id' => $this->client->id]);
    $this->post(route('tasks.store', $this->deployment), [
        'name' => 'Test Task',
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'devices' => $devices
    ]);
    $this->assertDatabaseHas('tasks', ['name' => 'Test Task']);
    $task = $this->deployment->refresh()->tasks()->first();
    $this->assertCount(2, $task->devices);
    $this->assertEquals($task->devices()->find($devices[0])->status, 'PENDING');
    $this->assertEquals($task->devices()->find($devices[1])->status, 'PENDING');
});
