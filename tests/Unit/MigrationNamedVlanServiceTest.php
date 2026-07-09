<?php

use App\BaseURL;
use App\Helper\CentralAPIHelper;
use App\Models\Client;
use App\Services\MigrationNamedVlanService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('identifies freezer sites excluding hub-freezer', function () {
    expect(MigrationNamedVlanService::isFreezerSite('Daytona Freezer'))->toBeTrue()
        ->and(MigrationNamedVlanService::isFreezerSite('Store Freezer 01'))->toBeTrue()
        ->and(MigrationNamedVlanService::isFreezerSite('Hub-Freezer'))->toBeFalse()
        ->and(MigrationNamedVlanService::isFreezerSite('Central Site'))->toBeFalse();
});

it('offsets single vlan id ranges by adding 200', function () {
    expect(MigrationNamedVlanService::offsetVlanIdRanges(['1', '4']))
        ->toBe(['201', '204']);
});

it('offsets both ends of vlan id range strings', function () {
    expect(MigrationNamedVlanService::offsetVlanIdRanges(['20-30', '1']))
        ->toBe(['220-230', '201']);
});

it('preserves unparseable vlan id range entries', function () {
    expect(MigrationNamedVlanService::offsetVlanIdRanges(['invalid']))
        ->toBe(['invalid']);
});

it('deploys offset named vlan profiles for each returned profile', function () {
    Http::fake([
        '*named-vlan/WCD_KIT*' => Http::response(['ok' => true], 200),
        '*named-vlan/WCD_RF*' => Http::response(['ok' => true], 200),
        '*named-vlan*' => Http::response([
            'profile' => [
                [
                    'name' => 'WCD_KIT',
                    'vlan' => ['vlan-id-ranges' => ['1', '20-30']],
                ],
                [
                    'name' => 'WCD_RF',
                    'vlan' => ['vlan-id-ranges' => ['4']],
                ],
            ],
        ], 200),
    ]);

    $client = Client::factory()->create([
        'base_url' => BaseURL::US1,
        'bearer_token' => 'test-bearer-token',
        'expires_at' => now()->addHour(),
    ]);

    $helper = new CentralAPIHelper($client);
    $results = app(MigrationNamedVlanService::class)->deployOffsetNamedVlans($helper, 'scope-freezer');

    expect($results)->toHaveCount(2)
        ->and($results[0])->toMatchArray([
            'name' => 'WCD_KIT',
            'status' => 'success',
        ])
        ->and($results[1])->toMatchArray([
            'name' => 'WCD_RF',
            'status' => 'success',
        ]);

    Http::assertSent(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return $request->method() === 'GET'
            && str_contains($request->url(), 'network-config/v1alpha1/named-vlan')
            && ! str_contains($request->url(), 'network-config/v1alpha1/named-vlan/')
            && ($query['view-type'] ?? null) === 'LOCAL'
            && ($query['scope-id'] ?? null) === 'scope-freezer'
            && ($query['device-function'] ?? null) === 'CAMPUS_AP'
            && ! array_key_exists('object-type', $query);
    });

    Http::assertSent(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return $request->method() === 'POST'
            && str_contains($request->url(), 'network-config/v1alpha1/named-vlan/WCD_KIT')
            && ($query['view-type'] ?? null) === 'LOCAL'
            && ($query['object-type'] ?? null) === 'LOCAL'
            && ($query['scope-id'] ?? null) === 'scope-freezer'
            && ($query['device-function'] ?? null) === 'CAMPUS_AP'
            && json_decode($request->body(), true) === [
                'name' => 'WCD_KIT',
                'vlan' => ['vlan-id-ranges' => ['201', '220-230']],
            ];
    });
});
