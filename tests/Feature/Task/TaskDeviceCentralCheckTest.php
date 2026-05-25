<?php

use App\BaseURL;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\Site;
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
    $this->site = Site::factory()->for($this->client)->create([
        'name' => 'My Site',
        'scope_id' => 'site-scope-123',
    ]);
    $this->device = Device::factory()->for($this->deployment)->for($this->site)->create([
        'name' => 'Switch-A',
        'serial' => 'SN12345',
    ]);
    $this->actingAs($this->user);
});

/**
 * @return array{task: Task, centralItem: array<string, mixed>}
 */
function createDeviceCentralCheckFixtures(
    Deployment $deployment,
    Device $device,
    string $taskType = 'ASSOCIATE_DEVICE_TO_SITE',
): array {
    $task = Task::factory()->for($deployment)->create([
        'task_type' => $taskType,
        'status' => 'COMPLETED',
    ]);
    $task->devices()->attach($device->id, ['status' => 'COMPLETED']);

    $centralItem = [
        'serialNumber' => $device->serial,
        'siteId' => $device->site->scope_id,
        'deviceName' => $device->name,
    ];

    return [
        'task' => $task,
        'centralItem' => $centralItem,
    ];
}

test('task device central check reports success for site association', function () {
    $fixtures = createDeviceCentralCheckFixtures($this->deployment, $this->device);

    Http::fake([
        '*network-monitoring/v1/devices*' => Http::response([
            'items' => [$fixtures['centralItem']],
            'next' => null,
            'total' => 1,
            'count' => 1,
        ], 200),
    ]);

    $this->get(route('tasks.check', $fixtures['task']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Task/Check')
            ->where('check_kind', 'site_association')
            ->where('summary.passed', 1)
            ->where('summary.failed', 0)
            ->has('results', 1)
            ->where('results.0.ok', true)
            ->where('results.0.serial', 'SN12345'));
});

test('task device central check reports success for site and name task', function () {
    $fixtures = createDeviceCentralCheckFixtures(
        $this->deployment,
        $this->device,
        'ASSOCIATE_SITE_AND_NAME',
    );

    Http::fake([
        '*network-monitoring/v1/devices*' => Http::response([
            'items' => [$fixtures['centralItem']],
            'next' => null,
        ], 200),
    ]);

    $this->get(route('tasks.check', $fixtures['task']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('check_kind', 'site_and_name')
            ->where('summary.passed', 1));
});

test('task device central check reports success for naming task', function () {
    $fixtures = createDeviceCentralCheckFixtures(
        $this->deployment,
        $this->device,
        'UPDATE_SYSTEM_INFO',
    );

    Http::fake([
        '*network-monitoring/v1/devices*' => Http::response([
            'items' => [$fixtures['centralItem']],
            'next' => null,
        ], 200),
    ]);

    $this->get(route('tasks.check', $fixtures['task']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('check_kind', 'device_name')
            ->where('summary.passed', 1));
});

test('task device central check reports mismatch when central differs', function () {
    $fixtures = createDeviceCentralCheckFixtures($this->deployment, $this->device);
    $mismatched = $fixtures['centralItem'];
    $mismatched['siteId'] = 'wrong-site';

    Http::fake([
        '*network-monitoring/v1/devices*' => Http::response([
            'items' => [$mismatched],
            'next' => null,
        ], 200),
    ]);

    $this->get(route('tasks.check', $fixtures['task']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.failed', 1)
            ->where('results.0.ok', false)
            ->where('results.0.diff.0.path', 'siteId'));
});

test('task device central check reports missing device when not in central', function () {
    $fixtures = createDeviceCentralCheckFixtures($this->deployment, $this->device);

    Http::fake([
        '*network-monitoring/v1/devices*' => Http::response([
            'items' => [],
            'next' => null,
        ], 200),
    ]);

    $this->get(route('tasks.check', $fixtures['task']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('results.0.ok', false)
            ->where('results.0.missing_in_central', true));
});

test('task device central check returns 404 for unsupported task types', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'PREPROVISION_DEVICE_TO_GROUP',
        'status' => 'COMPLETED',
    ]);

    $this->get(route('tasks.check', $task))->assertNotFound();
});

test('task device central check redirects when current client does not match deployment', function () {
    $otherClient = Client::factory()->for($this->user)->create(['current' => true]);
    $this->client->update(['current' => false]);

    $fixtures = createDeviceCentralCheckFixtures($this->deployment, $this->device);

    Http::fake([
        '*network-monitoring/v1/devices*' => Http::response(['items' => []], 200),
    ]);

    $this->get(route('tasks.check', $fixtures['task']))
        ->assertRedirect(route('tasks.index'))
        ->assertSessionHas('error');

    expect($otherClient->fresh()->current)->toBeTrue();
});

test('task index includes central check flags for site association tasks', function () {
    $fixtures = createDeviceCentralCheckFixtures($this->deployment, $this->device);

    $this->get(route('tasks.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1)
            ->where('tasks.data.0.id', $fixtures['task']->id)
            ->where('tasks.data.0.supports_central_check', true)
            ->where('tasks.data.0.can_run_central_check', true));
});

test('relaunch failed verification creates site association task with only failed devices', function () {
    $fixtures = createDeviceCentralCheckFixtures($this->deployment, $this->device);

    Http::fake([
        '*network-monitoring/v1/devices*' => Http::response(['items' => []], 200),
    ]);

    $response = $this->post(route('tasks.relaunch_failed_verification', $fixtures['task']));

    $newTask = Task::query()
        ->where('deployment_id', $this->deployment->id)
        ->where('id', '!=', $fixtures['task']->id)
        ->latest('id')
        ->first();

    expect($newTask)->not->toBeNull()
        ->and($newTask->task_type)->toBe('ASSOCIATE_DEVICE_TO_SITE');

    $response->assertRedirect(route('tasks.show', $newTask));

    expect($newTask->fresh()->devices->pluck('id')->all())
        ->toBe([$this->device->id]);
});

test('device task show exposes verify button for completed site association tasks', function () {
    $fixtures = createDeviceCentralCheckFixtures($this->deployment, $this->device);

    $this->get(route('tasks.show', $fixtures['task']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Task/DeviceTask')
            ->where('can_run_central_check', true));
});
