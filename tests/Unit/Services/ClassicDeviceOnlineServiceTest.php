<?php

use App\DeviceFunction;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\User;
use App\Services\Provisioning\ClassicDeviceOnlineService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new ClassicDeviceOnlineService;
});

it('reports device up when classic status is Up for switches', function () {
    $device = Device::factory()->make([
        'serial' => 'SN123',
        'device_function' => DeviceFunction::ACCESS_SWITCH->name,
    ]);

    $statuses = ['SN123' => 'Up'];

    expect($this->service->isDeviceUp($device, $statuses, []))->toBeTrue()
        ->and($this->service->currentStatus($device, $statuses, []))->toBe('Up');
});

it('reports device not up when classic status is Down', function () {
    $device = Device::factory()->make([
        'serial' => 'SN123',
        'device_function' => DeviceFunction::CAMPUS_AP->name,
    ]);

    expect($this->service->isDeviceUp($device, [], ['SN123' => 'Down']))->toBeFalse()
        ->and($this->service->currentStatus($device, [], ['SN123' => 'Down']))->toBe('Down');
});

it('detects when workflow needs switch polling', function () {
    $user = User::factory()->has(Client::factory())->create();
    $client = $user->clients()->first();
    $deployment = Deployment::factory()->for($client)->create();
    $workflow = $deployment->provisioningWorkflows()->create([
        'user_id' => $user->id,
        'status' => 'running',
        'job_queue' => 'q0',
        'deployment_time' => 10,
        'wait_time' => 1,
    ]);
    $device = Device::factory()->for($deployment)->create([
        'device_function' => DeviceFunction::ACCESS_SWITCH->name,
    ]);
    $workflowDevice = $workflow->workflowDevices()->create([
        'device_id' => $device->id,
        'overall_status' => 'in_progress',
        'current_step_key' => 'wait_for_online',
    ]);
    $workflowDevice->steps()->create([
        'step_key' => 'wait_for_online',
        'step_order' => 4,
        'status' => 'in_progress',
    ]);

    expect($this->service->workflowNeedsSwitchPoll($workflow->fresh()))->toBeTrue()
        ->and($this->service->workflowNeedsApPoll($workflow->fresh()))->toBeFalse();
});
