<?php

use App\ClassicBaseUrl;
use App\InterfaceKind;
use App\Jobs\AssignDeviceFunctionJob;
use App\Jobs\AssignSubscriptionJob;
use App\Jobs\MoveDevicesToGroupJob;
use App\Jobs\PreprovisionDevicesToGroupJob;
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

test('PREPROVISION_DEVICE_TO_GROUP dispatches a single batch containing every chunk job', function () {
    Bus::fake();

    $devices = Device::factory(26)->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'group' => 'central-group',
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'PREPROVISION_DEVICE_TO_GROUP',
        'deployment_time' => 1,
        'devices' => $devices->map(fn ($device) => ['id' => $device->id])->toArray(),
    ]);

    $response->assertSessionHasNoErrors();
    $task = $this->deployment->refresh()->tasks()->first();
    expect($task)->not()->toBeNull()
        ->and($task->task_type)->toBe('PREPROVISION_DEVICE_TO_GROUP')
        ->and($task->batch_id)->not()->toBeNull();

    Bus::assertBatchCount(1);
    Bus::assertBatched(function ($batch): bool {
        if ($batch->jobs->count() !== 2) {
            return false;
        }

        return $batch->jobs->every(fn ($job) => $job instanceof PreprovisionDevicesToGroupJob);
    });
});

test('MOVE_DEVICE_TO_GROUP dispatches a single batch containing every chunk job', function () {
    Bus::fake();

    $devices = Device::factory(26)->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'group' => 'target-group',
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'MOVE_DEVICE_TO_GROUP',
        'deployment_time' => 1,
        'devices' => $devices->map(fn ($device) => ['id' => $device->id])->toArray(),
    ]);

    $response->assertSessionHasNoErrors();
    $task = $this->deployment->refresh()->tasks()->first();
    expect($task)->not()->toBeNull()
        ->and($task->task_type)->toBe('MOVE_DEVICE_TO_GROUP')
        ->and($task->batch_id)->not()->toBeNull();

    Bus::assertBatchCount(1);
    Bus::assertBatched(function ($batch): bool {
        if ($batch->jobs->count() !== 2) {
            return false;
        }

        return $batch->jobs->every(fn ($job) => $job instanceof MoveDevicesToGroupJob);
    });
});

test('ASSIGN_DEVICE_FUNCTION dispatches grouped chunks for each device function', function () {
    Bus::fake();

    $accessDevices = Device::factory(26)->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'device_function' => 'ACCESS_SWITCH',
    ]);
    $coreDevice = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'device_function' => 'CORE_SWITCH',
    ]);

    $allDevices = $accessDevices->concat([$coreDevice]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'ASSIGN_DEVICE_FUNCTION',
        'deployment_time' => 1,
        'devices' => $allDevices->map(fn ($device) => ['id' => $device->id])->toArray(),
    ]);

    $response->assertSessionHasNoErrors();
    $task = $this->deployment->refresh()->tasks()->first();
    expect($task)->not()->toBeNull()
        ->and($task->task_type)->toBe('ASSIGN_DEVICE_FUNCTION')
        ->and($task->batch_id)->not()->toBeNull();

    Bus::assertBatchCount(1);
    Bus::assertBatched(function ($batch): bool {
        if ($batch->jobs->count() !== 3) {
            return false;
        }

        if (! $batch->jobs->every(fn ($job) => $job instanceof AssignDeviceFunctionJob)) {
            return false;
        }

        $deviceFunctions = $batch->jobs->map(fn ($job) => $job->device_function)->sort()->values()->all();

        return $deviceFunctions === ['ACCESS_SWITCH', 'ACCESS_SWITCH', 'CORE_SWITCH'];
    });
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
    DeviceInterface::query()->create(['device_id' => $device->id, 'interface' => '1/1/1', 'interface_kind' => InterfaceKind::ETHERNET]);
    DeviceInterface::query()->create(['device_id' => $device->id, 'interface' => '10', 'ip_address' => '10.0.0.1/24', 'interface_kind' => InterfaceKind::VLAN]);
    DeviceInterface::query()->create(['device_id' => $device->id, 'interface' => '11', 'lacp_profile_id' => $lacp->id, 'interface_kind' => InterfaceKind::LAG]);

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
        'interface_kind' => InterfaceKind::ETHERNET,
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

test('ASSIGN_SUBSCRIPTION stores licensing fields and dispatches subscription jobs', function () {
    Bus::fake();

    $this->client->update([
        'classic_base_url' => ClassicBaseUrl::US1,
        'classic_client_id' => 'classic-id',
        'classic_client_secret' => 'classic-secret',
        'classic_username' => 'user',
        'classic_password' => 'pass',
        'classic_refresh_token' => 'refresh',
        'classic_expires_in' => now()->addHour(),
        'classic_access_token' => 'access-token',
    ]);

    seedLicensingCache(
        $this->client,
        devices: [],
        subscriptions: [[
            'subscription_key' => 'KEY-POOL',
            'sku' => 'Q9Y65AAE',
            'license_type' => 'Advanced AP',
            'status' => 'OK',
            'available' => 10,
        ]],
    );

    $devices = Device::factory(2)->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'device_function' => 'CAMPUS_AP',
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'ASSIGN_SUBSCRIPTION',
        'deployment_time' => 3,
        'licensing_mode' => 'uniform',
        'subscription_key' => 'KEY-POOL',
        'devices' => $devices->map(fn ($device) => ['id' => $device->id])->toArray(),
    ]);

    $response->assertSessionHasNoErrors();
    $task = $this->deployment->refresh()->tasks()->first();

    expect($task)->not()->toBeNull()
        ->and($task->task_type)->toBe('ASSIGN_SUBSCRIPTION')
        ->and($task->licensing_service_name)->toBe('advanced_ap')
        ->and($task->licensing_subscription_key)->toBe('KEY-POOL')
        ->and($task->devices)->toHaveCount(2);

    Bus::assertBatchCount(1);
    Bus::assertBatched(function ($batch): bool {
        return $batch->jobs->count() === 1
            && $batch->jobs->first() instanceof AssignSubscriptionJob
            && $batch->jobs->first()->serviceName === 'advanced_ap';
    });

    $task->refresh();
    expect($task->batch_id)->not()->toBeNull();
});
