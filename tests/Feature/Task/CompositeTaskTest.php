<?php

use App\Models\Client;
use App\Models\Device;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()
        ->has(Client::factory())
        ->create();
    $this->client = $this->user->clients()->first();
    $this->client->update(['current' => true]);
    $this->deployment = $this->client->deployments()->create(['name' => 'Test Deployment']);
    $this->actingAs($this->user);
});

test('creating REMOVE_VSF_PROFILE_LOCAL_OVERRIDES stores four composite sibling tasks', function () {
    $devices = Device::factory(2)->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'REMOVE_VSF_PROFILE_LOCAL_OVERRIDES',
        'deployment_time' => 1,
        'devices' => $devices->map(fn ($device) => ['id' => $device->id])->toArray(),
    ]);

    $response->assertSessionHasNoErrors();
    $tasks = $this->deployment->refresh()->tasks()->orderBy('composite_order')->get();
    expect($tasks)->toHaveCount(4);
    expect($tasks->pluck('composite_group_id')->unique())->toHaveCount(1);
    expect($tasks->every(fn (Task $t) => $t->composite_kind === 'REMOVE_VSF_PROFILE_LOCAL_OVERRIDES'))->toBeTrue();
    expect($tasks->pluck('task_type')->all())->toBe([
        'REMOVE_LOCAL_OVERRIDE_VLANS',
        'REMOVE_LOCAL_OVERRIDE_DNS_PROFILE',
        'REMOVE_LOCAL_OVERRIDE_STATIC_ROUTE',
        'REMOVE_LOCAL_OVERRIDE_NTP_PROFILE',
    ]);

    $first = $tasks->firstWhere('composite_order', 1);
    expect($first)->not->toBeNull();
    $response->assertRedirect(route('tasks.show', $first));
});

test('creating CONFIGURE_ALL_INTERFACE stores three composite sibling tasks', function () {
    $devices = Device::factory(1)->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'CONFIGURE_ALL_INTERFACE',
        'deployment_time' => 1,
        'devices' => $devices->map(fn ($device) => ['id' => $device->id])->toArray(),
    ]);

    $response->assertSessionHasNoErrors();
    $tasks = $this->deployment->refresh()->tasks()->orderBy('composite_order')->get();
    expect($tasks)->toHaveCount(3);
    expect($tasks->pluck('composite_group_id')->unique())->toHaveCount(1);
    expect($tasks->every(fn (Task $t) => $t->composite_kind === 'CONFIGURE_ALL_INTERFACE'))->toBeTrue();
    expect($tasks->pluck('task_type')->all())->toBe([
        'CONFIGURE_LAG_INTERFACE',
        'CONFIGURE_ETHERNET_INTERFACE',
        'CONFIGURE_VLAN_INTERFACE',
    ]);

    $first = $tasks->firstWhere('composite_order', 1);
    expect($first)->not->toBeNull();
    $response->assertRedirect(route('tasks.show', $first));
});

test('composite task show uses MultiJobTask and includes sub_jobs', function () {
    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
    ]);

    $groupId = (string) Str::uuid();
    $kind = 'REMOVE_VSF_PROFILE_LOCAL_OVERRIDES';
    $definitions = [
        ['REMOVE_LOCAL_OVERRIDE_VLANS', 1],
        ['REMOVE_LOCAL_OVERRIDE_DNS_PROFILE', 2],
        ['REMOVE_LOCAL_OVERRIDE_STATIC_ROUTE', 3],
        ['REMOVE_LOCAL_OVERRIDE_NTP_PROFILE', 4],
    ];

    $created = [];
    foreach ($definitions as [$taskType, $order]) {
        $created[] = Task::query()->create([
            'name' => 'composite_sub_'.$order,
            'task_type' => $taskType,
            'deployment_id' => $this->deployment->id,
            'deployment_time' => 1,
            'status' => 'IN_PROGRESS',
            'composite_group_id' => $groupId,
            'composite_kind' => $kind,
            'composite_order' => $order,
        ]);
    }

    foreach ($created as $task) {
        $task->devices()->attach($device->id, ['status' => 'PENDING']);
    }

    $firstTask = $created[0];

    $this->get(route('tasks.show', $firstTask))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Task/MultiJobTask')
            ->where('logical_friendly_name', 'Remove VSF profile local overrides')
            ->has('sub_jobs', 4)
            ->has('sub_jobs.0', fn (Assert $job) => $job
                ->where('task_type', 'REMOVE_LOCAL_OVERRIDE_VLANS')
                ->where('friendly_label', 'Remove local VLAN overrides')
                ->where('total_count', 1)
                ->etc())
        );
});
