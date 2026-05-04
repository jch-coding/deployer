<?php

use App\Helper\InterfaceHelper;
use App\Http\Controllers\DeviceController;

it('strips leading zeros from slash-separated numeric segments', function () {
    expect(InterfaceHelper::normalizeInterfaceString('01/01/01'))->toBe('1/1/1')
        ->and(InterfaceHelper::normalizeInterfaceString('  1/01/10  '))->toBe('1/1/10');
});

it('normalizes single numeric interface identifiers', function () {
    expect(InterfaceHelper::normalizeInterfaceString('01'))->toBe('1')
        ->and(InterfaceHelper::normalizeInterfaceString('10'))->toBe('10');
});

it('preserves non-numeric path segments', function () {
    expect(InterfaceHelper::normalizeInterfaceString('Gi1/0/8'))->toBe('Gi1/0/8');
});

it('normalizes each side of hyphen ranges and ampersand-separated chunks', function () {
    expect(InterfaceHelper::normalizeInterfaceString('01/01/01-01/01/05'))->toBe('1/1/1-1/1/5')
        ->and(InterfaceHelper::normalizeInterfaceString('01/01/01&02/02/02'))->toBe('1/1/1&2/2/2');
});

it('normalizes interface ranges before expansion in DeviceController', function () {
    expect(DeviceController::expandInterfaceRange('01/01/01-01/01/02'))->toBe(['1/1/1', '1/1/2']);
});
