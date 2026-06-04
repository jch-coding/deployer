<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

use App\Helper\CentralAPIHelper;
use App\Helper\GreenLakeAPIHelper;
use App\Models\Client;
use App\Services\LicensingSyncService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * @param  array<int, array<string, mixed>>  $greenLakeDevices  Raw GreenLake device items (serialNumber, id, etc.)
 * @param  array<int, array<string, mixed>>  $greenLakeSubscriptions  Raw GreenLake subscription items (key, id, tierDescription, etc.)
 * @param  array<int, array<string, mixed>>  $centralSubscriptions  Central classic subscription rows
 */
function fakeLicensingApis(
    array $greenLakeDevices = [],
    array $greenLakeSubscriptions = [],
    array $centralSubscriptions = [],
): void {
    Http::fake(function (Request $request) use ($greenLakeDevices, $greenLakeSubscriptions, $centralSubscriptions) {
        if (str_contains($request->url(), GreenLakeAPIHelper::BASE_URL)) {
            if (str_contains($request->url(), '/subscriptions/v1/subscriptions') && $request->method() === 'GET') {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $offset = (int) ($query['offset'] ?? 0);
                $limit = (int) ($query['limit'] ?? 1000);
                $page = array_slice($greenLakeSubscriptions, $offset, $limit);

                return Http::response([
                    'items' => $page,
                    'total' => count($greenLakeSubscriptions),
                ], 200);
            }

            if (str_contains($request->url(), '/devices/v1/devices') && $request->method() === 'GET') {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $offset = (int) ($query['offset'] ?? 0);
                $limit = (int) ($query['limit'] ?? 1000);
                $page = array_slice($greenLakeDevices, $offset, $limit);

                return Http::response([
                    'items' => $page,
                    'total' => count($greenLakeDevices),
                ], 200);
            }

            if (str_contains($request->url(), '/devices/v1/devices') && $request->method() === 'PATCH') {
                return Http::response([], 200);
            }

            return Http::response([], 200);
        }

        if (str_contains($request->url(), '/platform/licensing/v1/services/enabled')) {
            return Http::response([
                'services' => [
                    'services' => ['advanced_ap', 'advanced_switch_6300'],
                ],
            ], 200);
        }

        if (str_contains($request->url(), '/platform/licensing/v1/subscriptions')
            && $request->method() === 'GET') {
            return Http::response([
                'subscriptions' => $centralSubscriptions,
            ], 200);
        }

        return Http::response([], 404);
    });
}

/**
 * @deprecated Use fakeLicensingApis() with GreenLake-shaped device/subscription payloads.
 */
function fakeLicensingCentralApis(array $devices = [], array $subscriptions = []): void
{
    $greenLakeDevices = array_map(function (array $device): array {
        $subscriptionKey = (string) ($device['subscription_key'] ?? '');
        $payload = [
            'id' => (string) ($device['greenlake_device_id'] ?? 'gl-dev-'.($device['serial'] ?? 'unknown')),
            'serialNumber' => (string) ($device['serial'] ?? ''),
            'macAddress' => (string) ($device['mac'] ?? ''),
            'model' => (string) ($device['model'] ?? ''),
            'deviceType' => (string) ($device['device_type'] ?? ''),
            'name' => (string) ($device['name'] ?? $device['serial'] ?? ''),
            'tier' => is_array($device['services'] ?? null) ? ($device['services'][0] ?? '') : '',
        ];
        if ($subscriptionKey !== '') {
            $payload['subscription'] = [['key' => $subscriptionKey]];
        }

        return $payload;
    }, $devices);

    $greenLakeSubscriptions = array_map(function (array $subscription): array {
        return [
            'id' => (string) ($subscription['greenlake_subscription_id'] ?? 'gl-sub-'.($subscription['subscription_key'] ?? 'unknown')),
            'key' => (string) ($subscription['subscription_key'] ?? ''),
            'sku' => (string) ($subscription['sku'] ?? $subscription['subscription_sku'] ?? ''),
            'tierDescription' => (string) ($subscription['license_type'] ?? ''),
            'quantity' => (int) ($subscription['quantity'] ?? 10),
            'availableQuantity' => (int) ($subscription['available'] ?? 0),
            'subscriptionStatus' => (string) ($subscription['status'] ?? 'OK'),
            'startTime' => isset($subscription['start_date']) ? date('c', (int) ($subscription['start_date'] / 1000)) : null,
            'endTime' => isset($subscription['end_date']) ? date('c', (int) ($subscription['end_date'] / 1000)) : null,
            'productType' => (string) ($subscription['subscription_type'] ?? ''),
            'tags' => $subscription['tags'] ?? [],
        ];
    }, $subscriptions);

    fakeLicensingApis($greenLakeDevices, $greenLakeSubscriptions, $subscriptions);
}

function seedLicensingCache(Client $client, array $devices = [], array $subscriptions = []): void
{
    fakeLicensingCentralApis($devices, $subscriptions);
    $client = $client->fresh()->load('user');
    $client->update([
        'bearer_token' => 'test-greenlake-token',
        'expires_at' => now()->addHour(),
    ]);
    app(LicensingSyncService::class)->syncFromCentral(
        $client,
        new CentralAPIHelper($client),
        new GreenLakeAPIHelper($client),
    );
    $client->refresh();
}
