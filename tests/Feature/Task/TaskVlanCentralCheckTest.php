<?php

use App\BaseURL;
use App\InterfaceKind;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\DeviceInterface;
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
function createVlanCentralCheckFixtures(Device $device, Deployment $deployment): array
{
    $deviceInterface = DeviceInterface::factory()->create([
        'device_id' => $device->id,
        'interface' => '100',
        'interface_kind' => InterfaceKind::VLAN,
        'ip_address' => '10.0.0.1/24',
        'enable' => true,
    ]);

    $task = Task::factory()->for($deployment)->create([
        'task_type' => 'CONFIGURE_VLAN_INTERFACE',
        'status' => 'COMPLETED',
    ]);
    $task->deviceInterfaces()->attach($deviceInterface->id, ['status' => 'COMPLETED']);

    $expectedCentralItem = [
        'id' => '100',
        'ipv4' => ['address' => '10.0.0.1/24'],
        'enable' => true,
        'is-valid' => true,
    ];

    return [
        'task' => $task,
        'deviceInterface' => $deviceInterface,
        'expectedCentralItem' => $expectedCentralItem,
    ];
}

test('task vlan central check reports success when Central matches expected config', function () {
    $fixtures = createVlanCentralCheckFixtures($this->device, $this->deployment);

    Http::fake([
        '*vlan-interfaces*' => Http::response([
            'interface' => [$fixtures['expectedCentralItem']],
        ], 200),
    ]);

    $this->get(route('tasks.check', $fixtures['task']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Task/Check')
            ->where('check_kind', 'vlan')
            ->where('summary.passed', 1)
            ->where('summary.failed', 0)
            ->has('results', 1)
            ->where('results.0.ok', true)
            ->where('results.0.interface', '100'));
});

test('task vlan central check reports mismatch when Central differs', function () {
    $fixtures = createVlanCentralCheckFixtures($this->device, $this->deployment);
    $mismatched = $fixtures['expectedCentralItem'];
    $mismatched['ipv4']['address'] = '10.0.0.2/24';

    Http::fake([
        '*vlan-interfaces*' => Http::response([
            'interface' => [$mismatched],
        ], 200),
    ]);

    $this->get(route('tasks.check', $fixtures['task']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.failed', 1)
            ->where('results.0.ok', false)
            ->where('results.0.diff.0.path', 'ipv4.address'));
});

test('task vlan central check reports missing interface when not in Central', function () {
    $fixtures = createVlanCentralCheckFixtures($this->device, $this->deployment);

    Http::fake([
        '*vlan-interfaces*' => Http::response(['interface' => []], 200),
    ]);

    $this->get(route('tasks.check', $fixtures['task']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('results.0.ok', false)
            ->where('results.0.missing_in_central', true));
});

test('task vlan central check returns 404 for unsupported task types', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'COMPLETED',
    ]);

    $this->get(route('tasks.check', $task))->assertNotFound();
});

test('task vlan central check redirects when current client does not match deployment', function () {
    $otherClient = Client::factory()->for($this->user)->create(['current' => true]);
    $this->client->update(['current' => false]);

    $fixtures = createVlanCentralCheckFixtures($this->device, $this->deployment);

    Http::fake([
        '*vlan-interfaces*' => Http::response(['interface' => []], 200),
    ]);

    $this->get(route('tasks.check', $fixtures['task']))
        ->assertRedirect(route('tasks.index'))
        ->assertSessionHas('error');

    expect($otherClient->fresh()->current)->toBeTrue();
});

test('task index includes central check flags for vlan tasks', function () {
    $fixtures = createVlanCentralCheckFixtures($this->device, $this->deployment);

    $this->get(route('tasks.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1)
            ->where('tasks.data.0.id', $fixtures['task']->id)
            ->where('tasks.data.0.supports_central_check', true)
            ->where('tasks.data.0.can_run_central_check', true));
});

test('relaunch failed verification creates vlan task with only failed interfaces', function () {
    $fixtures = createVlanCentralCheckFixtures($this->device, $this->deployment);

    Http::fake([
        '*vlan-interfaces*' => Http::response(['interface' => []], 200),
    ]);

    $response = $this->post(route('tasks.relaunch_failed_verification', $fixtures['task']));

    $newTask = Task::query()
        ->where('deployment_id', $this->deployment->id)
        ->where('id', '!=', $fixtures['task']->id)
        ->latest('id')
        ->first();

    expect($newTask)->not->toBeNull()
        ->and($newTask->task_type)->toBe('CONFIGURE_VLAN_INTERFACE');

    $response->assertRedirect(route('tasks.show', $newTask));

    expect($newTask->fresh()->deviceInterfaces->pluck('id')->all())
        ->toBe([$fixtures['deviceInterface']->id]);
});

test('interface task show exposes verify button for completed vlan tasks', function () {
    $fixtures = createVlanCentralCheckFixtures($this->device, $this->deployment);

    $this->get(route('tasks.show', $fixtures['task']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Task/InterfaceTask')
            ->where('can_run_central_check', true));
});
