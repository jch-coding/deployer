<?php

use App\ClassicBaseUrl;
use App\Helper\CentralAPIHelper;
use App\Models\Client;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function makeClassicSiteApiHelper(bool $tokenOk = true): CentralAPIHelper
{
    $client = mock(Client::class)->makePartial();
    $client->classic_base_url = ClassicBaseUrl::US1->value;
    $client->classic_access_token = 'test-access-token';
    $client->shouldReceive('handleClassicBearerToken')->andReturn($tokenOk);

    return new CentralAPIHelper($client);
}

it('posts create site payload to classic central', function () {
    Http::fake([
        '*central/v2/sites' => Http::response(['site_id' => 42], 201),
    ]);

    $helper = makeClassicSiteApiHelper();
    $response = $helper->classic_create_site([
        'site_name' => 'Warehouse',
        'site_address' => [
            'address' => '123 Main',
            'city' => 'Denver',
            'state' => 'CO',
            'country' => 'US',
            'zipcode' => '80202',
        ],
    ]);

    expect($response->successful())->toBeTrue();

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), 'central/v2/sites')
            && $request->method() === 'POST'
            && $request->data()['site_name'] === 'Warehouse';
    });
});

it('patches update site payload to classic central', function () {
    Http::fake([
        '*central/v2/sites/77' => Http::response(['site_id' => 77], 200),
    ]);

    $helper = makeClassicSiteApiHelper();
    $response = $helper->classic_update_site(77, [
        'site_name' => 'Warehouse',
        'site_address' => [
            'address' => '456 Oak',
            'city' => 'Denver',
            'state' => 'CO',
            'country' => 'US',
            'zipcode' => '80203',
        ],
    ]);

    expect($response->successful())->toBeTrue();

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), 'central/v2/sites/77')
            && $request->method() === 'PATCH'
            && $request->data()['site_address']['address'] === '456 Oak';
    });
});
