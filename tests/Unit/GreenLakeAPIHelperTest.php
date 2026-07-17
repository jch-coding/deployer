<?php

use App\Helper\GreenLakeAPIHelper;
use App\Models\Client;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('normalizeTagKeys extracts keys from key-value maps and objects', function () {
    expect(GreenLakeAPIHelper::normalizeTagKeys([
        'deployment' => 'prod-east',
        'owner' => 'network-ops',
    ]))->toBe(['deployment', 'owner']);

    expect(GreenLakeAPIHelper::normalizeTagKeys([
        ['key' => 'location', 'value' => 'San Jose'],
        ['key' => 'cost-center', 'value' => 'CC-100'],
    ]))->toBe(['location', 'cost-center']);

    expect(GreenLakeAPIHelper::normalizeTagKeys(['legacy-key-only']))->toBe(['legacy-key-only']);
});

test('subscriptionIsAssignable accepts common GreenLake statuses', function () {
    expect(GreenLakeAPIHelper::subscriptionIsAssignable('ACTIVE'))->toBeTrue()
        ->and(GreenLakeAPIHelper::subscriptionIsAssignable('SUBSCRIBED'))->toBeTrue()
        ->and(GreenLakeAPIHelper::subscriptionIsAssignable(''))->toBeTrue()
        ->and(GreenLakeAPIHelper::subscriptionIsAssignable('EXPIRED'))->toBeFalse();
});

test('parseSubscriptions normalizes GreenLake items', function () {
    $client = Client::factory()->create([
        'bearer_token' => 'test-token',
        'expires_at' => now()->addHour(),
    ]);
    $helper = new GreenLakeAPIHelper($client);

    Http::fake([
        GreenLakeAPIHelper::BASE_URL.'/subscriptions/v1/subscriptions' => Http::response([
            'items' => [
                [
                    'id' => 'sub-uuid-1',
                    'key' => 'KEY-ABC',
                    'sku' => 'SKU-1',
                    'tierDescription' => 'Advanced Switch',
                    'quantity' => 10,
                    'availableQuantity' => 3,
                    'subscriptionStatus' => 'ACTIVE',
                    'startTime' => '2024-01-01T00:00:00Z',
                    'endTime' => '2025-01-01T00:00:00Z',
                    'productType' => 'CENTRAL',
                    'tier' => 'advanced_switch',
                    'tags' => [
                        'pool-a' => 'value-a',
                        'pool-b' => 'value-b',
                    ],
                ],
            ],
        ], 200),
    ]);

    $result = $helper->parseSubscriptions($helper->getSubscriptions());

    expect($result)->toHaveKey('subscriptions')
        ->and($result['subscriptions'])->toHaveCount(1)
        ->and($result['subscriptions'][0]['subscription_key'])->toBe('KEY-ABC')
        ->and($result['subscriptions'][0]['license_type'])->toBe('Advanced Switch')
        ->and($result['subscriptions'][0]['available'])->toBe(3)
        ->and($result['subscriptions'][0]['quantity'])->toBe(10)
        ->and($result['subscriptions'][0]['status'])->toBe('OK')
        ->and($result['subscriptions'][0]['greenlake_subscription_id'])->toBe('sub-uuid-1')
        ->and($result['subscriptions'][0]['tags'])->toBe(['pool-a', 'pool-b']);
});

test('handleBearerTokenAuth refreshes when bearer token is blank but expiry is still valid', function () {
    $client = Client::factory()->create([
        'client_id' => 'gl-client-id',
        'client_secret' => 'gl-client-secret',
        'bearer_token' => null,
        'expires_at' => now()->addHour(),
    ]);

    Http::fake([
        'https://sso.common.cloud.hpe.com/as/token.oauth2' => Http::response([
            'access_token' => 'fresh-token',
        ], 200),
    ]);

    expect($client->handleBearerTokenAuth())->toBeTrue()
        ->and($client->refresh()->bearer_token)->toBe('fresh-token');
});

test('getSubscriptions returns error when bearer token unavailable', function () {
    $client = Client::factory()->create([
        'bearer_token' => null,
        'expires_at' => null,
        'client_id' => '',
        'client_secret' => '',
    ]);
    Http::fake([
        'https://sso.common.cloud.hpe.com/*' => Http::response([], 401),
    ]);

    $helper = new GreenLakeAPIHelper($client);
    $result = $helper->getSubscriptions();

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('error');
});

test('collectSubscriptions uses API-friendly page size by default', function () {
    $client = Client::factory()->create([
        'bearer_token' => 'test-token',
        'expires_at' => now()->addHour(),
    ]);
    $helper = new GreenLakeAPIHelper($client);

    Http::fake(function (Request $request) {
        if (! str_contains($request->url(), '/subscriptions/v1/subscriptions') || $request->method() !== 'GET') {
            return Http::response([], 404);
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        if ((int) ($query['limit'] ?? 0) > 100) {
            return Http::response(['message' => 'limit too large'], 400);
        }

        return Http::response([
            'items' => [
                ['id' => 'sub-1', 'key' => 'KEY-1', 'sku' => 'SKU-1', 'tierDescription' => 'AP', 'availableQuantity' => 1, 'quantity' => 1, 'subscriptionStatus' => 'ACTIVE'],
            ],
            'total' => 1,
            'remainingRecords' => false,
        ], 200);
    });

    $items = $helper->collectSubscriptions();

    expect($items)->toHaveCount(1)
        ->and($items[0]['key'])->toBe('KEY-1');
});

test('collectSubscriptions paginates until all items are fetched', function () {
    $client = Client::factory()->create([
        'bearer_token' => 'test-token',
        'expires_at' => now()->addHour(),
    ]);
    $helper = new GreenLakeAPIHelper($client);

    $pageOne = [];
    for ($i = 0; $i < 1000; $i++) {
        $pageOne[] = [
            'id' => "sub-page1-{$i}",
            'key' => "KEY-P1-{$i}",
            'sku' => 'SKU-P1',
            'tierDescription' => 'Tier P1',
            'availableQuantity' => 1,
            'quantity' => 1,
            'subscriptionStatus' => 'ACTIVE',
        ];
    }

    Http::fake(function (Request $request) use ($pageOne) {
        if (! str_contains($request->url(), '/subscriptions/v1/subscriptions') || $request->method() !== 'GET') {
            return Http::response([], 404);
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $offset = (int) ($query['offset'] ?? 0);

        if ($offset === 0) {
            return Http::response(['items' => $pageOne, 'total' => 1001], 200);
        }

        return Http::response([
            'items' => [
                [
                    'id' => 'sub-page2-0',
                    'key' => 'KEY-P2-0',
                    'sku' => 'SKU-P2',
                    'tierDescription' => 'Tier P2',
                    'availableQuantity' => 1,
                    'quantity' => 1,
                    'subscriptionStatus' => 'ACTIVE',
                ],
            ],
            'total' => 1001,
        ], 200);
    });

    $items = $helper->collectSubscriptions(1000);

    expect($items)->toHaveCount(1001)
        ->and($items[0]['key'])->toBe('KEY-P1-0')
        ->and($items[1000]['key'])->toBe('KEY-P2-0');

    $parsed = $helper->parseSubscriptionsFromItems($items);
    expect($parsed['subscriptions'])->toHaveCount(1001);
});

test('collectDevices stops when total is reached across pages', function () {
    $client = Client::factory()->create([
        'bearer_token' => 'test-token',
        'expires_at' => now()->addHour(),
    ]);
    $helper = new GreenLakeAPIHelper($client);

    Http::fake(function (Request $request) {
        if (! str_contains($request->url(), '/devices/v1/devices') || $request->method() !== 'GET') {
            return Http::response([], 404);
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $offset = (int) ($query['offset'] ?? 0);

        if ($offset === 0) {
            return Http::response([
                'items' => [
                    ['id' => 'dev-1', 'serialNumber' => 'SN-1'],
                    ['id' => 'dev-2', 'serialNumber' => 'SN-2'],
                ],
                'total' => 3,
            ], 200);
        }

        return Http::response([
            'items' => [
                ['id' => 'dev-3', 'serialNumber' => 'SN-3'],
            ],
            'total' => 3,
        ], 200);
    });

    $devices = $helper->collectDevices(2);

    expect($devices)->toHaveCount(3)
        ->and($devices[2]['serialNumber'])->toBe('SN-3');
});

test('removeDevicesFromWorkspace unassigns subscription then application', function () {
    $client = Client::factory()->create([
        'bearer_token' => 'test-token',
        'expires_at' => now()->addHour(),
    ]);

    Http::fake([
        GreenLakeAPIHelper::BASE_URL.'/*' => Http::response([], 200),
    ]);

    $helper = new GreenLakeAPIHelper($client);
    $result = $helper->removeDevicesFromWorkspace(['dev-remove-1']);

    expect($result['error'])->toBeNull()
        ->and($result['results'])->toHaveCount(1)
        ->and($result['results'][0]['success'])->toBeTrue();

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'PATCH'
            && str_contains($request->url(), 'id=dev-remove-1')
            && ($request->data()['subscription'] ?? null) === [];
    });

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'PATCH'
            && str_contains($request->url(), 'id=dev-remove-1')
            && array_key_exists('application', $request->data())
            && $request->data()['application'] === null;
    });
});

test('assignSubscriptionToDevices treats 202 as success', function () {
    $client = Client::factory()->create([
        'bearer_token' => 'test-token',
        'expires_at' => now()->addHour(),
    ]);

    Http::fake([
        GreenLakeAPIHelper::BASE_URL.'/*' => Http::response([], 202),
    ]);

    $helper = new GreenLakeAPIHelper($client);
    $result = $helper->assignSubscriptionToDevices(['dev-1'], 'sub-uuid-1');

    expect($result['error'])->toBeNull()
        ->and($result['responses'])->toHaveCount(1)
        ->and($result['responses'][0]->status())->toBe(202);
});

test('assignSubscriptionToDevices patches each device with subscription id', function () {
    $client = Client::factory()->create([
        'bearer_token' => 'test-token',
        'expires_at' => now()->addHour(),
    ]);

    Http::fake([
        GreenLakeAPIHelper::BASE_URL.'/*' => Http::response([], 200),
    ]);

    $helper = new GreenLakeAPIHelper($client);
    $result = $helper->assignSubscriptionToDevices(['dev-1', 'dev-2'], 'sub-uuid-1');

    expect($result['error'])->toBeNull()
        ->and($result['responses'])->toHaveCount(2);

    Http::assertSent(function (Request $request): bool {
        if ($request->method() !== 'PATCH') {
            return false;
        }

        return str_contains($request->url(), '/devices/v1/devices')
            && str_contains($request->url(), 'id=dev-')
            && ($request->data()['subscription'][0]['id'] ?? '') === 'sub-uuid-1';
    });
});

test('unassignSubscriptionFromDevices sends empty subscription array', function () {
    $client = Client::factory()->create([
        'bearer_token' => 'test-token',
        'expires_at' => now()->addHour(),
    ]);

    Http::fake([
        GreenLakeAPIHelper::BASE_URL.'/*' => Http::response([], 200),
    ]);

    $helper = new GreenLakeAPIHelper($client);
    $result = $helper->unassignSubscriptionFromDevices(['dev-1']);

    expect($result['error'])->toBeNull();

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'PATCH'
            && ($request->data()['subscription'] ?? null) === [];
    });
});

test('normalizeGreenLakeDevice maps serial and subscription from device payload', function () {
    $client = Client::factory()->create();
    $helper = new GreenLakeAPIHelper($client);

    $subscriptionsByGreenLakeId = [
        'sub-uuid-1' => ['subscription_key' => 'KEY-ABC'],
    ];

    $normalized = $helper->normalizeGreenLakeDevice([
        'id' => 'dev-uuid-1',
        'serialNumber' => 'SN001',
        'macAddress' => '00:11:22:33:44:55',
        'model' => '6300',
        'deviceType' => 'SWITCH',
        'subscription' => [['id' => 'sub-uuid-1']],
        'tier' => 'advanced_switch',
    ], $subscriptionsByGreenLakeId);

    expect($normalized['serial'])->toBe('SN001')
        ->and($normalized['greenlake_device_id'])->toBe('dev-uuid-1')
        ->and($normalized['subscription_key'])->toBe('KEY-ABC')
        ->and($normalized['licensed'])->toBeTrue()
        ->and($normalized['assigned_services'])->toContain('advanced_switch');
});

test('addNetworkDevices posts network payload and polls async operation until succeeded', function () {
    $client = Client::factory()->create([
        'bearer_token' => 'test-token',
        'expires_at' => now()->addHour(),
    ]);
    $helper = new GreenLakeAPIHelper($client);

    Http::fake([
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/devices' => Http::response([
            'code' => 202,
            'status' => '202 ACCEPTED',
            'transactionId' => 'async-op-1',
        ], 202, [
            'Location' => '/devices/v1/async-operations/async-op-1',
        ]),
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/async-operations/async-op-1' => Http::response([
            'id' => 'async-op-1',
            'type' => 'devices/asyncOperation',
            'status' => 'SUCCEEDED',
            'progressPercent' => 100,
            'suggestedPollingIntervalSeconds' => 0,
            'result' => [
                'succeeded' => [['serialNumber' => 'SN001']],
            ],
        ], 200),
    ]);

    $result = $helper->addNetworkDevices([
        ['serial' => 'SN001', 'mac_address' => 'aa:bb:cc:dd:ee:ff'],
    ], sleepBetweenPolls: false);

    expect($result['success'])->toBeTrue()
        ->and($result['transaction_id'])->toBe('async-op-1')
        ->and($result['status'])->toBe('SUCCEEDED')
        ->and($result['results']['SN001'])->toBeTrue();

    Http::assertSent(function (Request $request) {
        if ($request->method() !== 'POST' || ! str_contains($request->url(), '/devices/v1/devices')) {
            return false;
        }

        $body = $request->data();

        return ($body['network'][0]['serialNumber'] ?? null) === 'SN001'
            && ($body['network'][0]['macAddress'] ?? null) === 'aa:bb:cc:dd:ee:ff'
            && ($body['compute'] ?? null) === []
            && ($body['storage'] ?? null) === [];
    });
});

test('addNetworkDevices marks failed async operation as unsuccessful', function () {
    $client = Client::factory()->create([
        'bearer_token' => 'test-token',
        'expires_at' => now()->addHour(),
    ]);
    $helper = new GreenLakeAPIHelper($client);

    Http::fake([
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/devices' => Http::response([
            'transactionId' => 'async-op-fail',
        ], 202),
        GreenLakeAPIHelper::BASE_URL.'/devices/v1/async-operations/async-op-fail' => Http::response([
            'id' => 'async-op-fail',
            'status' => 'FAILED',
            'suggestedPollingIntervalSeconds' => 0,
            'result' => [
                'failed' => [['serialNumber' => 'SN002']],
            ],
        ], 200),
    ]);

    $result = $helper->addNetworkDevices([
        ['serial' => 'SN002', 'mac_address' => '11:22:33:44:55:66'],
    ], sleepBetweenPolls: false);

    expect($result['success'])->toBeFalse()
        ->and($result['results']['SN002'])->toBeFalse()
        ->and($result['error'])->toContain('FAILED');
});
