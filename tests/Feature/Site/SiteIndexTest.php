<?php

use App\BaseURL;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = Client::factory()->for($this->user)->create([
        'current' => true,
        'base_url' => BaseURL::US1,
        'bearer_token' => 'test-bearer-token',
        'expires_at' => now()->addHour(),
    ]);
    $this->actingAs($this->user);
});

test('sites index redirects when no current client is set', function () {
    $this->client->update(['current' => false]);

    $this->get(route('sites.index'))
        ->assertRedirect(route('clients.index'));
});

test('sites index renders without devices when no filters are applied', function () {
    Http::fake([
        '*network-config/v1/sites*' => Http::response([
            'items' => [
                ['scopeName' => 'HQ', 'scopeId' => 'scope-hq'],
            ],
        ], 200),
    ]);

    $this->get(route('sites.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Site/Index')
            ->where('devices', [])
            ->where('has_active_filters', false)
            ->has('site_options', 1)
            ->where('site_options.0.siteId', 'scope-hq')
            ->where('site_options.0.siteName', 'HQ'));
});

test('sites index fetches devices when filters are applied', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'network-config/v1/sites')) {
            return Http::response([
                'items' => [
                    ['scopeName' => 'HQ', 'scopeId' => 'scope-hq'],
                ],
            ], 200);
        }

        if (str_contains($request->url(), 'network-monitoring/v1/devices')) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            expect($query['filter'] ?? null)->toBe('siteId eq scope-hq and status eq ONLINE');

            return Http::response([
                'items' => [[
                    'deviceName' => 'Switch-A',
                    'serialNumber' => 'SN12345',
                    'deviceFunction' => 'ACCESS_SWITCH',
                    'model' => '6300',
                    'ipv4' => '10.0.0.1',
                    'status' => 'ONLINE',
                    'deployment' => 'Standalone',
                    'siteName' => 'HQ',
                ]],
                'next' => null,
                'total' => 1,
                'count' => 1,
            ], 200);
        }

        return Http::response([], 404);
    });

    $this->get(route('sites.index', [
        'site_id' => 'scope-hq',
        'status' => 'ONLINE',
        'submitted' => true,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Site/Index')
            ->where('has_active_filters', true)
            ->where('filters.site_id', 'scope-hq')
            ->where('filters.status', 'ONLINE')
            ->has('devices', 1)
            ->where('devices.0.deviceName', 'Switch-A')
            ->where('devices.0.serialNumber', 'SN12345')
            ->where('devices.0.status', 'ONLINE')
            ->where('devices.0.deployment', 'Standalone')
            ->where('devices.0.siteName', 'HQ'));
});

test('sites index does not fetch devices when filters are applied without submission', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'network-config/v1/sites')) {
            return Http::response([
                'items' => [
                    ['scopeName' => 'HQ', 'scopeId' => 'scope-hq'],
                ],
            ], 200);
        }

        if (str_contains($request->url(), 'network-monitoring/v1/devices')) {
            return Http::response([
                'items' => [[
                    'deviceName' => 'Switch-A',
                    'serialNumber' => 'SN12345',
                    'deviceFunction' => 'ACCESS_SWITCH',
                    'model' => '6300',
                    'ipv4' => '10.0.0.1',
                    'status' => 'ONLINE',
                    'deployment' => 'Standalone',
                    'siteName' => 'HQ',
                ]],
                'next' => null,
                'total' => 1,
                'count' => 1,
            ], 200);
        }

        return Http::response([], 404);
    });

    $this->get(route('sites.index', [
        'site_id' => 'scope-hq',
        'status' => 'ONLINE',
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Site/Index')
            ->where('has_active_filters', true)
            ->where('filters.site_id', 'scope-hq')
            ->where('filters.status', 'ONLINE')
            ->where('devices', []));

    Http::assertSentCount(1);
});

test('sites index rejects invalid filter enums', function () {
    Http::fake([
        '*network-config/v1/sites*' => Http::response(['items' => []], 200),
    ]);

    $this->get(route('sites.index', ['device_type' => 'INVALID']))
        ->assertSessionHasErrors('device_type');
});
