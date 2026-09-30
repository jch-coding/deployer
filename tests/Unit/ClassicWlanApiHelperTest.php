<?php

use App\ClassicBaseUrl;
use App\Helper\CentralAPIHelper;
use App\Models\Client;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function makeClassicWlanApiHelper(bool $tokenOk = true): CentralAPIHelper
{
    $client = mock(Client::class)->makePartial();
    $client->classic_base_url = ClassicBaseUrl::US1->value;
    $client->classic_access_token = 'test-access-token';
    $client->shouldReceive('handleClassicBearerToken')->andReturn($tokenOk);

    return new CentralAPIHelper($client);
}

it('gets full wlan from classic central', function () {
    Http::fake([
        '*configuration/full_wlan/Group_1/wlan_1*' => Http::response([
            'value' => '{"wlan":{"essid":"wlan1"}}',
        ], 200),
    ]);

    $helper = makeClassicWlanApiHelper();
    $response = $helper->classic_get_full_wlan('Group_1', 'wlan_1');

    expect($response->successful())->toBeTrue();

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), 'configuration/full_wlan/Group_1/wlan_1')
            && $request->method() === 'GET';
    });
});

it('posts create wlan v2 payload to classic central', function () {
    Http::fake([
        '*configuration/v2/wlan/Group_1/wlan_1*' => Http::response([], 200),
    ]);

    $body = [
        'wlan' => [
            'essid' => 'ssid_name',
            'type' => 'employee',
            'hide_ssid' => false,
            'vlan' => '10',
            'wpa_passphrase' => '1234567890',
            'wpa_passphrase_changed' => true,
        ],
    ];

    $helper = makeClassicWlanApiHelper();
    $response = $helper->classic_create_wlan_v2('Group_1', 'wlan_1', $body);

    expect($response->successful())->toBeTrue();

    Http::assertSent(function (Request $request) use ($body): bool {
        return str_contains($request->url(), 'configuration/v2/wlan/Group_1/wlan_1')
            && $request->method() === 'POST'
            && $request->data() === $body;
    });
});

it('returns error and sends nothing when classic token fails for wlan helpers', function () {
    Http::fake();

    $helper = makeClassicWlanApiHelper(tokenOk: false);

    expect($helper->classic_get_full_wlan('Group_1', 'wlan_1'))
        ->toBe(['error' => 'failed to get access token from central.'])
        ->and($helper->classic_create_wlan_v2('Group_1', 'wlan_1', ['wlan' => ['essid' => 'x', 'type' => 'employee']]))
        ->toBe(['error' => 'failed to get access token from central.']);

    Http::assertNothingSent();
});
