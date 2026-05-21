<?php

use App\InterfaceKind;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\User;
use App\Services\RelaunchFailedCriticalConfigService;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake();
    $this->user = User::factory()->create();
    $this->client = Client::factory()->for($this->user)->create(['current' => true]);
    $this->deployment = Deployment::factory()->for($this->client)->create();
    $this->device = Device::factory()->for($this->deployment)->create();
    $this->actingAs($this->user);
});

test('relaunch failed critical config creates composite tasks for failures', function () {
    $lag = DeviceInterface::factory()->create([
        'device_id' => $this->device->id,
        'interface_kind' => InterfaceKind::LAG,
    ]);
    $vlan = DeviceInterface::factory()->create([
        'device_id' => $this->device->id,
        'interface_kind' => InterfaceKind::VLAN,
    ]);

    $response = $this->post(route('deployments.relaunch_failed_critical_config', $this->deployment), [
        'deployment_time' => 5,
        'wait_time' => 2,
        'include_ethernet' => false,
        'failed_interface_ids' => [
            'lag' => [$lag->id],
            'vlan' => [$vlan->id],
            'ethernet' => [],
        ],
        'profile_device_ids' => [
            'static_route' => [$this->device->id],
            'dns' => [],
        ],
    ]);

    $response->assertSessionHasNoErrors();
    $tasks = $this->deployment->refresh()->tasks()->orderBy('composite_order')->get();
    expect($tasks)->toHaveCount(3);
    expect($tasks->every(fn ($t) => $t->composite_kind === RelaunchFailedCriticalConfigService::COMPOSITE_KIND))->toBeTrue();
    expect($tasks->pluck('task_type')->all())->toBe([
        'CONFIGURE_LAG_INTERFACE',
        'CONFIGURE_VLAN_INTERFACE',
        'REMOVE_LOCAL_OVERRIDE_STATIC_ROUTE',
    ]);
    expect($tasks->first()->remediation_context)->toMatchArray(['include_ethernet' => false]);
});

test('relaunch failed critical config rejects empty failure sets', function () {
    $this->post(route('deployments.relaunch_failed_critical_config', $this->deployment), [
        'deployment_time' => 1,
        'wait_time' => 1,
        'failed_interface_ids' => ['lag' => [], 'vlan' => [], 'ethernet' => []],
        'profile_device_ids' => ['static_route' => [], 'dns' => []],
    ])->assertSessionHasErrors('relaunch');
});
