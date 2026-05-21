<?php

use App\InterfaceKind;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Services\DeviceInterfaceUpdateResolver;

test('payload attributes map vlan ipv4 address to ip_address', function () {
    $device = Device::factory()->create();
    $interface = DeviceInterface::factory()->create([
        'device_id' => $device->id,
        'interface' => '10',
        'interface_kind' => InterfaceKind::VLAN,
        'ip_address' => '10.0.0.1/24',
    ]);

    $resolver = new DeviceInterfaceUpdateResolver;
    $update = $resolver->payloadAttributesToUpdate('vlan', [
        'ipv4.address' => '10.0.0.2/24',
    ], $interface);

    expect($update)->toHaveKey('ip_address', '10.0.0.2/24');
});

test('payload attributes map lacp mode to lacp_mode', function () {
    $device = Device::factory()->create();
    $interface = DeviceInterface::factory()->create([
        'device_id' => $device->id,
        'interface' => '1',
        'interface_kind' => InterfaceKind::LAG,
    ]);

    $resolver = new DeviceInterfaceUpdateResolver;
    $update = $resolver->payloadAttributesToUpdate('lag', [
        'lacp.mode' => 'PASSIVE',
    ], $interface);

    expect($update)->toHaveKey('lacp_mode', 'PASSIVE');
});
