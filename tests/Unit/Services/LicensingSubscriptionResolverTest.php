<?php

use App\Services\LicensingSubscriptionResolver;

test('resolver infers service from inventory devices on same subscription key', function () {
    $resolver = new LicensingSubscriptionResolver;

    $result = $resolver->resolveServiceName(
        'KEY-001',
        ['advanced_ap', 'advanced_switch_6300'],
        [
            'KEY-001' => [
                'subscription_key' => 'KEY-001',
                'license_type' => 'Advanced AP',
                'available' => 5,
            ],
        ],
        [
            [
                'serial' => 'SN-1',
                'subscription_key' => 'KEY-001',
                'services' => ['advanced_ap'],
            ],
        ],
    );

    expect($result)->toBe(['service_name' => 'advanced_ap']);
});

test('resolver maps license type slug to enabled service', function () {
    $resolver = new LicensingSubscriptionResolver;

    $result = $resolver->resolveServiceName(
        'KEY-AP',
        ['advanced_ap'],
        [
            'KEY-AP' => [
                'subscription_key' => 'KEY-AP',
                'license_type' => 'Advanced AP',
                'available' => 3,
            ],
        ],
        [],
    );

    expect($result)->toBe(['service_name' => 'advanced_ap']);
});

test('resolver validateCapacity blocks over-assignment', function () {
    $resolver = new LicensingSubscriptionResolver;

    $error = $resolver->validateCapacity('KEY-1', 5, [
        'KEY-1' => ['subscription_key' => 'KEY-1', 'available' => 2],
    ]);

    expect($error)->toBe(['error' => 'Only 2 seat(s) available on this license; you selected 5 device(s).']);
});
