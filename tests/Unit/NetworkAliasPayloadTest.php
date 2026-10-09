<?php

use App\Support\NetworkAliasPayload;

test('extracts site number from nn - site name pattern', function () {
    expect(NetworkAliasPayload::extractSiteNumber('07 - Boulder'))->toBe(7)
        ->and(NetworkAliasPayload::extractSiteNumber('10 - Denver High'))->toBe(10)
        ->and(NetworkAliasPayload::extractSiteNumber('00 - Zero'))->toBe(0);
});

test('rejects site names without two-digit prefix', function () {
    expect(NetworkAliasPayload::extractSiteNumber('Boulder'))->toBeNull()
        ->and(NetworkAliasPayload::extractSiteNumber('7 - Boulder'))->toBeNull()
        ->and(NetworkAliasPayload::extractSiteNumber('107 - Boulder'))->toBeNull()
        ->and(NetworkAliasPayload::defaultCidrFromSiteName('Warehouse'))->toBeNull();
});

test('builds default cidr from site name', function () {
    expect(NetworkAliasPayload::defaultCidrFromSiteName('07 - Boulder'))->toBe('10.7.9.0/24')
        ->and(NetworkAliasPayload::defaultCidrFromSiteName('42 - Site'))->toBe('10.42.9.0/24');
});

test('resolve network address prefers override over site preset', function () {
    expect(NetworkAliasPayload::resolveNetworkIpv4Address('07 - Boulder', '10.99.9.0/24'))
        ->toBe('10.99.9.0/24')
        ->and(NetworkAliasPayload::resolveNetworkIpv4Address('07 - Boulder', '  '))
        ->toBe('10.7.9.0/24')
        ->and(NetworkAliasPayload::resolveNetworkIpv4Address('Warehouse', null))
        ->toBeNull()
        ->and(NetworkAliasPayload::resolveNetworkIpv4Address('Warehouse', '10.1.9.0/24'))
        ->toBe('10.1.9.0/24');
});

test('builds alias body shape', function () {
    expect(NetworkAliasPayload::buildBody('10.7.9.0/24'))->toBe([
        'default-value' => [
            'network-address-value' => [
                'network-ipv4-address' => '10.7.9.0/24',
            ],
        ],
        'name' => 'BVSD-VIVI-SUBNET',
        'type' => 'ALIAS_NETWORK',
    ]);
});
