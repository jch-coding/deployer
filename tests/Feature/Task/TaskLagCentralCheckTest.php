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
            'interface' => [$fixtures['expectedCentralItem']],
        ], 200),
    ]);

    $this->get(route('tasks.check', $fixtures['task']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Task/Check')
            ->where('check_kind', 'lag')
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
            'interface' => [$mismatched],
        ], 200),
    ]);

    $this->get(route('tasks.check', $fixtures['task']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.failed', 1)
            ->where('results.0.ok', false)
            ->where('results.0.diff.0.path', 'lacp.mode'));
});

test('task lag central check finds interface on a later portchannels page', function () {
    $fixtures = createLagCentralCheckFixtures($this->device, $this->deployment);

    Http::fake(function (\Illuminate\Http\Client\Request $request) use ($fixtures) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        if (! isset($query['next'])) {
            return Http::response([
                'interface' => [['name' => '99', 'enable' => true]],
                'next' => 'page-2',
            ], 200);
        }

        return Http::response([
            'interface' => [$fixtures['expectedCentralItem']],
            'next' => null,
        ], 200);
    });

    $this->get(route('tasks.check', $fixtures['task']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.passed', 1)
            ->where('results.0.ok', true)
            ->where('results.0.interface', '10'));

    Http::assertSentCount(2);
});

test('task lag central check reports missing interface when not in Central', function () {
    $fixtures = createLagCentralCheckFixtures($this->device, $this->deployment);

    Http::fake([
        '*portchannels*' => Http::response(['interface' => []], 200),
    ]);

    $this->get(route('tasks.check', $fixtures['task']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('results.0.ok', false)
            ->where('results.0.missing_in_central', true));
});

test('task lag central check returns 404 for unsupported task types', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'COMPLETED',
    ]);

    $this->get(route('tasks.check', $task))->assertNotFound();
});

test('task lag central check redirects when current client does not match deployment', function () {
    $otherClient = Client::factory()->for($this->user)->create(['current' => true]);
    $this->client->update(['current' => false]);

    $fixtures = createLagCentralCheckFixtures($this->device, $this->deployment);

    Http::fake([
        '*portchannels*' => Http::response(['interface' => []], 200),
    ]);

    $this->get(route('tasks.check', $fixtures['task']))
        ->assertRedirect(route('tasks.index'))
        ->assertSessionHas('error');

    expect($otherClient->fresh()->current)->toBeTrue();
});

test('task lag central check exposes relaunch when interfaces failed', function () {
    $fixtures = createLagCentralCheckFixtures($this->device, $this->deployment);

    Http::fake([
        '*portchannels*' => Http::response(['interface' => []], 200),
    ]);

    $this->get(route('tasks.check', $fixtures['task']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can_relaunch_failed_verification', true)
            ->where('summary.failed', 1));
});

test('task lag central check hides relaunch when all interfaces pass', function () {
    $fixtures = createLagCentralCheckFixtures($this->device, $this->deployment);

    Http::fake([
        '*portchannels*' => Http::response([
            'interface' => [$fixtures['expectedCentralItem']],
        ], 200),
    ]);

    $this->get(route('tasks.check', $fixtures['task']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can_relaunch_failed_verification', false)
            ->where('summary.failed', 0));
});

test('relaunch failed verification creates task with only failed interfaces', function () {
    $fixtures = createLagCentralCheckFixtures($this->device, $this->deployment);

    Http::fake([
        '*portchannels*' => Http::response(['interface' => []], 200),
    ]);

    $response = $this->post(route('tasks.relaunch_failed_verification', $fixtures['task']));

    $newTask = Task::query()
        ->where('deployment_id', $this->deployment->id)
        ->where('id', '!=', $fixtures['task']->id)
        ->latest('id')
        ->first();

    expect($newTask)->not->toBeNull()
        ->and($newTask->task_type)->toBe('CONFIGURE_LAG_INTERFACE')
        ->and($newTask->status)->toBe('IN_PROGRESS');

    $response->assertRedirect(route('tasks.show', $newTask));

    expect($newTask->fresh()->deviceInterfaces->pluck('id')->all())
        ->toBe([$fixtures['deviceInterface']->id]);
});

test('relaunch failed verification rejects when nothing failed', function () {
    $fixtures = createLagCentralCheckFixtures($this->device, $this->deployment);

    Http::fake([
        '*portchannels*' => Http::response([
            'interface' => [$fixtures['expectedCentralItem']],
        ], 200),
    ]);

    $this->from(route('tasks.check', $fixtures['task']))
        ->post(route('tasks.relaunch_failed_verification', $fixtures['task']))
        ->assertRedirect(route('tasks.check', $fixtures['task']))
        ->assertSessionHas('error');

    expect(Task::query()->where('deployment_id', $this->deployment->id)->count())->toBe(1);
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
