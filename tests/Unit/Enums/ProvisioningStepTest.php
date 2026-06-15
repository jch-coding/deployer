<?php

use App\DeviceFunction;
use App\Enums\ProvisioningStep;
use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Services\Provisioning\ProvisioningStepContext;

it('orders provisioning steps consistently', function () {
    $orders = array_map(fn (ProvisioningStep $step) => $step->order(), ProvisioningStep::ordered());

    expect($orders)->toBe([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14]);
});

it('skips VSF stack wait for non SKU devices', function () {
    $device = Device::factory()->make(['sku' => null]);

    expect(ProvisioningStep::WaitForVsfStackScope->shouldSkipForDevice($device))->toBeTrue();
});

it('skips stack profile when no sku or vsx profile', function () {
    $device = Device::factory()->make([
        'sku' => null,
        'vsx_profile' => null,
        'name' => 'NY1-ACCESS-SW1',
    ]);

    expect(ProvisioningStep::CreateStackProfile->shouldSkipForDevice($device))->toBeTrue();
});

it('does not skip stack profile for name-based vsx fallback devices', function () {
    $device = Device::factory()->make([
        'sku' => null,
        'vsx_profile' => null,
        'name' => 'NY1-MDF-CORE-SW1',
    ]);
    $context = new ProvisioningStepContext(vsxFallbackMode: true);

    expect(ProvisioningStep::CreateStackProfile->shouldSkipForDevice($device, $context))->toBeFalse();
});

it('skips post-naming steps for AP devices', function () {
    $device = Device::factory()->make([
        'device_function' => DeviceFunction::CAMPUS_AP->name,
    ]);

    expect(ProvisioningStep::ConfigureVlanInterfaces->shouldSkipForDevice($device))->toBeTrue()
        ->and(ProvisioningStep::ConfigureMirrorSessions->shouldSkipForDevice($device))->toBeTrue()
        ->and(ProvisioningStep::NameDevice->shouldSkipForDevice($device))->toBeFalse();
});

it('includes mirror fallback devices in mirror step', function () {
    $device = Device::factory()->make([
        'name' => 'NY1-MDF-CORE-SW1',
        'mirror_session_id' => null,
        'mirror_dst_ports' => null,
        'mirror_name' => null,
    ]);
    $context = new ProvisioningStepContext(mirrorFallbackMode: true);

    expect(ProvisioningStep::ConfigureMirrorSessions->shouldSkipForDevice($device, $context))->toBeFalse();
});

it('detects mirror configuration on device', function () {
    $without = Device::factory()->make([
        'mirror_session_id' => null,
        'mirror_dst_ports' => null,
        'mirror_name' => null,
    ]);
    $with = Device::factory()->make([
        'mirror_session_id' => 1,
        'mirror_dst_ports' => '1/1/48',
        'mirror_name' => 'SPAN',
    ]);

    expect(ProvisioningStep::deviceHasMirrorConfig($without))->toBeFalse()
        ->and(ProvisioningStep::deviceHasMirrorConfig($with))->toBeTrue();
});

it('identifies switch and AP device functions for classic polling', function () {
    $switch = Device::factory()->make(['device_function' => DeviceFunction::ACCESS_SWITCH->name]);
    $ap = Device::factory()->make(['device_function' => DeviceFunction::CAMPUS_AP->name]);

    expect(str_contains((string) $switch->device_function, 'SWITCH'))->toBeTrue()
        ->and(str_contains((string) $ap->device_function, 'AP'))->toBeTrue();
});
