<?php

use App\Support\MacAddress;

it('normalizes colon, dash, and bare hex MAC addresses', function () {
    expect(MacAddress::normalize('AA:BB:CC:DD:EE:FF'))->toBe('aa:bb:cc:dd:ee:ff')
        ->and(MacAddress::normalize('aa-bb-cc-dd-ee-ff'))->toBe('aa:bb:cc:dd:ee:ff')
        ->and(MacAddress::normalize('AABBCCDDEEFF'))->toBe('aa:bb:cc:dd:ee:ff');
});

it('rejects invalid MAC addresses', function () {
    expect(MacAddress::normalize(''))->toBeNull()
        ->and(MacAddress::normalize('not-a-mac'))->toBeNull()
        ->and(MacAddress::normalize('aa:bb:cc:dd:ee'))->toBeNull()
        ->and(MacAddress::isValid('zz:zz:zz:zz:zz:zz'))->toBeFalse();
});
