<?php

use App\BaseURL;
use App\InterfaceKind;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\SwitchPort;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = Client::factory()->for($this->user)->create([
        'current' => true,
        'base_url' => BaseURL::US1,
        'bearer_token' => 'test-bearer-token',
        'expires_at' => now()->addHour(),
    ]);
    $this->deployment = Deployment::factory()->for($this->client)->create();
    $this->device = Device::factory()->for($this->deployment)->create([
        'scope_id' => 'scope-123',
        'device_function' => 'ACCESS_SWITCH',
    ]);
    $this->actingAs($this->user);
});

/**
 * @return array{task: Task, deviceInterface: DeviceInterface, expectedCentralItem: array<string, mixed>}
 */
function createEthernetCentralCheckFixtures(Device $device, Deployment $deployment): array
{
    $switchPort = SwitchPort::factory()->create([
        'interface_mode' => 'ACCESS',
        'access_vlan' => 100,
        'native_vlan' => null,
        'trunk_vlan_all' => null,
        'trunk_vlan_ranges' => null,
    ]);
    $deviceInterface = DeviceInterface::factory()->create([
        'device_id' => $device->id,
        'interface' => '1/1/1',
        'switch_port_id' => $switchPort->id,
        'interface_kind' => InterfaceKind::ETHERNET,
        'description' => 'Access port',
        'shutdown_on_split' => false,
    ]);

    $task = Task::factory()->for($deployment)->create([
        'task_type' => 'CONFIGURE_ETHERNET_INTERFACE',
        'status' => 'COMPLETED',
    ]);
    $task->deviceInterfaces()->attach($deviceInterface->id, ['status' => 'COMPLETED']);

    $expectedCentralItem = [
        'name' => '1/1/1',
        'description' => 'Access port',
        'vsx' => ['shutdown-on-split' => false],
        'switchport' => [
            'access-vlan' => 100,
            'interface-mode' => 'ACCESS',
            'native-vlan' => null,
            'trunk-vlan-all' => null,
            'trunk-vlan-ranges' => null,
        ],
        'stp' => [],
    ];

    return [
        'task' => $task,
        'deviceInterface' => $deviceInterface,
        'expectedCentralItem' => $expectedCentralItem,
    ];
}

test('task ethernet central check reports success when Central matches expected config', function () {
    $fixtures = createEthernetCentralCheckFixtures($this->device, $this->deployment);

    Http::fake([
        '*ethernet-interfaces*' => Http::response([
            'interface' => [$fixtures['expectedCentralItem']],
        ], 200),
    ]);

    $this->get(route('tasks.check', $fixtures['task']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Task/Check')
            ->where('check_kind', 'ethernet')
            ->where('summary.passed', 1)
            ->where('summary.failed', 0)
            ->has('results', 1)
            ->where('results.0.ok', true)
            ->where('results.0.interface', '1/1/1'));
});

test('task ethernet central check reports mismatch when Central differs', function () {
    $fixtures = createEthernetCentralCheckFixtures($this->device, $this->deployment);
    $mismatched = $fixtures['expectedCentralItem'];
    $mismatched['switchport']['access-vlan'] = 200;

    Http::fake([
        '*ethernet-interfaces*' => Http::response([
            'interface' => [$mismatched],
        ], 200),
    ]);

    $this->get(route('tasks.check', $fixtures['task']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.failed', 1)
            ->where('results.0.ok', false)
            ->where('results.0.diff.0.path', 'switchport.access-vlan'));
});

test('task ethernet central check reports missing interface when not in Central', function () {
    $fixtures = createEthernetCentralCheckFixtures($this->device, $this->deployment);

    Http::fake([
        '*ethernet-interfaces*' => Http::response(['interface' => []], 200),
    ]);

    $this->get(route('tasks.check', $fixtures['task']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('results.0.ok', false)
            ->where('results.0.missing_in_central', true));
});

test('task ethernet central check returns 404 for unsupported task types', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'CONFIGURE_VLAN_INTERFACE',
        'status' => 'COMPLETED',
    ]);

    $this->get(route('tasks.check', $task))->assertNotFound();
});

test('task ethernet central check redirects when current client does not match deployment', function () {
    $otherClient = Client::factory()->for($this->user)->create(['current' => true]);
    $this->client->update(['current' => false]);

    $fixtures = createEthernetCentralCheckFixtures($this->device, $this->deployment);

    Http::fake([
        '*ethernet-interfaces*' => Http::response(['interface' => []], 200),
    ]);

    $this->get(route('tasks.check', $fixtures['task']))
        ->assertRedirect(route('tasks.index'))
        ->assertSessionHas('error');

    expect($otherClient->fresh()->current)->toBeTrue();
});

test('task index includes central check flags for ethernet tasks', function () {
    $fixtures = createEthernetCentralCheckFixtures($this->device, $this->deployment);

    $this->get(route('tasks.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1)
            ->where('tasks.data.0.id', $fixtures['task']->id)
            ->where('tasks.data.0.supports_central_check', true)
            ->where('tasks.data.0.can_run_central_check', true));
});
