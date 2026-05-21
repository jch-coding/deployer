<?php

use App\InterfaceKind;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\Task;
use App\Models\User;
use App\Services\RelaunchFailedCriticalConfigService;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = Client::factory()->for($this->user)->create(['current' => true]);
    $this->deployment = Deployment::factory()->for($this->client)->create();
    $this->device = Device::factory()->for($this->deployment)->create([
        'scope_id' => 'scope-1',
        'device_function' => 'ACCESS_SWITCH',
    ]);
    $this->actingAs($this->user);

    Http::fake([
        '*portchannels*' => Http::response(['interface' => []], 200),
        '*vlan-interfaces*' => Http::response(['interface' => []], 200),
        '*site-collections*' => Http::response(['items' => [['scopeName' => 'WCD', 'scopeId' => 'wcd-scope']]], 200),
    ]);
});

test('remediation check page renders critical check in remediation mode', function () {
    $group = (string) \Illuminate\Support\Str::uuid();
    $lagTask = Task::factory()->for($this->deployment)->create([
        'task_type' => 'CONFIGURE_LAG_INTERFACE',
        'composite_group_id' => $group,
        'composite_kind' => RelaunchFailedCriticalConfigService::COMPOSITE_KIND,
        'composite_order' => 1,
        'remediation_context' => ['include_ethernet' => false],
        'status' => 'COMPLETED',
    ]);
    $interface = DeviceInterface::factory()->create([
        'device_id' => $this->device->id,
        'interface_kind' => InterfaceKind::LAG,
    ]);
    $lagTask->devices()->attach($this->device->id);
    $lagTask->deviceInterfaces()->attach([$interface->id => ['status' => 'COMPLETED']]);

    $this->get(route('tasks.remediation_check', $lagTask))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Deployment/CriticalCheck')
            ->where('remediation_task_id', $lagTask->id)
            ->where('include_ethernet', false));
});

test('remediation check step returns json progress', function () {
    $group = (string) \Illuminate\Support\Str::uuid();
    $lagTask = Task::factory()->for($this->deployment)->create([
        'task_type' => 'CONFIGURE_LAG_INTERFACE',
        'composite_group_id' => $group,
        'composite_kind' => RelaunchFailedCriticalConfigService::COMPOSITE_KIND,
        'composite_order' => 1,
        'remediation_context' => ['include_ethernet' => false],
    ]);
    $interface = DeviceInterface::factory()->create([
        'device_id' => $this->device->id,
        'interface_kind' => InterfaceKind::LAG,
    ]);
    $lagTask->devices()->attach($this->device->id);
    $lagTask->deviceInterfaces()->attach([$interface->id => ['status' => 'PENDING']]);

    $this->getJson(route('tasks.remediation_check.step', [$lagTask, 0]))
        ->assertOk()
        ->assertJsonStructure(['progress', 'partial', 'context', 'done']);
});
