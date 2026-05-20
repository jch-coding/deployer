<?php

use App\BaseURL;
use App\InterfaceKind;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\LacpProfile;
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
function createLagCentralCheckFixtures(Device $device, Deployment $deployment): array
{
    $lacpProfile = LacpProfile::factory()->create([
        'mode' => 'ACTIVE',
        'rate' => 'SLOW',
        'port_list' => '1/1/1-1/1/2',
        'trunk_type' => 'LACP',
    ]);
    $switchPort = SwitchPort::factory()->create([
        'interface_mode' => 'TRUNK',
        'access_vlan' => null,
        'native_vlan' => 10,
        'trunk_vlan_all' => 'true',
        'trunk_vlan_ranges' => null,
    ]);
    $deviceInterface = DeviceInterface::factory()->create([
        'device_id' => $device->id,
        'interface' => '10',
        'switch_port_id' => $switchPort->id,
        'lacp_profile_id' => $lacpProfile->id,
        'interface_kind' => InterfaceKind::LAG,
        'description' => null,
    ]);

    $task = Task::factory()->for($deployment)->create([
        'task_type' => 'CONFIGURE_LAG_INTERFACE',
        'status' => 'COMPLETED',
    ]);
    $task->deviceInterfaces()->attach($deviceInterface->id, ['status' => 'COMPLETED']);

    $expectedCentralItem = [
        'name' => '10',
        'vsx' => ['shutdown-on-split' => false],
        'switchport' => [
            'access-vlan' => null,
            'interface-mode' => 'TRUNK',
            'native-vlan' => 10,
            'trunk-vlan-all' => true,
            'trunk-vlan-ranges' => null,
        ],
        'lacp' => [
            'mode' => 'ACTIVE',
            'rate' => 'SLOW',
        ],
        'trunk-type' => 'LACP',
        'port-list' => ['1/1/1', '1/1/2'],
        'enable' => true,
    ];

    return [
        'task' => $task,
        'deviceInterface' => $deviceInterface,
        'expectedCentralItem' => $expectedCentralItem,
    ];
}

test('task lag central check reports success when Central matches expected config', function () {
    $fixtures = createLagCentralCheckFixtures($this->device, $this->deployment);

    Http::fake([
        '*portchannels*' => Http::response([
            'items' => [$fixtures['expectedCentralItem']],
        ], 200),
    ]);

    $this->get(route('tasks.check', $fixtures['task']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Task/Check')
            ->where('summary.passed', 1)
            ->where('summary.failed', 0)
            ->has('results', 1)
            ->where('results.0.ok', true)
            ->where('results.0.interface', '10'));
});

test('task lag central check reports mismatch when Central differs', function () {
    $fixtures = createLagCentralCheckFixtures($this->device, $this->deployment);
    $mismatched = $fixtures['expectedCentralItem'];
    $mismatched['lacp']['mode'] = 'PASSIVE';

    Http::fake([
        '*portchannels*' => Http::response([
            'items' => [$mismatched],
        ], 200),
    ]);

    $this->get(route('tasks.check', $fixtures['task']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.failed', 1)
            ->where('results.0.ok', false)
            ->where('results.0.diff.0.path', 'lacp.mode'));
});

test('task lag central check reports missing interface when not in Central', function () {
    $fixtures = createLagCentralCheckFixtures($this->device, $this->deployment);

    Http::fake([
        '*portchannels*' => Http::response(['items' => []], 200),
    ]);

    $this->get(route('tasks.check', $fixtures['task']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('results.0.ok', false)
            ->where('results.0.missing_in_central', true));
});

test('task lag central check returns 404 for unsupported task types', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'CONFIGURE_ETHERNET_INTERFACE',
        'status' => 'COMPLETED',
    ]);

    $this->get(route('tasks.check', $task))->assertNotFound();
});

test('task lag central check redirects when current client does not match deployment', function () {
    $otherClient = Client::factory()->for($this->user)->create(['current' => true]);
    $this->client->update(['current' => false]);

    $fixtures = createLagCentralCheckFixtures($this->device, $this->deployment);

    Http::fake([
        '*portchannels*' => Http::response(['items' => []], 200),
    ]);

    $this->get(route('tasks.check', $fixtures['task']))
        ->assertRedirect(route('tasks.index'))
        ->assertSessionHas('error');

    expect($otherClient->fresh()->current)->toBeTrue();
});

test('task index includes central check flags for lag tasks', function () {
    $fixtures = createLagCentralCheckFixtures($this->device, $this->deployment);

    $this->get(route('tasks.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1)
            ->where('tasks.data.0.id', $fixtures['task']->id)
            ->where('tasks.data.0.supports_central_check', true)
            ->where('tasks.data.0.can_run_central_check', true));
});
