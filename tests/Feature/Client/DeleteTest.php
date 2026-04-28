<?php

use App\DeviceFunction;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\Task;
use App\Models\User;

test('an authenticated user can delete own client', function () {
    $user = User::factory()
        ->has(Client::factory())->create();

    $client = $user->clients->first();

    $this->actingAs($user);
    $this->delete(route('clients.destroy', $client))
        ->assertRedirect();

    $this->assertDatabaseMissing('clients', $client->toArray());
});

test('clients cannot be deleted by other users', function () {
    $user = User::factory()
        ->has(Client::factory())->create();
    $user2 = User::factory()->create();

    $client = $user->clients->first();

    $this->actingAs($user2);
    $this->delete(route('clients.destroy', $client))
        ->assertForbidden();
});

test('deleting a client removes deployments, tasks, and pivot rows', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create(['current' => true]);
    $deployment = Deployment::factory()->for($client)->create();
    $device = Device::query()->create([
        'name' => 'device-one',
        'serial' => 'serial-delete-flow-1',
        'client_id' => $client->id,
        'deployment_id' => $deployment->id,
        'device_function' => DeviceFunction::ACCESS_SWITCH->name,
        'scope_id' => null,
    ]);
    $task = Task::factory()->for($deployment)->create();
    $task->devices()->attach($device->id);
    $task->users()->attach($user->id);
    $interface = DeviceInterface::factory()->for($device)->create();
    $task->deviceInterfaces()->attach($interface->id);

    $this->actingAs($user);
    $this->delete(route('clients.destroy', $client))
        ->assertRedirect();

    $this->assertDatabaseMissing('clients', ['id' => $client->id]);
    $this->assertDatabaseMissing('deployments', ['id' => $deployment->id]);
    $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    $this->assertDatabaseMissing('devices', ['id' => $device->id]);
    $this->assertDatabaseMissing('device_task', ['task_id' => $task->id]);
    $this->assertDatabaseMissing('device_interface_task', ['task_id' => $task->id]);
    $this->assertDatabaseMissing('task_user', ['task_id' => $task->id]);
});

test('deleting the current client promotes another client when one exists', function () {
    $user = User::factory()->create();
    $current = Client::factory()->for($user)->create(['current' => true]);
    $other = Client::factory()->for($user)->create(['current' => false]);

    $this->actingAs($user);
    $this->delete(route('clients.destroy', $current))
        ->assertRedirect();

    expect($other->refresh()->current)->toBeTrue();
    $this->assertDatabaseMissing('clients', ['id' => $current->id]);
});
