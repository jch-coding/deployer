<?php

use App\Support\MacAddress;

it('normalizes colon, dash, and bare hex MAC addresses', function () {
    expect(MacAddress::normalize('AA:BB:CC:DD:EE:FF'))->toBe('aa:bb:cc:dd:ee:ff')
        ->and(MacAddress::normalize('aa-bb-cc-dd-ee-ff'))->toBe('aa:bb:cc:dd:ee:ff')
        ->and(MacAddress::normalize('AABBCCDDEEFF'))->toBe('aa:bb:cc:dd:ee:ff');
});

it('formats MACs for Central CSV import', function () {
    expect(MacAddress::toCentralCsvFormat('aa:bb:cc:dd:ee:ff'))->toBe('AA-BB-CC-DD-EE-FF')
        ->and(MacAddress::toCentralCsvFormat('f4-9a-b1-c0-a5-86'))->toBe('F4-9A-B1-C0-A5-86')
        ->and(MacAddress::toCentralCsvFormat('not-a-mac'))->toBeNull();
});

it('rejects invalid MAC addresses', function () {
    expect(MacAddress::normalize(''))->toBeNull()
        ->and(MacAddress::normalize('not-a-mac'))->toBeNull()
        ->and(MacAddress::normalize('aa:bb:cc:dd:ee'))->toBeNull()
        ->and(MacAddress::isValid('zz:zz:zz:zz:zz:zz'))->toBeFalse();
});

it('extracts MACs from tpd_ unmanaged identifiers', function () {
    expect(MacAddress::fromTpdIdentifier('tpd_aabbccddeeff'))->toBe('aa:bb:cc:dd:ee:ff')
        ->and(MacAddress::fromTpdIdentifier('TPD_AABBCCDDEEFF'))->toBe('aa:bb:cc:dd:ee:ff')
        ->and(MacAddress::fromTpdIdentifier('tpd_aa:bb:cc:dd:ee:ff'))->toBe('aa:bb:cc:dd:ee:ff')
        ->and(MacAddress::fromTpdIdentifier('SG99KN003F'))->toBeNull()
        ->and(MacAddress::fromTpdIdentifier('tpd_short'))->toBeNull()
        ->and(MacAddress::fromTpdIdentifier(''))->toBeNull();
});
