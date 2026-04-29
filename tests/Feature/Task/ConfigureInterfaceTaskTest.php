<?php

use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\LacpProfile;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    $this->user = User::factory()->has(Client::factory())->create();
    $this->client = $this->user->clients->first();
    $this->client->update(['current' => true]);
    $this->deployment = Deployment::factory()->for($this->client)->create();
    $this->device = Device::factory()->for($this->deployment)->create();
    $this->actingAs($this->user);
});

test('CONFIGURE_ALL_INTERFACE creates lag subtask before ethernet subtask', function () {
    Bus::fake();

    $lacpProfile = LacpProfile::query()->create([
        'mode' => 'ACTIVE',
        'rate' => 'SLOW',
        'trunk_type' => 'LACP',
        'port_list' => '1/1/1-1/1/2',
    ]);

    DeviceInterface::query()->create([
        'device_id' => $this->device->id,
        'interface' => '10',
        'lacp_profile_id' => $lacpProfile->id,
    ]);
    DeviceInterface::query()->create([
        'device_id' => $this->device->id,
        'interface' => '1/1/1',
    ]);

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'CONFIGURE_ALL_INTERFACE',
        'deployment_time' => 1,
        'devices' => [['id' => $this->device->id]],
    ]);

    $response->assertSessionHasNoErrors();

    $tasks = $this->deployment->refresh()->tasks()->orderBy('composite_order')->get();
    expect($tasks->pluck('task_type')->all())->toBe([
        'CONFIGURE_LAG_INTERFACE',
        'CONFIGURE_ETHERNET_INTERFACE',
    ]);
    expect($tasks->pluck('composite_order')->all())->toBe([1, 2]);
});
