<?php

use App\ClassicBaseUrl;
use App\InterfaceKind;
use App\Jobs\AddVlansToDeviceGroup;
use App\Models\Client;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\LacpProfile;
use App\Models\Task;
use App\Models\User;
use App\SwitchSKU;
use Illuminate\Bus\ChainedBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function configureClientForClassicCentral(Client $client): void
{
    $client->update([
        'classic_base_url' => ClassicBaseUrl::US1->value,
        'classic_client_id' => 'classic-id',
        'classic_client_secret' => 'classic-secret',
        'classic_username' => 'user',
        'classic_password' => 'pass',
        'classic_refresh_token' => 'refresh',
        'classic_expires_in' => now()->addHour(),
        'classic_access_token' => 'access-token',
    ]);
}

function fakeClassicCentralGroupListPages(array $firstPageRow): void
{
    Http::fake([
        '*configuration/v2/groups*' => Http::sequence()
            ->push(['data' => [$firstPageRow]], 200)
            ->push(['data' => []], 200),
    ]);
}

beforeEach(function () {
    $this->user = User::factory()
        ->has(Client::factory())
        ->create();
    $this->client = $this->user->clients()->first();
    $this->client->update(['current' => true]);
    $this->deployment = $this->client->deployments()->create(['name' => 'Test Deployment']);
    $this->actingAs($this->user);
});

test('creating REMOVE_VSF_PROFILE_LOCAL_OVERRIDES stores five composite sibling tasks', function () {
    Bus::fake();

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
    expect($tasks)->toHaveCount(5);
    expect($tasks->pluck('composite_group_id')->unique())->toHaveCount(1);
    expect($tasks->every(fn (Task $t) => $t->composite_kind === 'REMOVE_VSF_PROFILE_LOCAL_OVERRIDES'))->toBeTrue();
    expect($tasks->pluck('task_type')->all())->toBe([
        'REMOVE_LOCAL_OVERRIDE_VLANS',
        'REMOVE_LOCAL_OVERRIDE_DNS_PROFILE',
        'REMOVE_LOCAL_OVERRIDE_STATIC_ROUTE',
        'REMOVE_LOCAL_OVERRIDE_NTP_PROFILE',
        'REMOVE_LOCAL_OVERRIDE_LOCAL_MANAGEMENT_PROFILE',
    ]);
    expect($tasks->pluck('override_device_scope')->unique()->values()->all())->toBe(['vsf_only']);
    expect($tasks->pluck('job_queue')->unique())->toHaveCount(1);
    expect($tasks->first()->job_queue)->toMatch('/^q\d+$/');

    $first = $tasks->firstWhere('composite_order', 1);
    expect($first)->not->toBeNull();
    $response->assertRedirect(route('tasks.show', $first));
});

test('creating REMOVE_VSF_PROFILE_LOCAL_OVERRIDES with all device scope stores override_device_scope on siblings', function () {
    Bus::fake();

    $devices = Device::factory(1)->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'REMOVE_VSF_PROFILE_LOCAL_OVERRIDES',
        'deployment_time' => 1,
        'override_device_scope' => 'all',
        'devices' => $devices->map(fn ($device) => ['id' => $device->id])->toArray(),
    ]);

    $response->assertSessionHasNoErrors();
    $tasks = $this->deployment->refresh()->tasks()->orderBy('composite_order')->get();
    expect($tasks)->toHaveCount(5);
    expect($tasks->pluck('override_device_scope')->unique()->values()->all())->toBe(['all']);
});

test('creating REMOVE_VSF_PROFILE_LOCAL_OVERRIDES with vsf_only marks non-SKU devices completed on attach', function () {
    Bus::fake();

    $withSku = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'sku' => SwitchSKU::JL658A->name,
    ]);
    $withoutSku = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'sku' => null,
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'REMOVE_VSF_PROFILE_LOCAL_OVERRIDES',
        'deployment_time' => 1,
        'override_device_scope' => 'vsf_only',
        'devices' => [
            ['id' => $withSku->id],
            ['id' => $withoutSku->id],
        ],
    ]);

    $response->assertSessionHasNoErrors();
    $task = $this->deployment->refresh()->tasks()->firstWhere('composite_order', 1);
    expect($task)->not->toBeNull();
    expect($task->devices()->where('devices.id', $withSku->id)->first()->pivot->status)->toBe('PENDING');
    expect($task->devices()->where('devices.id', $withoutSku->id)->first()->pivot->status)->toBe('COMPLETED');
});

test('creating CONFIGURE_ALL_INTERFACE stores three composite sibling tasks', function () {
    Bus::fake();

    $devices = Device::factory(1)->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
    ]);
    $device = $devices->first();
    $lacp = LacpProfile::query()->create([
        'mode' => 'ACTIVE',
        'rate' => 'SLOW',
        'trunk_type' => 'LACP',
        'port_list' => '1/1/1-1/1/2',
    ]);
    DeviceInterface::query()->create([
        'device_id' => $device->id,
        'interface' => '1/1/1',
        'interface_kind' => InterfaceKind::ETHERNET,
    ]);
    DeviceInterface::query()->create([
        'device_id' => $device->id,
        'interface' => '10',
        'ip_address' => '10.10.10.1/24',
        'interface_kind' => InterfaceKind::VLAN,
    ]);
    DeviceInterface::query()->create([
        'device_id' => $device->id,
        'interface' => '11',
        'lacp_profile_id' => $lacp->id,
        'interface_kind' => InterfaceKind::LAG,
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
    expect($tasks->pluck('job_queue')->unique())->toHaveCount(1);
    expect($tasks->first()->job_queue)->toMatch('/^q\d+$/');

    $first = $tasks->firstWhere('composite_order', 1);
    expect($first)->not->toBeNull();
    $response->assertRedirect(route('tasks.show', $first));
});

test('creating CONFIGURE_ALL_INTERFACE stores only eligible composite sibling tasks', function () {
    Bus::fake();

    $devices = Device::factory(1)->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
    ]);
    $device = $devices->first();

    DeviceInterface::query()->create([
        'device_id' => $device->id,
        'interface' => '1/1/1',
        'interface_kind' => InterfaceKind::ETHERNET,
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'CONFIGURE_ALL_INTERFACE',
        'deployment_time' => 1,
        'devices' => $devices->map(fn ($d) => ['id' => $d->id])->toArray(),
    ]);

    $response->assertSessionHasNoErrors();
    $tasks = $this->deployment->refresh()->tasks()->orderBy('composite_order')->get();
    expect($tasks)->toHaveCount(1);
    expect($tasks->pluck('task_type')->all())->toBe([
        'CONFIGURE_ETHERNET_INTERFACE',
    ]);
});

test('creating CONFIGURE_ALL_INTERFACE with no matching interfaces returns error and creates no tasks', function () {
    Bus::fake();

    $devices = Device::factory(1)->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
    ]);

    $response = $this->from(route('deployments.show', $this->deployment))
        ->post(route('tasks.store', $this->deployment), [
            'task_type' => 'CONFIGURE_ALL_INTERFACE',
            'deployment_time' => 1,
            'devices' => $devices->map(fn ($d) => ['id' => $d->id])->toArray(),
        ]);

    $response->assertRedirect(route('deployments.show', $this->deployment));
    $response->assertSessionHasErrors('devices');
    expect($this->deployment->refresh()->tasks)->toHaveCount(0);
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
        ['REMOVE_LOCAL_OVERRIDE_LOCAL_MANAGEMENT_PROFILE', 5],
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
            ->has('sub_jobs', 5)
            ->has('sub_jobs.0', fn (Assert $job) => $job
                ->where('task_type', 'REMOVE_LOCAL_OVERRIDE_VLANS')
                ->where('friendly_label', 'Remove local VLAN overrides')
                ->where('total_count', 1)
                ->etc())
        );
});

test('creating ADD_VLANS_TO_DEVICE_GROUP stores one composite sub-task per unique device group', function () {
    Bus::fake();
    configureClientForClassicCentral($this->client);
    fakeClassicCentralGroupListPages(['Site-A-CORE', 'Site-A-ACCESS']);

    $d1 = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'group' => 'Site-A-CORE',
    ]);
    $d2 = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'group' => 'Site-A-ACCESS',
    ]);
    $d3 = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'group' => 'Site-A-CORE',
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'ADD_VLANS_TO_DEVICE_GROUP',
        'deployment_time' => 1,
        'devices' => [
            ['id' => $d1->id],
            ['id' => $d2->id],
            ['id' => $d3->id],
        ],
        'vlan_site_prefix' => '',
    ]);

    $response->assertSessionHasNoErrors();
    $tasks = $this->deployment->refresh()->tasks()->orderBy('composite_order')->get();
    expect($tasks)->toHaveCount(2);
    expect($tasks->pluck('composite_group_id')->unique())->toHaveCount(1);
    expect($tasks->every(fn (Task $t) => $t->composite_kind === 'ADD_VLANS_TO_DEVICE_GROUP'))->toBeTrue();
    expect($tasks->pluck('task_type')->unique()->values()->all())->toBe(['ADD_VLANS_FOR_DEVICE_GROUP']);

    $groups = $tasks->pluck('vlan_target_device_group')->sort()->values()->all();
    expect($groups)->toBe(['Site-A-ACCESS', 'Site-A-CORE']);

    expect($tasks->firstWhere('vlan_target_device_group', 'Site-A-CORE')?->devices)->toHaveCount(2);
    expect($tasks->firstWhere('vlan_target_device_group', 'Site-A-ACCESS')?->devices)->toHaveCount(1);

    Bus::assertBatchCount(2);
    Bus::assertBatched(function ($batch): bool {
        return $batch->jobs->count() === 1 && $batch->jobs->first() instanceof AddVlansToDeviceGroup;
    });
});

test('creating ADD_VLANS_TO_DEVICE_GROUP with site prefix stores five sub-tasks without device pivots', function () {
    Bus::fake();
    configureClientForClassicCentral($this->client);
    fakeClassicCentralGroupListPages([
        'WHSE-SAC-ACCESS',
        'WHSE-SAC-CORE',
        'WHSE-SAC-MGMT',
        'WHSE-SAC-DMZ',
        'WHSE-SAC-SERVER',
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'ADD_VLANS_TO_DEVICE_GROUP',
        'deployment_time' => 1,
        'devices' => [],
        'vlan_site_prefix' => 'SAC',
    ]);

    $response->assertSessionHasNoErrors();
    $tasks = $this->deployment->refresh()->tasks()->orderBy('composite_order')->get();
    expect($tasks)->toHaveCount(5);
    expect($tasks->pluck('vlan_target_device_group')->all())->toBe([
        'WHSE-SAC-ACCESS',
        'WHSE-SAC-CORE',
        'WHSE-SAC-MGMT',
        'WHSE-SAC-DMZ',
        'WHSE-SAC-SERVER',
    ]);
    expect($tasks->every(fn (Task $t) => $t->devices()->count() === 0))->toBeTrue();

    Bus::assertBatchCount(5);
});

test('creating ADD_VLANS_TO_DEVICE_GROUP adds prerequisite create task when group is missing in Central', function () {
    Bus::fake();
    configureClientForClassicCentral($this->client);
    fakeClassicCentralGroupListPages(['SomeOtherGroup']);

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'group' => 'MissingInCentral',
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'ADD_VLANS_TO_DEVICE_GROUP',
        'deployment_time' => 1,
        'devices' => [['id' => $device->id]],
        'vlan_site_prefix' => '',
    ]);

    $response->assertSessionHasNoErrors();
    $tasks = $this->deployment->refresh()->tasks()->orderBy('composite_order')->get();
    expect($tasks)->toHaveCount(2);
    expect($tasks->pluck('task_type')->all())->toBe([
        'CREATE_NEW_CENTRAL_CX_GROUP',
        'ADD_VLANS_FOR_DEVICE_GROUP',
    ]);

    $vlanTask = $tasks->firstWhere('task_type', 'ADD_VLANS_FOR_DEVICE_GROUP');
    $createTask = $tasks->firstWhere('task_type', 'CREATE_NEW_CENTRAL_CX_GROUP');
    expect($vlanTask)->not->toBeNull();
    expect($createTask)->not->toBeNull();
    expect($vlanTask->central_group_creation_task_id)->toBe($createTask->id);
    expect($createTask->devices)->toHaveCount(1);

    Bus::assertDispatchedTimes(ChainedBatch::class, 1);
});

test('force_restart ADD_VLANS_TO_DEVICE_GROUP does not standalone-dispatch CREATE_NEW_CENTRAL_CX_GROUP rows', function () {
    Bus::fake();
    configureClientForClassicCentral($this->client);
    fakeClassicCentralGroupListPages(['SomeOtherGroup']);

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'group' => 'MissingInCentral',
    ]);

    $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'ADD_VLANS_TO_DEVICE_GROUP',
        'deployment_time' => 1,
        'devices' => [['id' => $device->id]],
        'vlan_site_prefix' => '',
    ])->assertSessionHasNoErrors();

    Bus::assertDispatchedTimes(ChainedBatch::class, 1);

    $vlanTask = Task::query()
        ->where('deployment_id', $this->deployment->id)
        ->where('task_type', 'ADD_VLANS_FOR_DEVICE_GROUP')
        ->firstOrFail();

    $this->post(route('tasks.force_restart', $vlanTask))->assertRedirect();

    Bus::assertDispatchedTimes(ChainedBatch::class, 2);
});
