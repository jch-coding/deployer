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
use App\Models\Client;
use App\Services\LicensingSyncService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function fakeLicensingCentralApis(array $devices = [], array $subscriptions = []): void
{
    Http::fake(function (Request $request) use ($devices, $subscriptions) {
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
                'subscriptions' => $subscriptions,
            ], 200);
        }

        if (str_contains($request->url(), '/platform/device_inventory/v1/devices')) {
            return Http::response([
                'total' => count($devices),
                'devices' => $devices,
            ], 200);
        }

        return Http::response([], 404);
    });
}

function seedLicensingCache(Client $client, array $devices = [], array $subscriptions = []): void
{
    fakeLicensingCentralApis($devices, $subscriptions);
    $client = $client->fresh();
    app(LicensingSyncService::class)->syncFromCentral($client, new CentralAPIHelper($client));
    $client->refresh();
}
