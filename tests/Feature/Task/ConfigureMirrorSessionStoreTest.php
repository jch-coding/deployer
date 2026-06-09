<?php

use App\DeviceFunction;
use App\Jobs\ConfigureMirrorSessionJob;
use App\Models\Client;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    $this->user = User::factory()->has(Client::factory())->create();
    $this->client = $this->user->clients()->first();
    $this->client->update(['current' => true]);
    $this->deployment = $this->client->deployments()->create(['name' => 'Mirror Store Deployment']);
    $this->actingAs($this->user);
});

test('CONFIGURE_MIRROR_SESSION in fallback mode attaches only name-pattern devices', function () {
    Bus::fake();

    $coreDevice = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'name' => 'NY1-MDF-CORE-SW1',
        'device_function' => DeviceFunction::CORE_SWITCH,
    ]);
    $accessDevice = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'name' => 'NY1-ACCESS-SW1',
        'device_function' => DeviceFunction::ACCESS_SWITCH,
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'CONFIGURE_MIRROR_SESSION',
        'deployment_time' => 1,
        'devices' => [
            ['id' => $coreDevice->id],
            ['id' => $accessDevice->id],
        ],
    ]);

    $response->assertSessionHasNoErrors();
    $task = $this->deployment->refresh()->tasks()->first();

    expect($task)->not()->toBeNull()
        ->and($task->task_type)->toBe('CONFIGURE_MIRROR_SESSION')
        ->and($task->mirror_fallback_mode)->toBeTrue()
        ->and($task->devices()->pluck('devices.id')->all())->toBe([$coreDevice->id]);

    Bus::assertBatched(function ($batch): bool {
        return $batch->jobs->count() === 1
            && $batch->jobs->first() instanceof ConfigureMirrorSessionJob;
    });
});

test('CONFIGURE_MIRROR_SESSION in fallback mode errors when no selected devices match name patterns', function () {
    Bus::fake();

    $accessDevice = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'name' => 'NY1-ACCESS-SW1',
        'device_function' => DeviceFunction::ACCESS_SWITCH,
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'CONFIGURE_MIRROR_SESSION',
        'deployment_time' => 1,
        'devices' => [
            ['id' => $accessDevice->id],
        ],
    ]);

    $response->assertSessionHasErrors('devices');
    expect($this->deployment->refresh()->tasks)->toHaveCount(0);
    Bus::assertNothingBatched();
});

test('CONFIGURE_MIRROR_SESSION in explicit mode attaches only devices with mirror attributes', function () {
    Bus::fake();

    $explicitDevice = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'name' => 'NY1-MDF-CORE-SW1',
        'device_function' => DeviceFunction::CORE_SWITCH,
        'mirror_dst_ports' => '1/1/43',
    ]);
    $coreWithoutMirror = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'name' => 'NY1-MDF-CORE-SW2',
        'device_function' => DeviceFunction::CORE_SWITCH,
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'CONFIGURE_MIRROR_SESSION',
        'deployment_time' => 1,
        'devices' => [
            ['id' => $explicitDevice->id],
            ['id' => $coreWithoutMirror->id],
        ],
    ]);

    $response->assertSessionHasNoErrors();
    $task = $this->deployment->refresh()->tasks()->first();

    expect($task->mirror_fallback_mode)->toBeFalse()
        ->and($task->devices()->pluck('devices.id')->all())->toBe([$explicitDevice->id]);
});
