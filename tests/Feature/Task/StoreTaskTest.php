<?php

use App\ClassicBaseUrl;
use App\InterfaceKind;
use App\Jobs\AddDevicesToGreenLakeInventoryJob;
use App\Jobs\AssignDeviceFunctionJob;
use App\Jobs\AssignSubscriptionJob;
use App\Jobs\ExportMacAddressesToCentralJob;
use App\Jobs\MoveDevicesToGroupJob;
use App\Jobs\PreprovisionDevicesToGroupJob;
use App\Jobs\UnassignSubscriptionJob;
use App\Models\Client;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\LacpProfile;
use App\Models\LicensingInventoryDevice;
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

test('PREPROVISION_DEVICE_TO_GROUP dispatches a single batch containing one job per group', function () {
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
        if ($batch->jobs->count() !== 1) {
            return false;
        }

        $job = $batch->jobs->first();

        return $job instanceof PreprovisionDevicesToGroupJob
            && count($job->device_chunks) === 2;
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

    $devices = Device::factory(2)->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'device_function' => 'CAMPUS_AP',
    ]);

    $this->client->update([
        'classic_base_url' => ClassicBaseUrl::US1,
        'classic_client_id' => 'classic-id',
        'classic_client_secret' => 'classic-secret',
        'classic_username' => 'user',
        'classic_password' => 'pass',
        'classic_refresh_token' => 'refresh',
        'classic_expires_in' => now()->addHour(),
        'classic_access_token' => 'access-token',
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
    ]);

    seedLicensingCache(
        $this->client,
        devices: $devices->map(fn ($device) => [
            'serial' => $device->serial,
            'model' => 'AP-515',
            'device_type' => 'IAP',
            'services' => [],
            'subscription_key' => '',
        ])->all(),
        subscriptions: [[
            'subscription_key' => 'KEY-POOL',
            'greenlake_subscription_id' => 'gl-sub-KEY-POOL',
            'sku' => 'Q9Y65AAE',
            'license_type' => 'Advanced AP',
            'status' => 'OK',
            'available' => 10,
            'tags' => ['pool-a'],
        ]],
    );

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'ASSIGN_SUBSCRIPTION',
        'deployment_time' => 3,
        'licensing_mode' => 'uniform',
        'license_tag' => 'pool-a',
        'license_type' => 'Advanced AP',
        'devices' => $devices->map(fn ($device) => ['id' => $device->id])->toArray(),
    ]);

    $response->assertSessionHasNoErrors();
    $task = $this->deployment->refresh()->tasks()->first();

    expect($task)->not()->toBeNull()
        ->and($task->task_type)->toBe('ASSIGN_SUBSCRIPTION')
        ->and($task->license_tag)->toBe('pool-a')
        ->and($task->license_type)->toBe('Advanced AP')
        ->and($task->devices)->toHaveCount(2);

    $firstDevice = $task->devices->first();
    expect($firstDevice->pivot->licensing_service_name)->toBe('gl-sub-KEY-POOL');

    Bus::assertBatchCount(1);
    Bus::assertBatched(function ($batch): bool {
        return $batch->jobs->count() === 1
            && $batch->jobs->first() instanceof AssignSubscriptionJob
            && $batch->jobs->first()->greenlakeSubscriptionId === 'gl-sub-KEY-POOL';
    });

    $task->refresh();
    expect($task->batch_id)->not()->toBeNull();
});

test('ASSIGN_SUBSCRIPTION rejects task when tag/type pool lacks seats', function () {
    Bus::fake();

    $devices = Device::factory(2)->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'device_function' => 'CAMPUS_AP',
    ]);

    $this->client->update([
        'classic_base_url' => ClassicBaseUrl::US1,
        'classic_client_id' => 'classic-id',
        'classic_client_secret' => 'classic-secret',
        'classic_username' => 'user',
        'classic_password' => 'pass',
        'classic_refresh_token' => 'refresh',
        'classic_expires_in' => now()->addHour(),
        'classic_access_token' => 'access-token',
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
    ]);

    seedLicensingCache(
        $this->client,
        devices: $devices->map(fn ($device) => [
            'serial' => $device->serial,
            'model' => 'AP-515',
            'device_type' => 'IAP',
            'services' => [],
            'subscription_key' => '',
        ])->all(),
        subscriptions: [[
            'subscription_key' => 'KEY-POOL',
            'greenlake_subscription_id' => 'gl-sub-KEY-POOL',
            'sku' => 'Q9Y65AAE',
            'license_type' => 'Advanced AP',
            'status' => 'OK',
            'available' => 1,
            'quantity' => 1,
            'tags' => ['pool-a'],
        ]],
    );

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'ASSIGN_SUBSCRIPTION',
        'deployment_time' => 3,
        'licensing_mode' => 'uniform',
        'license_tag' => 'pool-a',
        'license_type' => 'Advanced AP',
        'devices' => $devices->map(fn ($device) => ['id' => $device->id])->toArray(),
    ]);

    $response->assertSessionHasErrors('license_tag');
    expect($this->deployment->refresh()->tasks)->toHaveCount(0);
    Bus::assertNothingBatched();
});

test('UNASSIGN_SUBSCRIPTION stores licensing fields and dispatches unassign jobs', function () {
    Bus::fake();

    $devices = Device::factory(2)->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'device_function' => 'CAMPUS_AP',
    ]);

    $this->client->update([
        'classic_base_url' => ClassicBaseUrl::US1,
        'classic_client_id' => 'classic-id',
        'classic_client_secret' => 'classic-secret',
        'classic_username' => 'user',
        'classic_password' => 'pass',
        'classic_refresh_token' => 'refresh',
        'classic_expires_in' => now()->addHour(),
        'classic_access_token' => 'access-token',
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
    ]);

    seedLicensingCache(
        $this->client,
        devices: $devices->map(fn ($device) => [
            'serial' => $device->serial,
            'name' => $device->name,
            'model' => 'AP-515',
            'device_type' => 'IAP',
            'services' => ['advanced_ap'],
            'subscription_key' => 'KEY-POOL',
            'greenlake_device_id' => 'gl-dev-'.$device->serial,
        ])->all(),
        subscriptions: [[
            'subscription_key' => 'KEY-POOL',
            'greenlake_subscription_id' => 'gl-sub-KEY-POOL',
            'sku' => 'Q9Y65AAE',
            'license_type' => 'Advanced AP',
            'status' => 'OK',
            'available' => 0,
            'quantity' => 10,
            'tags' => ['pool-a'],
        ]],
    );

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'UNASSIGN_SUBSCRIPTION',
        'deployment_time' => 3,
        'devices' => $devices->map(fn ($device) => ['id' => $device->id])->toArray(),
    ]);

    $response->assertSessionHasNoErrors();
    $task = $this->deployment->refresh()->tasks()->first();

    expect($task)->not()->toBeNull()
        ->and($task->task_type)->toBe('UNASSIGN_SUBSCRIPTION')
        ->and($task->license_tag)->toBeNull()
        ->and($task->license_type)->toBeNull()
        ->and($task->devices)->toHaveCount(2);

    $firstDevice = $task->devices->first();
    expect($firstDevice->pivot->licensing_service_name)->toBe('gl-sub-KEY-POOL')
        ->and($firstDevice->pivot->license_tag)->toBe('pool-a')
        ->and($firstDevice->pivot->license_type)->toBe('Advanced AP');

    Bus::assertBatchCount(1);
    Bus::assertBatched(function ($batch): bool {
        return $batch->jobs->count() === 1
            && $batch->jobs->first() instanceof UnassignSubscriptionJob;
    });

    $task->refresh();
    expect($task->batch_id)->not()->toBeNull();
});

test('UNASSIGN_SUBSCRIPTION rejects device without assigned subscription', function () {
    Bus::fake();

    $devices = Device::factory(1)->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'device_function' => 'CAMPUS_AP',
    ]);

    $this->client->update([
        'classic_base_url' => ClassicBaseUrl::US1,
        'classic_client_id' => 'classic-id',
        'classic_client_secret' => 'classic-secret',
        'classic_username' => 'user',
        'classic_password' => 'pass',
        'classic_refresh_token' => 'refresh',
        'classic_expires_in' => now()->addHour(),
        'classic_access_token' => 'access-token',
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
    ]);

    seedLicensingCache(
        $this->client,
        devices: $devices->map(fn ($device) => [
            'serial' => $device->serial,
            'name' => $device->name,
            'model' => 'AP-515',
            'device_type' => 'IAP',
            'services' => [],
            'subscription_key' => '',
        ])->all(),
        subscriptions: [[
            'subscription_key' => 'KEY-POOL',
            'greenlake_subscription_id' => 'gl-sub-KEY-POOL',
            'sku' => 'Q9Y65AAE',
            'license_type' => 'Advanced AP',
            'status' => 'OK',
            'available' => 10,
            'tags' => ['pool-a'],
        ]],
    );

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'UNASSIGN_SUBSCRIPTION',
        'deployment_time' => 3,
        'devices' => $devices->map(fn ($device) => ['id' => $device->id])->toArray(),
    ]);

    $response->assertSessionHasErrors('devices');
    expect($this->deployment->refresh()->tasks)->toHaveCount(0);
    Bus::assertNothingBatched();
});

test('UNASSIGN_SUBSCRIPTION rejects device not linked in GreenLake', function () {
    Bus::fake();

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'device_function' => 'CAMPUS_AP',
    ]);

    $this->client->update([
        'licensing_synced_at' => now(),
        'licensing_sync_error' => null,
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
    ]);

    $this->client->clientSubscriptions()->create([
        'subscription_key' => 'KEY-POOL',
        'greenlake_subscription_id' => 'gl-sub-KEY-POOL',
        'subscription_sku' => 'Q9Y65AAE',
        'license_type' => 'Advanced AP',
        'status' => 'OK',
        'available' => 0,
        'tags' => ['pool-a'],
    ]);

    LicensingInventoryDevice::create([
        'client_id' => $this->client->id,
        'serial' => $device->serial,
        'greenlake_device_id' => '',
        'model' => 'AP-515',
        'device_type' => 'IAP',
        'name' => $device->name,
        'licensed' => true,
        'assigned_services' => ['advanced_ap'],
        'subscription_key' => 'KEY-POOL',
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'UNASSIGN_SUBSCRIPTION',
        'deployment_time' => 3,
        'devices' => [['id' => $device->id]],
    ]);

    $response->assertSessionHasErrors('devices');
    expect($this->deployment->refresh()->tasks)->toHaveCount(0);
    Bus::assertNothingBatched();
});

test('ADD_DEVICES_TO_GREENLAKE_INVENTORY rejects devices missing mac_address', function () {
    Bus::fake();

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'mac_address' => null,
        'serial' => 'MISSINGMAC001',
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'ADD_DEVICES_TO_GREENLAKE_INVENTORY',
        'deployment_time' => 3,
        'devices' => [['id' => $device->id]],
    ]);

    $response->assertSessionHasErrors('devices');
    expect($this->deployment->refresh()->tasks)->toHaveCount(0);
    Bus::assertNothingBatched();
});

test('ADD_DEVICES_TO_GREENLAKE_INVENTORY stores and dispatches chunked jobs', function () {
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
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
    ]);

    fakeLicensingCentralApis();

    $devices = Device::factory(6)->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'mac_address' => 'aa:bb:cc:dd:ee:ff',
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'ADD_DEVICES_TO_GREENLAKE_INVENTORY',
        'deployment_time' => 3,
        'devices' => $devices->map(fn ($device) => ['id' => $device->id])->toArray(),
    ]);

    $response->assertSessionHasNoErrors();
    $task = $this->deployment->refresh()->tasks()->first();

    expect($task)->not()->toBeNull()
        ->and($task->task_type)->toBe('ADD_DEVICES_TO_GREENLAKE_INVENTORY')
        ->and($task->devices)->toHaveCount(6)
        ->and($task->batch_id)->not()->toBeNull();

    Bus::assertBatchCount(1);
    Bus::assertBatched(function ($batch): bool {
        return $batch->jobs->count() === 2
            && $batch->jobs->every(fn ($job) => $job instanceof AddDevicesToGreenLakeInventoryJob);
    });
});

test('ADD_DEVICES_TO_GREENLAKE_INVENTORY excludes devices already in inventory', function () {
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
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
    ]);

    $alreadyInInventory = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'mac_address' => 'aa:bb:cc:dd:ee:01',
        'serial' => 'SN-ALREADY-001',
    ]);
    $needsAdd = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'mac_address' => 'aa:bb:cc:dd:ee:02',
        'serial' => 'SN-NEEDS-ADD-001',
    ]);

    fakeLicensingCentralApis(
        devices: [[
            'serial' => 'SN-ALREADY-001',
            'mac' => 'aa:bb:cc:dd:ee:01',
            'model' => 'AP-515',
            'device_type' => 'IAP',
            'name' => 'Already there',
            'licensed' => false,
        ]],
    );

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'ADD_DEVICES_TO_GREENLAKE_INVENTORY',
        'deployment_time' => 3,
        'devices' => [
            ['id' => $alreadyInInventory->id],
            ['id' => $needsAdd->id],
        ],
    ]);

    $response->assertSessionHasNoErrors();
    $task = $this->deployment->refresh()->tasks()->first();

    expect($task)->not()->toBeNull()
        ->and($task->devices)->toHaveCount(1)
        ->and($task->devices->first()->id)->toBe($needsAdd->id);

    Bus::assertBatchCount(1);
});

test('ADD_DEVICES_TO_GREENLAKE_INVENTORY rejects when all selected devices are already in inventory', function () {
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
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
    ]);

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'mac_address' => 'aa:bb:cc:dd:ee:ff',
        'serial' => 'SN-PRESENT-001',
    ]);

    fakeLicensingCentralApis(
        devices: [[
            'serial' => 'SN-PRESENT-001',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'model' => 'AP-515',
            'device_type' => 'IAP',
            'licensed' => false,
        ]],
    );

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'ADD_DEVICES_TO_GREENLAKE_INVENTORY',
        'deployment_time' => 3,
        'devices' => [['id' => $device->id]],
    ]);

    $response->assertSessionHasErrors('devices');
    expect($this->deployment->refresh()->tasks)->toHaveCount(0);
    Bus::assertNothingBatched();
});

test('ADD_DEVICES_TO_GREENLAKE_INVENTORY stores normalized greenlake_tags', function () {
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
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
    ]);

    fakeLicensingCentralApis();

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'mac_address' => 'aa:bb:cc:dd:ee:ff',
        'serial' => 'SN-TAGS-001',
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'ADD_DEVICES_TO_GREENLAKE_INVENTORY',
        'deployment_time' => 3,
        'devices' => [['id' => $device->id]],
        'tags' => [
            ['key' => 'Environment', 'value' => 'prod'],
            ['key' => 'Site', 'value' => ''],
            ['key' => '  ', 'value' => 'ignored'],
            ['key' => 'Owner'],
        ],
    ]);

    $response->assertSessionHasNoErrors();
    $task = $this->deployment->refresh()->tasks()->first();

    expect($task)->not()->toBeNull()
        ->and($task->greenlake_tags)->toBe([
            'Environment' => 'prod',
            'Site' => '',
            'Owner' => '',
        ]);
});

test('ADD_DEVICES_TO_GREENLAKE_INVENTORY rejects duplicate tag keys', function () {
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
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
    ]);

    fakeLicensingCentralApis();

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'mac_address' => 'aa:bb:cc:dd:ee:ff',
        'serial' => 'SN-DUP-TAG-001',
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'ADD_DEVICES_TO_GREENLAKE_INVENTORY',
        'deployment_time' => 3,
        'devices' => [['id' => $device->id]],
        'tags' => [
            ['key' => 'Environment', 'value' => 'prod'],
            ['key' => 'Environment', 'value' => 'dev'],
        ],
    ]);

    $response->assertSessionHasErrors('tags.1.key');
    expect($this->deployment->refresh()->tasks)->toHaveCount(0);
    Bus::assertNothingBatched();
});

test('ADD_DEVICES_TO_GREENLAKE_INVENTORY stores greenlake location id and name', function () {
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
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
    ]);

    fakeLicensingCentralApis(
        locations: [
            ['id' => 'loc-nyc', 'name' => 'Warehouse NYC'],
        ],
    );

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'mac_address' => 'aa:bb:cc:dd:ee:ff',
        'serial' => 'SN-LOC-001',
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'ADD_DEVICES_TO_GREENLAKE_INVENTORY',
        'deployment_time' => 3,
        'devices' => [['id' => $device->id]],
        'greenlake_location_id' => 'loc-nyc',
    ]);

    $response->assertSessionHasNoErrors();
    $task = $this->deployment->refresh()->tasks()->first();

    expect($task)->not()->toBeNull()
        ->and($task->greenlake_location_id)->toBe('loc-nyc')
        ->and($task->greenlake_location_name)->toBe('Warehouse NYC');
});

test('ADD_DEVICES_TO_GREENLAKE_INVENTORY rejects unknown greenlake location id', function () {
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
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
    ]);

    fakeLicensingCentralApis(
        locations: [
            ['id' => 'loc-nyc', 'name' => 'Warehouse NYC'],
        ],
    );

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'mac_address' => 'aa:bb:cc:dd:ee:ff',
        'serial' => 'SN-LOC-UNKNOWN',
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'ADD_DEVICES_TO_GREENLAKE_INVENTORY',
        'deployment_time' => 3,
        'devices' => [['id' => $device->id]],
        'greenlake_location_id' => 'loc-missing',
    ]);

    $response->assertSessionHasErrors('greenlake_location_id');
    expect($this->deployment->refresh()->tasks)->toHaveCount(0);
    Bus::assertNothingBatched();
});

test('ADD_TAGS_TO_GREENLAKE_DEVICES stores tags and attaches inventory devices', function () {
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
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
    ]);

    $inInventory = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'SN-TAG-IN-001',
    ]);
    $notInInventory = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'SN-TAG-OUT-001',
    ]);

    fakeLicensingCentralApis(
        devices: [[
            'serial' => 'SN-TAG-IN-001',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'model' => 'AP-515',
            'device_type' => 'IAP',
            'licensed' => false,
            'greenlake_device_id' => 'gl-tag-dev-1',
        ]],
    );

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'ADD_TAGS_TO_GREENLAKE_DEVICES',
        'deployment_time' => 3,
        'devices' => [
            ['id' => $inInventory->id],
            ['id' => $notInInventory->id],
        ],
        'tags' => [
            ['key' => 'Environment', 'value' => 'prod'],
            ['key' => 'Owner', 'value' => ''],
        ],
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect();

    $task = $this->deployment->refresh()->tasks()->first();

    expect($task)->not()->toBeNull()
        ->and($task->task_type)->toBe('ADD_TAGS_TO_GREENLAKE_DEVICES')
        ->and($task->greenlake_tags)->toBe([
            'Environment' => 'prod',
            'Owner' => '',
        ])
        ->and($task->devices)->toHaveCount(1)
        ->and($task->devices->first()->id)->toBe($inInventory->id);

    Bus::assertBatchCount(1);
});

test('ADD_TAGS_TO_GREENLAKE_DEVICES rejects when no tags are provided', function () {
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
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
    ]);

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'SN-TAG-NONE-001',
    ]);

    fakeLicensingCentralApis(
        devices: [[
            'serial' => 'SN-TAG-NONE-001',
            'mac' => 'aa:bb:cc:dd:ee:01',
            'model' => 'AP-515',
            'device_type' => 'IAP',
            'licensed' => false,
        ]],
    );

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'ADD_TAGS_TO_GREENLAKE_DEVICES',
        'deployment_time' => 3,
        'devices' => [['id' => $device->id]],
        'tags' => [],
    ]);

    $response->assertSessionHasErrors('tags');
    expect($this->deployment->refresh()->tasks)->toHaveCount(0);
    Bus::assertNothingBatched();
});

test('ADD_TAGS_TO_GREENLAKE_DEVICES rejects when none of the selected devices are in inventory', function () {
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
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
    ]);

    fakeLicensingCentralApis();

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'SN-TAG-MISSING-001',
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'ADD_TAGS_TO_GREENLAKE_DEVICES',
        'deployment_time' => 3,
        'devices' => [['id' => $device->id]],
        'tags' => [
            ['key' => 'Environment', 'value' => 'prod'],
        ],
    ]);

    $response->assertSessionHasErrors('devices');
    expect($this->deployment->refresh()->tasks)->toHaveCount(0);
    Bus::assertNothingBatched();
});

test('ADD_LOCATION_TO_GREENLAKE_DEVICES stores location and attaches inventory devices', function () {
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
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
    ]);

    $inInventory = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'SN-LOC-IN-001',
    ]);
    $notInInventory = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'SN-LOC-OUT-001',
    ]);

    fakeLicensingCentralApis(
        devices: [[
            'serial' => 'SN-LOC-IN-001',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'model' => 'AP-515',
            'device_type' => 'IAP',
            'licensed' => false,
            'greenlake_device_id' => 'gl-loc-dev-1',
        ]],
        locations: [
            ['id' => 'loc-nyc', 'name' => 'Warehouse NYC'],
        ],
    );

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'ADD_LOCATION_TO_GREENLAKE_DEVICES',
        'deployment_time' => 3,
        'devices' => [
            ['id' => $inInventory->id],
            ['id' => $notInInventory->id],
        ],
        'greenlake_location_id' => 'loc-nyc',
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect();

    $task = $this->deployment->refresh()->tasks()->first();

    expect($task)->not()->toBeNull()
        ->and($task->task_type)->toBe('ADD_LOCATION_TO_GREENLAKE_DEVICES')
        ->and($task->greenlake_location_id)->toBe('loc-nyc')
        ->and($task->greenlake_location_name)->toBe('Warehouse NYC')
        ->and($task->devices)->toHaveCount(1)
        ->and($task->devices->first()->id)->toBe($inInventory->id);

    Bus::assertBatchCount(1);
});

test('ADD_LOCATION_TO_GREENLAKE_DEVICES rejects when no location is provided', function () {
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
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
    ]);

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'SN-LOC-NONE-001',
    ]);

    fakeLicensingCentralApis(
        devices: [[
            'serial' => 'SN-LOC-NONE-001',
            'mac' => 'aa:bb:cc:dd:ee:01',
            'model' => 'AP-515',
            'device_type' => 'IAP',
            'licensed' => false,
            'greenlake_device_id' => 'gl-loc-none-1',
        ]],
        locations: [
            ['id' => 'loc-nyc', 'name' => 'Warehouse NYC'],
        ],
    );

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'ADD_LOCATION_TO_GREENLAKE_DEVICES',
        'deployment_time' => 3,
        'devices' => [['id' => $device->id]],
    ]);

    $response->assertSessionHasErrors('greenlake_location_id');
    expect($this->deployment->refresh()->tasks)->toHaveCount(0);
    Bus::assertNothingBatched();
});

test('ADD_LOCATION_TO_GREENLAKE_DEVICES rejects when none of the selected devices are in inventory', function () {
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
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
    ]);

    fakeLicensingCentralApis(
        locations: [
            ['id' => 'loc-nyc', 'name' => 'Warehouse NYC'],
        ],
    );

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'SN-LOC-MISSING-001',
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'ADD_LOCATION_TO_GREENLAKE_DEVICES',
        'deployment_time' => 3,
        'devices' => [['id' => $device->id]],
        'greenlake_location_id' => 'loc-nyc',
    ]);

    $response->assertSessionHasErrors('devices');
    expect($this->deployment->refresh()->tasks)->toHaveCount(0);
    Bus::assertNothingBatched();
});

test('EXPORT_MAC_ADDRESSES_TO_CENTRAL stores central_static_tags and dispatches job', function () {
    Bus::fake();

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'mac_address' => 'aa:bb:cc:dd:ee:ff',
        'serial' => 'SN-MAC-EXPORT-001',
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'EXPORT_MAC_ADDRESSES_TO_CENTRAL',
        'deployment_time' => 3,
        'devices' => [['id' => $device->id]],
        'static_tags' => ['BVSD-AP', '  BVSD-VOICE  ', '', 'BVSD-AP'],
    ]);

    $response->assertSessionHasNoErrors();
    $task = $this->deployment->refresh()->tasks()->first();

    expect($task)->not()->toBeNull()
        ->and($task->task_type)->toBe('EXPORT_MAC_ADDRESSES_TO_CENTRAL')
        ->and($task->central_static_tags)->toBe(['BVSD-AP', 'BVSD-VOICE'])
        ->and($task->batch_id)->not()->toBeNull();

    Bus::assertBatchCount(1);
    Bus::assertBatched(function ($batch) use ($device): bool {
        if ($batch->jobs->count() !== 1) {
            return false;
        }

        $job = $batch->jobs->first();

        return $job instanceof ExportMacAddressesToCentralJob
            && count($job->devices) === 1
            && (int) $job->devices[0]['id'] === $device->id
            && $job->devices[0]['mac_address'] === 'aa:bb:cc:dd:ee:ff';
    });
});

test('EXPORT_MAC_ADDRESSES_TO_CENTRAL rejects devices missing mac_address', function () {
    Bus::fake();

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'mac_address' => null,
        'serial' => 'SN-MAC-MISSING-001',
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'EXPORT_MAC_ADDRESSES_TO_CENTRAL',
        'deployment_time' => 3,
        'devices' => [['id' => $device->id]],
        'static_tags' => ['BVSD-AP'],
    ]);

    $response->assertSessionHasErrors('devices');
    expect($this->deployment->refresh()->tasks)->toHaveCount(0);
    Bus::assertNothingBatched();
});

test('ADD_DEVICES_TO_GREENLAKE_INVENTORY stores greenlake application id region and name', function () {
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
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
    ]);

    fakeLicensingCentralApis(
        locations: [],
        serviceManagers: [
            ['id' => 'app-central', 'name' => 'Aruba Central'],
        ],
        serviceManagerProvisions: [
            [
                'id' => 'prov-1',
                'serviceManager' => ['id' => 'app-central'],
                'region' => 'us-west',
                'provisionStatus' => 'PROVISIONED',
            ],
        ],
    );

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'mac_address' => 'aa:bb:cc:dd:ee:ff',
        'serial' => 'SN-SVC-001',
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'ADD_DEVICES_TO_GREENLAKE_INVENTORY',
        'deployment_time' => 3,
        'devices' => [['id' => $device->id]],
        'greenlake_application_id' => 'app-central',
        'greenlake_application_region' => 'us-west',
    ]);

    $response->assertSessionHasNoErrors();
    $task = $this->deployment->refresh()->tasks()->first();

    expect($task)->not()->toBeNull()
        ->and($task->greenlake_application_id)->toBe('app-central')
        ->and($task->greenlake_application_region)->toBe('us-west')
        ->and($task->greenlake_application_name)->toBe('Aruba Central (us-west)')
        ->and($task->applicableGreenLakeSteps())->toBe(['inventory', 'service']);
});

test('ADD_DEVICES_TO_GREENLAKE_INVENTORY rejects unknown greenlake application pair', function () {
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
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
    ]);

    fakeLicensingCentralApis(
        locations: [],
        serviceManagers: [
            ['id' => 'app-central', 'name' => 'Aruba Central'],
        ],
        serviceManagerProvisions: [
            [
                'id' => 'prov-1',
                'serviceManager' => ['id' => 'app-central'],
                'region' => 'us-west',
                'provisionStatus' => 'PROVISIONED',
            ],
        ],
    );

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'mac_address' => 'aa:bb:cc:dd:ee:ff',
        'serial' => 'SN-SVC-UNKNOWN',
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'ADD_DEVICES_TO_GREENLAKE_INVENTORY',
        'deployment_time' => 3,
        'devices' => [['id' => $device->id]],
        'greenlake_application_id' => 'app-central',
        'greenlake_application_region' => 'eu-central',
    ]);

    $response->assertSessionHasErrors('greenlake_application_id');
    expect($this->deployment->refresh()->tasks)->toHaveCount(0);
    Bus::assertNothingBatched();
});

test('greenlake service regions endpoint returns provisioned services for deployment client', function () {
    $this->client->update([
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
    ]);

    fakeLicensingCentralApis(
        serviceManagers: [
            ['id' => 'app-central', 'name' => 'Aruba Central'],
        ],
        serviceManagerProvisions: [
            [
                'id' => 'prov-1',
                'serviceManager' => ['id' => 'app-central'],
                'region' => 'us-west',
                'provisionStatus' => 'PROVISIONED',
            ],
        ],
    );

    $response = $this->getJson(route('tasks.greenlake_service_regions', $this->deployment));

    $response->assertOk()
        ->assertJson([
            'service_regions' => [
                [
                    'application_id' => 'app-central',
                    'region' => 'us-west',
                    'name' => 'Aruba Central (us-west)',
                ],
            ],
        ]);
});

test('ASSIGN_SERVICE_TO_GREENLAKE_DEVICES stores application fields and attaches inventory devices', function () {
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
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
    ]);

    $inInventory = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'SN-SVC-IN-001',
    ]);
    $notInInventory = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'SN-SVC-OUT-001',
    ]);

    fakeLicensingCentralApis(
        devices: [[
            'serial' => 'SN-SVC-IN-001',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'model' => 'AP-515',
            'device_type' => 'IAP',
            'licensed' => false,
            'greenlake_device_id' => 'gl-svc-dev-1',
        ]],
        serviceManagers: [
            ['id' => 'app-central', 'name' => 'Aruba Central'],
        ],
        serviceManagerProvisions: [
            [
                'id' => 'prov-1',
                'serviceManager' => ['id' => 'app-central'],
                'region' => 'us-west',
                'provisionStatus' => 'PROVISIONED',
            ],
        ],
    );

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'ASSIGN_SERVICE_TO_GREENLAKE_DEVICES',
        'deployment_time' => 3,
        'devices' => [
            ['id' => $inInventory->id],
            ['id' => $notInInventory->id],
        ],
        'greenlake_application_id' => 'app-central',
        'greenlake_application_region' => 'us-west',
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect();

    $task = $this->deployment->refresh()->tasks()->first();

    expect($task)->not()->toBeNull()
        ->and($task->task_type)->toBe('ASSIGN_SERVICE_TO_GREENLAKE_DEVICES')
        ->and($task->greenlake_application_id)->toBe('app-central')
        ->and($task->greenlake_application_region)->toBe('us-west')
        ->and($task->greenlake_application_name)->toBe('Aruba Central (us-west)')
        ->and($task->devices)->toHaveCount(1)
        ->and($task->devices->first()->id)->toBe($inInventory->id);

    Bus::assertBatchCount(1);
});

test('ASSIGN_SERVICE_TO_GREENLAKE_DEVICES rejects when no service is provided', function () {
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
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
    ]);

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'SN-SVC-NONE-001',
    ]);

    fakeLicensingCentralApis(
        devices: [[
            'serial' => 'SN-SVC-NONE-001',
            'mac' => 'aa:bb:cc:dd:ee:01',
            'model' => 'AP-515',
            'device_type' => 'IAP',
            'licensed' => false,
            'greenlake_device_id' => 'gl-svc-none-1',
        ]],
        serviceManagers: [
            ['id' => 'app-central', 'name' => 'Aruba Central'],
        ],
        serviceManagerProvisions: [
            [
                'id' => 'prov-1',
                'serviceManager' => ['id' => 'app-central'],
                'region' => 'us-west',
                'provisionStatus' => 'PROVISIONED',
            ],
        ],
    );

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'ASSIGN_SERVICE_TO_GREENLAKE_DEVICES',
        'deployment_time' => 3,
        'devices' => [['id' => $device->id]],
    ]);

    $response->assertSessionHasErrors('greenlake_application_id');
    expect($this->deployment->refresh()->tasks)->toHaveCount(0);
    Bus::assertNothingBatched();
});

test('ASSIGN_SERVICE_TO_GREENLAKE_DEVICES rejects unknown service pair', function () {
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
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
    ]);

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'SN-SVC-UNKNOWN-001',
    ]);

    fakeLicensingCentralApis(
        devices: [[
            'serial' => 'SN-SVC-UNKNOWN-001',
            'mac' => 'aa:bb:cc:dd:ee:02',
            'model' => 'AP-515',
            'device_type' => 'IAP',
            'licensed' => false,
            'greenlake_device_id' => 'gl-svc-unknown-1',
        ]],
        serviceManagers: [
            ['id' => 'app-central', 'name' => 'Aruba Central'],
        ],
        serviceManagerProvisions: [
            [
                'id' => 'prov-1',
                'serviceManager' => ['id' => 'app-central'],
                'region' => 'us-west',
                'provisionStatus' => 'PROVISIONED',
            ],
        ],
    );

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'ASSIGN_SERVICE_TO_GREENLAKE_DEVICES',
        'deployment_time' => 3,
        'devices' => [['id' => $device->id]],
        'greenlake_application_id' => 'app-central',
        'greenlake_application_region' => 'eu-central',
    ]);

    $response->assertSessionHasErrors('greenlake_application_id');
    expect($this->deployment->refresh()->tasks)->toHaveCount(0);
    Bus::assertNothingBatched();
});

test('ASSIGN_SERVICE_TO_GREENLAKE_DEVICES rejects when none of the selected devices are in inventory', function () {
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
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
    ]);

    fakeLicensingCentralApis(
        serviceManagers: [
            ['id' => 'app-central', 'name' => 'Aruba Central'],
        ],
        serviceManagerProvisions: [
            [
                'id' => 'prov-1',
                'serviceManager' => ['id' => 'app-central'],
                'region' => 'us-west',
                'provisionStatus' => 'PROVISIONED',
            ],
        ],
    );

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'SN-SVC-MISSING-001',
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'ASSIGN_SERVICE_TO_GREENLAKE_DEVICES',
        'deployment_time' => 3,
        'devices' => [['id' => $device->id]],
        'greenlake_application_id' => 'app-central',
        'greenlake_application_region' => 'us-west',
    ]);

    $response->assertSessionHasErrors('devices');
    expect($this->deployment->refresh()->tasks)->toHaveCount(0);
    Bus::assertNothingBatched();
});
