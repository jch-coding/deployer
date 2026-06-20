<?php

use App\Actions\Provisioning\NameDeviceAction;
use App\DeviceFunction;
use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Services\Provisioning\ProvisioningStepResult;

it('skips naming APs without an explicit hostname', function () {
    $device = Device::factory()->make([
        'device_function' => DeviceFunction::CAMPUS_AP->name,
        'name' => 'SN0000000001',
        'serial' => 'SN0000000001',
        'scope_id' => 'scope-1',
    ]);

    $action = new NameDeviceAction();
    $result = $action->execute($device, Mockery::mock(CentralAPIHelper::class));

    expect($result->isSkipped())->toBeTrue()
        ->and($result->outcome)->toBe(ProvisioningStepResult::OUTCOME_SKIPPED);
});

it('names APs when an explicit hostname is set', function () {
    $device = Device::factory()->make([
        'device_function' => DeviceFunction::CAMPUS_AP->name,
        'name' => 'Campus AP 1',
        'serial' => 'SN0000000001',
        'scope_id' => 'scope-1',
    ]);

    $central = Mockery::mock(CentralAPIHelper::class);
    $central->shouldReceive('updateSystemInfo')
        ->once()
        ->with($device)
        ->andReturn(new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(200)));

    $action = new NameDeviceAction();
    $result = $action->execute($device, $central);

    expect($result->isCompleted())->toBeTrue();
});
