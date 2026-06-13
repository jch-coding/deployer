<?php

use App\DeviceFunction;
use App\Enums\ProvisioningStep;
use App\Models\Device;

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
    ]);

    expect(ProvisioningStep::CreateStackProfile->shouldSkipForDevice($device))->toBeTrue();
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
