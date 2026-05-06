<?php

use App\Models\Client;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\Task;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()
        ->has(Client::factory())
        ->create();
    $this->client = $this->user->clients()->first();
    $this->client->update(['current' => true]);
    $this->deployment = $this->client->deployments()->create(['name' => 'Alpha Deployment']);
    $this->actingAs($this->user);
});

test('tasks index shows only tasks for the current client', function () {
    $visibleTask = Task::factory()->for($this->deployment)->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'IN_PROGRESS',
    ]);

    $otherClient = Client::factory()->for($this->user)->create();
    $otherDeployment = $otherClient->deployments()->create(['name' => 'Other Deployment']);
    Task::factory()->for($otherDeployment)->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'IN_PROGRESS',
    ]);

    $this->get(route('tasks.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Task/Index')
            ->has('tasks.data', 1)
            ->where('tasks.data.0.id', $visibleTask->id)
            ->has('tasks.data.0.human_updated_at')
        );
});

test('tasks index filters by status', function () {
    $matching = Task::factory()->for($this->deployment)->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'FAILED',
    ]);
    Task::factory()->for($this->deployment)->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'COMPLETED',
    ]);

    $this->get(route('tasks.index', ['status' => 'FAILED']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1)
            ->where('tasks.data.0.id', $matching->id)
            ->where('tasks.data.0.status', 'FAILED')
        );
});

test('tasks index filters by deployment name', function () {
    $matchingDeployment = $this->client->deployments()->create(['name' => 'North Campus']);
    $otherDeployment = $this->client->deployments()->create(['name' => 'South Campus']);
    $matching = Task::factory()->for($matchingDeployment)->create(['task_type' => 'UPDATE_SYSTEM_INFO']);
    Task::factory()->for($otherDeployment)->create(['task_type' => 'UPDATE_SYSTEM_INFO']);

    $this->get(route('tasks.index', ['deployment_name' => 'north']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1)
            ->where('tasks.data.0.id', $matching->id)
            ->where('tasks.data.0.deployment_name', 'North Campus')
        );
});

test('tasks index filters by friendly task name', function () {
    $matching = Task::factory()->for($this->deployment)->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'IN_PROGRESS',
    ]);
    Task::factory()->for($this->deployment)->create([
        'task_type' => 'CONFIGURE_VLAN_INTERFACE',
        'status' => 'IN_PROGRESS',
    ]);

    $this->get(route('tasks.index', ['task_name' => 'name devices']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1)
            ->where('tasks.data.0.id', $matching->id)
            ->where('tasks.data.0.task_name', 'Name Devices')
        );
});

test('tasks index item_count uses devices for device tasks and interfaces for interface tasks', function () {
    $deviceTask = Task::factory()->for($this->deployment)->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
    ]);
    $interfaceTask = Task::factory()->for($this->deployment)->create([
        'task_type' => 'CONFIGURE_VLAN_INTERFACE',
    ]);

    $devices = Device::factory(2)->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
    ]);
    $deviceTask->devices()->attach($devices->pluck('id')->all(), ['status' => 'PENDING']);

    $interfaces = DeviceInterface::factory(3)->create([
        'device_id' => $devices->first()->id,
    ]);
    $interfaceTask->deviceInterfaces()->attach($interfaces->pluck('id')->all(), ['status' => 'PENDING']);

    $this->get(route('tasks.index', ['task_name' => 'name devices']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1)
            ->where('tasks.data.0.id', $deviceTask->id)
            ->where('tasks.data.0.item_count', 2)
        );

    $this->get(route('tasks.index', ['task_name' => 'configure svi']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1)
            ->where('tasks.data.0.id', $interfaceTask->id)
            ->where('tasks.data.0.item_count', 3)
        );
});
