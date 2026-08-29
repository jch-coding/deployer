<?php

use App\Support\ClassicSiteTaskPayload;

it('builds classic site body with geolocation when both coordinates are present', function () {
    $body = ClassicSiteTaskPayload::buildClassicSiteBody([
        'site_name' => 'Warehouse',
        'site_address' => [
            'address' => '123 Main',
            'city' => 'Denver',
            'state' => 'CO',
            'country' => 'US',
            'zipcode' => '80202',
        ],
        'geolocation' => [
            'latitude' => '39.7392',
            'longitude' => '-104.9903',
        ],
    ]);

    expect($body)->toBe([
        'site_name' => 'Warehouse',
        'site_address' => [
            'address' => '123 Main',
            'city' => 'Denver',
            'state' => 'CO',
            'country' => 'US',
            'zipcode' => '80202',
        ],
        'geolocation' => [
            'latitude' => '39.7392',
            'longitude' => '-104.9903',
        ],
    ]);
});

it('omits geolocation when latitude or longitude is empty', function () {
    $body = ClassicSiteTaskPayload::buildClassicSiteBody([
        'site_name' => 'Warehouse',
        'site_address' => [
            'address' => '123 Main',
            'city' => 'Denver',
            'state' => 'CO',
            'country' => 'US',
            'zipcode' => '80202',
        ],
        'geolocation' => [
            'latitude' => '',
            'longitude' => '-104.9903',
        ],
    ]);

    expect($body)->not->toHaveKey('geolocation');
});

it('derives task status from site detail rows', function () {
    expect(ClassicSiteTaskPayload::deriveTaskStatusFromSiteDetails([
        ['status' => 'COMPLETED'],
        ['status' => 'COMPLETED'],
    ]))->toBe('COMPLETED');

    expect(ClassicSiteTaskPayload::deriveTaskStatusFromSiteDetails([
        ['status' => 'FAILED'],
        ['status' => 'FAILED'],
    ]))->toBe('FAILED');

    expect(ClassicSiteTaskPayload::deriveTaskStatusFromSiteDetails([
        ['status' => 'COMPLETED'],
        ['status' => 'FAILED'],
    ]))->toBe('FAILED');

    expect(ClassicSiteTaskPayload::deriveTaskStatusFromSiteDetails([
        ['status' => 'PENDING'],
        ['status' => 'COMPLETED'],
    ]))->toBeNull();
});
