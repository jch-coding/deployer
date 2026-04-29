<?php

use App\Models\Client;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\LacpProfile;
use App\Models\Task;
use App\Models\User;
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

test('creating a task with devices stores the task and attaches the devices', function () {
    Bus::fake();

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

test('CONFIGURE_ALL_INTERFACE does not create subtasks when selected devices have no eligible interfaces', function () {
    Bus::fake();

    $devices = Device::factory(1)->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
    ]);

    $response = $this->from(route('deployments.show', $this->deployment))
        ->post(route('tasks.store', $this->deployment), [
            'task_type' => 'CONFIGURE_ALL_INTERFACE',
            'deployment_time' => 1,
            'devices' => $devices->map(fn ($device) => ['id' => $device->id])->toArray(),
        ]);

    $response->assertRedirect(route('deployments.show', $this->deployment));
    $response->assertSessionHasErrors('devices');
    expect($this->deployment->refresh()->tasks)->toHaveCount(0);
});

test('CONFIGURE_ALL_INTERFACE creates only eligible subtasks and keeps canonical order', function () {
    Bus::fake();

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
    ]);

    $lacp = LacpProfile::query()->create([
        'mode' => 'ACTIVE',
        'rate' => 'SLOW',
        'trunk_type' => 'LACP',
        'port_list' => '1/1/1-1/1/2',
    ]);

    // One interface per composite subtask category.
    DeviceInterface::query()->create(['device_id' => $device->id, 'interface' => '1/1/1']);
    DeviceInterface::query()->create(['device_id' => $device->id, 'interface' => '10', 'ip_address' => '10.0.0.1/24']);
    DeviceInterface::query()->create(['device_id' => $device->id, 'interface' => '11', 'lacp_profile_id' => $lacp->id]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'CONFIGURE_ALL_INTERFACE',
        'deployment_time' => 1,
        'devices' => [['id' => $device->id]],
    ]);

    $response->assertSessionHasNoErrors();

    $tasks = Task::query()
        ->where('deployment_id', $this->deployment->id)
        ->orderBy('composite_order')
        ->get();

    expect($tasks)->toHaveCount(3);
    expect($tasks->pluck('task_type')->all())->toBe([
        'CONFIGURE_LAG_INTERFACE',
        'CONFIGURE_ETHERNET_INTERFACE',
        'CONFIGURE_VLAN_INTERFACE',
    ]);
    expect($tasks->pluck('composite_order')->all())->toBe([1, 2, 3]);
});

test('CONFIGURE_ALL_INTERFACE creates only ethernet subtask when only ethernet interfaces exist', function () {
    Bus::fake();

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
    ]);

    DeviceInterface::query()->create([
        'device_id' => $device->id,
        'interface' => '1/1/24',
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'CONFIGURE_ALL_INTERFACE',
        'deployment_time' => 1,
        'devices' => [['id' => $device->id]],
    ]);

    $response->assertSessionHasNoErrors();

    $tasks = Task::query()
        ->where('deployment_id', $this->deployment->id)
        ->orderBy('composite_order')
        ->get();

    expect($tasks)->toHaveCount(1);
    expect($tasks->first()->task_type)->toBe('CONFIGURE_ETHERNET_INTERFACE');
    expect($tasks->first()->composite_order)->toBe(1);
});
