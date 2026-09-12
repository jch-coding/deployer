<?php

use App\Actions\Provisioning\NameDeviceAction;
use App\DeviceFunction;
use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Services\Provisioning\ProvisioningStepResult;
use Illuminate\Http\Client\Response;

it('skips naming APs without an explicit hostname', function () {
    $device = Device::factory()->make([
        'device_function' => DeviceFunction::CAMPUS_AP->name,
        'name' => 'SN0000000001',
        'serial' => 'SN0000000001',
        'scope_id' => 'scope-1',
    ]);

    $action = new NameDeviceAction;
    $result = $action->execute($device, Mockery::mock(CentralAPIHelper::class));

    expect($result->isSkipped())->toBeTrue()
        ->and($result->outcome)->toBe(ProvisioningStepResult::OUTCOME_SKIPPED);
});

it('fails when the device is not online in Classic Central', function () {
    $device = Device::factory()->make([
        'device_function' => DeviceFunction::ACCESS_SWITCH->name,
        'name' => 'switch-a',
        'serial' => 'SNDOWN1',
        'scope_id' => 'scope-1',
    ]);

    $central = Mockery::mock(CentralAPIHelper::class);
    $central->shouldReceive('classic_collect_all_switches')->once()->andReturn([
        'switches' => [['serial' => 'SNDOWN1', 'status' => 'Down']],
    ]);
    $central->shouldReceive('updateSystemInfo')->never();

    $action = new NameDeviceAction;
    $result = $action->execute($device, $central);

    expect($result->isFailed())->toBeTrue()
        ->and($result->message)->toContain('not online in Classic Central');
});

it('names APs when an explicit hostname is set and device is Up', function () {
    $device = Device::factory()->make([
        'device_function' => DeviceFunction::CAMPUS_AP->name,
        'name' => 'Campus AP 1',
        'serial' => 'SN0000000001',
        'scope_id' => 'scope-1',
    ]);

    $central = Mockery::mock(CentralAPIHelper::class);
    $central->shouldReceive('classic_collect_all_aps')->once()->andReturn([
        'aps' => [['serial' => 'SN0000000001', 'status' => 'Up']],
    ]);
    $central->shouldReceive('updateSystemInfo')
        ->once()
        ->with($device)
        ->andReturn(new Response(new \GuzzleHttp\Psr7\Response(200)));

    $action = new NameDeviceAction;
    $result = $action->execute($device, $central);

    expect($result->isCompleted())->toBeTrue();
});

it('completes without write when only_update_different_names and hostname matches', function () {
    $device = Device::factory()->make([
        'device_function' => DeviceFunction::ACCESS_SWITCH->name,
        'name' => 'switch-a',
        'serial' => 'SNUP1',
        'scope_id' => 'scope-1',
    ]);

    $central = Mockery::mock(CentralAPIHelper::class);
    $central->shouldReceive('classic_collect_all_switches')->once()->andReturn([
        'switches' => [['serial' => 'SNUP1', 'status' => 'Up']],
    ]);
    $getResponse = Mockery::mock(Response::class);
    $getResponse->shouldReceive('successful')->andReturn(true);
    $getResponse->shouldReceive('json')->with('profile', [])->andReturn([
        ['hostname' => 'switch-a'],
    ]);
    $central->shouldReceive('getSystemInfo')->once()->andReturn($getResponse);
    $central->shouldReceive('updateSystemInfo')->never();

    $action = new NameDeviceAction;
    $result = $action->execute($device, $central, onlyUpdateDifferentNames: true);

    expect($result->isCompleted())->toBeTrue()
        ->and($result->message)->toContain('already matches Central');
});

it('updates when only_update_different_names and hostname differs', function () {
    $device = Device::factory()->make([
        'device_function' => DeviceFunction::ACCESS_SWITCH->name,
        'name' => 'switch-a',
        'serial' => 'SNUP1',
        'scope_id' => 'scope-1',
    ]);

    $central = Mockery::mock(CentralAPIHelper::class);
    $central->shouldReceive('classic_collect_all_switches')->once()->andReturn([
        'switches' => [['serial' => 'SNUP1', 'status' => 'Up']],
    ]);
    $getResponse = Mockery::mock(Response::class);
    $getResponse->shouldReceive('successful')->andReturn(true);
    $getResponse->shouldReceive('json')->with('profile', [])->andReturn([
        ['hostname' => 'old-name'],
    ]);
    $central->shouldReceive('getSystemInfo')->once()->andReturn($getResponse);
    $central->shouldReceive('updateSystemInfo')
        ->once()
        ->andReturn(new Response(new \GuzzleHttp\Psr7\Response(200)));

    $action = new NameDeviceAction;
    $result = $action->execute($device, $central, onlyUpdateDifferentNames: true);

    expect($result->isCompleted())->toBeTrue()
        ->and($result->message)->toContain('updated');
});
