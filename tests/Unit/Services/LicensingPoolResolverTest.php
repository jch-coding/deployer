<?php

use App\LicenseType;
use App\Services\LicensingPoolResolver;

test('LicenseType matches common GreenLake tier descriptions', function () {
    expect(LicenseType::AdvancedAP->matchesTierDescription('Advanced AP'))->toBeTrue()
        ->and(LicenseType::FoundationAP->matchesTierDescription('Foundation AP'))->toBeTrue()
        ->and(LicenseType::AdvancedSwitchClass1->matchesTierDescription('Advanced Switch'))->toBeTrue()
        ->and(LicenseType::AdvancedSwitchClass3->matchesTierDescription('Advanced-Switch-Class-3'))->toBeTrue()
        ->and(LicenseType::FoundationSwitchClass2->matchesTierDescription('Foundation Switch Class 2'))->toBeTrue();
});

test('LicenseType tryFromValue accepts canonical enum values', function () {
    expect(LicenseType::tryFromValue('Advanced AP'))->toBe(LicenseType::AdvancedAP)
        ->and(LicenseType::tryFromValue('Advanced-Switch-Class-3'))->toBe(LicenseType::AdvancedSwitchClass3);
});

test('LicensingPoolResolver sums seats across matching subscriptions', function () {
    $resolver = new LicensingPoolResolver;
    $subscriptions = [
        [
            'subscription_key' => 'KEY-1',
            'greenlake_subscription_id' => 'gl-1',
            'license_type' => 'Advanced AP',
            'status' => 'OK',
            'available' => 2,
            'tags' => ['pool-a'],
        ],
        [
            'subscription_key' => 'KEY-2',
            'greenlake_subscription_id' => 'gl-2',
            'license_type' => 'Advanced AP',
            'status' => 'OK',
            'available' => 3,
            'tags' => ['pool-a'],
        ],
    ];

    $matching = $resolver->matchingSubscriptions($subscriptions, 'pool-a', LicenseType::AdvancedAP);

    expect($matching)->toHaveCount(2)
        ->and($resolver->poolAvailableSeats($matching))->toBe(5);
});

test('LicensingPoolResolver validatePoolCapacity returns error when not enough seats', function () {
    $resolver = new LicensingPoolResolver;
    $subscriptions = [[
        'subscription_key' => 'KEY-1',
        'greenlake_subscription_id' => 'gl-1',
        'license_type' => 'Advanced AP',
        'status' => 'OK',
        'available' => 1,
        'tags' => ['pool-a'],
    ]];

    $error = $resolver->validatePoolCapacity('pool-a', LicenseType::AdvancedAP, 3, $subscriptions);

    expect($error)->not()->toBeNull()
        ->and($error['error'])->toContain('Only 1 Advanced AP seat(s) available');
});

test('LicensingPoolResolver allocateDevices fills highest-available subscription first', function () {
    $resolver = new LicensingPoolResolver;
    $subscriptions = [
        [
            'subscription_key' => 'KEY-LOW',
            'greenlake_subscription_id' => 'gl-low',
            'license_type' => 'Advanced AP',
            'status' => 'OK',
            'available' => 1,
            'tags' => ['pool-a'],
        ],
        [
            'subscription_key' => 'KEY-HIGH',
            'greenlake_subscription_id' => 'gl-high',
            'license_type' => 'Advanced AP',
            'status' => 'OK',
            'available' => 3,
            'tags' => ['pool-a'],
        ],
    ];

    $allocations = $resolver->allocateDevices([10, 11, 12], 'pool-a', LicenseType::AdvancedAP, $subscriptions);

    expect($allocations)->toBe([
        10 => 'gl-high',
        11 => 'gl-high',
        12 => 'gl-high',
    ]);
});
