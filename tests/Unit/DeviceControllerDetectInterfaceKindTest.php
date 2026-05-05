<?php

use App\Http\Controllers\DeviceController;
use App\InterfaceKind;

test('detectInterfaceKind returns ETHERNET when interface contains a slash', function () {
    expect(DeviceController::detectInterfaceKind([
        'interface' => '1/1/1',
        'ip_address' => '10.0.0.1/24',
    ]))->toBe(InterfaceKind::ETHERNET);
});

test('detectInterfaceKind returns LAG when lacp_port_id is set', function () {
    expect(DeviceController::detectInterfaceKind([
        'interface' => '1',
        'lacp_port_id' => '12',
    ]))->toBe(InterfaceKind::LAG);
});

test('detectInterfaceKind returns LAG when port_list is non-empty', function () {
    expect(DeviceController::detectInterfaceKind([
        'interface' => '5',
        'port_list' => '1/1/1-1/1/2',
    ]))->toBe(InterfaceKind::LAG);
});

test('detectInterfaceKind returns VLAN when ip_address is set and row is not ethernet or lag', function () {
    expect(DeviceController::detectInterfaceKind([
        'interface' => '30',
        'ip_address' => '10.10.30.1/24',
    ]))->toBe(InterfaceKind::VLAN);
});

test('detectInterfaceKind defaults to ETHERNET for bare numeric interface without lag or vlan hints', function () {
    expect(DeviceController::detectInterfaceKind([
        'interface' => '99',
    ]))->toBe(InterfaceKind::ETHERNET);
});
