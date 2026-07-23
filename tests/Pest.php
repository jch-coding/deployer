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
 * @param  array<int, array<string, mixed>>  $centralInventoryDevices  Classic device inventory rows (serial, name, …)
 * @param  array<int, array<string, mixed>>  $newCentralDevices  New Central network-monitoring device rows (serialNumber, deviceName, …)
 * @param  array<int, array{id?: string, name?: string}>  $greenLakeLocations  Raw GreenLake location items
 * @param  array<int, array{id?: string, name?: string}>  $serviceManagers  Service catalog managers
 * @param  array<int, array<string, mixed>>  $serviceManagerProvisions  Provisioned service managers (serviceManager, region, provisionStatus)
 */
function fakeLicensingApis(
    array $greenLakeDevices = [],
    array $greenLakeSubscriptions = [],
    array $centralSubscriptions = [],
    array $centralInventoryDevices = [],
    array $newCentralDevices = [],
    array $greenLakeLocations = [],
    array $serviceManagers = [],
    array $serviceManagerProvisions = [],
): void {
    Http::fake(function (Request $request) use (
        $greenLakeDevices,
        $greenLakeSubscriptions,
        $centralSubscriptions,
        $centralInventoryDevices,
        $newCentralDevices,
        $greenLakeLocations,
        $serviceManagers,
        $serviceManagerProvisions,
    ) {
        if (str_contains($request->url(), GreenLakeAPIHelper::BASE_URL)) {
            if (str_contains($request->url(), '/locations/v1/locations') && $request->method() === 'GET') {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $offset = (int) ($query['offset'] ?? 0);
                $limit = (int) ($query['limit'] ?? 50);
                $page = array_slice($greenLakeLocations, $offset, $limit);

                return Http::response([
                    'items' => $page,
                    'total' => count($greenLakeLocations),
                ], 200);
            }

            if (str_contains($request->url(), '/service-catalog/v1/service-managers')
                && ! str_contains($request->url(), 'provisions')
                && $request->method() === 'GET') {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $offset = (int) ($query['offset'] ?? 0);
                $limit = (int) ($query['limit'] ?? 50);
                $page = array_slice($serviceManagers, $offset, $limit);

                return Http::response([
                    'items' => $page,
                    'total' => count($serviceManagers),
                ], 200);
            }

            if (str_contains($request->url(), '/service-catalog/v1/service-manager-provisions')
                && $request->method() === 'GET') {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $offset = (int) ($query['offset'] ?? 0);
                $limit = (int) ($query['limit'] ?? 50);
                $filtered = $serviceManagerProvisions;
                $filter = (string) ($query['filter'] ?? '');
                if (str_contains($filter, "status eq 'PROVISIONED'")) {
                    $filtered = array_values(array_filter(
                        $serviceManagerProvisions,
                        fn (array $item): bool => strtoupper((string) ($item['provisionStatus'] ?? $item['status'] ?? '')) === 'PROVISIONED',
                    ));
                }
                $page = array_slice($filtered, $offset, $limit);

                return Http::response([
                    'items' => $page,
                    'total' => count($filtered),
                ], 200);
            }

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

        if (str_contains($request->url(), '/platform/device_inventory/v1/devices')
            && $request->method() === 'GET') {
            return Http::response([
                'devices' => $centralInventoryDevices,
                'total' => count($centralInventoryDevices),
            ], 200);
        }

        if (str_contains($request->url(), 'network-monitoring/v1/devices')
            && $request->method() === 'GET') {
            return Http::response([
                'items' => $newCentralDevices,
                'count' => count($newCentralDevices),
                'total' => count($newCentralDevices),
                'next' => null,
            ], 200);
        }

        return Http::response([], 404);
    });
}

/**
 * @deprecated Use fakeLicensingApis() with GreenLake-shaped device/subscription payloads.
 */
/**
 * @param  array<int, array<string, mixed>>  $centralInventoryDevices
 * @param  array<int, array<string, mixed>>  $newCentralDevices
 * @param  array<int, array{id?: string, name?: string}>  $locations
 * @param  array<int, array{id?: string, name?: string}>  $serviceManagers
 * @param  array<int, array<string, mixed>>  $serviceManagerProvisions
 */
function fakeLicensingCentralApis(
    array $devices = [],
    array $subscriptions = [],
    array $centralInventoryDevices = [],
    array $newCentralDevices = [],
    array $locations = [],
    array $serviceManagers = [],
    array $serviceManagerProvisions = [],
): void {
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

    fakeLicensingApis(
        $greenLakeDevices,
        $greenLakeSubscriptions,
        $subscriptions,
        $centralInventoryDevices,
        $newCentralDevices,
        $locations,
        $serviceManagers,
        $serviceManagerProvisions,
    );
}

function seedLicensingCache(Client $client, array $devices = [], array $subscriptions = []): void
{
    $centralInventoryDevices = array_values(array_filter(array_map(function (array $device): ?array {
        $serial = (string) ($device['serial'] ?? '');
        $name = trim((string) ($device['name'] ?? ''));
        if ($serial === '' || $name === '') {
            return null;
        }

        return ['serial' => $serial, 'name' => $name];
    }, $devices)));

    fakeLicensingCentralApis($devices, $subscriptions, $centralInventoryDevices);
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

function fakeCentralScopeManagementApis(): void
{
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'network-config/v1/sites')) {
            return Http::response([
                'items' => [
                    ['scopeName' => 'Central Site', 'scopeId' => 'scope-site'],
                ],
            ], 200);
        }

        if (str_contains($request->url(), 'device-groups')) {
            return Http::response([
                'items' => [
                    ['scopeName' => 'Central Group', 'scopeId' => 'scope-group'],
                ],
            ], 200);
        }

        if (str_contains($request->url(), 'configuration/v2/groups')) {
            return Http::response([
                'data' => [['Classic Only Group', 'Central Group']],
            ], 200);
        }

        return Http::response([], 404);
    });
}

function seedCentralScopeCache(Client $client): void
{
    \App\Models\CentralScopeCache::factory()->for($client)->sites()->create();
    \App\Models\CentralScopeCache::factory()->for($client)->groups()->create();
}
