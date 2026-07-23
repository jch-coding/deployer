<?php

use App\BaseURL;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create();
    $this->client = Client::factory()->for($this->user)->create([
        'current' => true,
        'base_url' => BaseURL::US1,
        'bearer_token' => 'test-bearer-token',
        'expires_at' => now()->addHour(),
    ]);
    $this->actingAs($this->user);
    seedCentralScopeCache($this->client);
});

test('device details index redirects when no current client is set', function () {
    $this->client->update(['current' => false]);

    $this->get(route('device-details.index'))
        ->assertRedirect(route('clients.index'));
});

test('device details index renders without devices when no filters are applied', function () {
    $this->get(route('device-details.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('DeviceDetails/Index')
            ->where('devices', [])
            ->where('has_active_filters', false)
            ->has('site_options', 1)
            ->where('site_options.0.siteId', 'scope-site')
            ->where('site_options.0.siteName', 'Central Site')
            ->has('central_sites_cache.refreshed_at')
            ->has('central_groups_cache.refreshed_at'));
});

test('device details index fetches devices when filters are applied', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'network-monitoring/v1/devices')) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            expect($query['filter'] ?? null)->toBe('siteId eq scope-site and status eq ONLINE');

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

    $this->get(route('device-details.index', [
        'site_id' => 'scope-site',
        'status' => 'ONLINE',
        'submitted' => true,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('DeviceDetails/Index')
            ->where('has_active_filters', true)
            ->where('filters.site_id', 'scope-site')
            ->where('filters.status', 'ONLINE')
            ->has('devices', 1)
            ->where('devices.0.deviceName', 'Switch-A')
            ->where('devices.0.serialNumber', 'SN12345')
            ->where('devices.0.status', 'ONLINE')
            ->where('devices.0.deployment', 'Standalone')
            ->where('devices.0.siteName', 'HQ'));
});

test('device details index does not fetch devices when filters are applied without submission', function () {
    Http::fake(function (Request $request) {
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

    $this->get(route('device-details.index', [
        'site_id' => 'scope-site',
        'status' => 'ONLINE',
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('DeviceDetails/Index')
            ->where('has_active_filters', true)
            ->where('filters.site_id', 'scope-site')
            ->where('filters.status', 'ONLINE')
            ->where('devices', []));

    Http::assertNothingSent();
});

test('device details index rejects invalid filter enums', function () {
    $this->get(route('device-details.index', ['device_type' => 'INVALID']))
        ->assertSessionHasErrors('device_type');
});

test('device details show maps interface fields for a single serial', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'network-monitoring/v1/devices')) {
            return Http::response([
                'items' => [[
                    'deviceName' => 'Switch-A',
                    'serialNumber' => 'SN12345',
                ]],
                'next' => null,
                'total' => 1,
                'count' => 1,
            ], 200);
        }

        if (str_contains($request->url(), 'network-monitoring/v1/switches/SN12345/interfaces')) {
            return Http::response([
                'items' => [[
                    'name' => '1/1/1',
                    'status' => 'Connected',
                    'operStatus' => 'Up',
                    'neighbour' => 'AP-1',
                    'neighbourSerial' => 'APSN1',
                    'vlanMode' => 'Trunk',
                    'allowedVlanIds' => [10, 20],
                    'nativeVlan' => 1,
                    'poeClass' => 'Class4',
                    'neighbourFamily' => 'Aruba',
                    'neighbourFunction' => 'AP',
                    'neighbourType' => 'Access Point',
                    'transceiverType' => 'SFP',
                ]],
                'total' => 1,
                'offset' => null,
            ], 200);
        }

        return Http::response([], 404);
    });

    $this->get(route('device-details.show', ['serials' => ['SN12345']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('DeviceDetails/Show')
            ->has('switches', 1)
            ->where('switches.0.serial', 'SN12345')
            ->where('switches.0.device_name', 'Switch-A')
            ->where('switches.0.central_error', null)
            ->has('switches.0.interfaces', 1)
            ->where('switches.0.interfaces.0.name', '1/1/1')
            ->where('switches.0.interfaces.0.status', 'Connected')
            ->where('switches.0.interfaces.0.operStatus', 'Up')
            ->where('switches.0.interfaces.0.neighbour', 'AP-1')
            ->where('switches.0.interfaces.0.neighbourSerial', 'APSN1')
            ->where('switches.0.interfaces.0.vlanMode', 'Trunk')
            ->where('switches.0.interfaces.0.allowedVlanIds', [10, 20])
            ->where('switches.0.interfaces.0.nativeVlan', '1')
            ->where('switches.0.interfaces.0.poeClass', 'Class4')
            ->where('switches.0.interfaces.0.neighbourFamily', 'Aruba')
            ->where('switches.0.interfaces.0.neighbourFunction', 'AP')
            ->where('switches.0.interfaces.0.neighbourType', 'Access Point')
            ->where('switches.0.interfaces.0.transceiverType', 'SFP'));
});

test('device details show maps interfaces for multiple serials', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'network-monitoring/v1/devices')) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $filter = (string) ($query['filter'] ?? '');

            if (str_contains($filter, 'SN111')) {
                return Http::response([
                    'items' => [['deviceName' => 'Switch-One', 'serialNumber' => 'SN111']],
                    'next' => null,
                    'total' => 1,
                    'count' => 1,
                ], 200);
            }

            if (str_contains($filter, 'SN222')) {
                return Http::response([
                    'items' => [['deviceName' => 'Switch-Two', 'serialNumber' => 'SN222']],
                    'next' => null,
                    'total' => 1,
                    'count' => 1,
                ], 200);
            }
        }

        if (str_contains($request->url(), 'network-monitoring/v1/switches/SN111/interfaces')) {
            return Http::response([
                'items' => [[
                    'name' => '1/1/1',
                    'status' => 'Connected',
                    'operStatus' => 'Up',
                    'neighbour' => '',
                    'neighbourSerial' => '',
                    'vlanMode' => 'Access',
                    'allowedVlanIds' => [10],
                    'nativeVlan' => 10,
                    'poeClass' => '',
                    'neighbourFamily' => '',
                    'neighbourFunction' => '',
                    'neighbourType' => '',
                    'transceiverType' => '',
                ]],
                'total' => 1,
                'offset' => null,
            ], 200);
        }

        if (str_contains($request->url(), 'network-monitoring/v1/switches/SN222/interfaces')) {
            return Http::response([
                'items' => [[
                    'name' => '1/1/2',
                    'status' => 'Not Connected',
                    'operStatus' => 'Down',
                    'neighbour' => '',
                    'neighbourSerial' => '',
                    'vlanMode' => 'Trunk',
                    'allowedVlanIds' => [20, 30],
                    'nativeVlan' => 1,
                    'poeClass' => 'Class3',
                    'neighbourFamily' => '',
                    'neighbourFunction' => '',
                    'neighbourType' => '',
                    'transceiverType' => 'SFP',
                ]],
                'total' => 1,
                'offset' => null,
            ], 200);
        }

        return Http::response([], 404);
    });

    $this->get(route('device-details.show', ['serials' => ['SN111', 'SN222']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('DeviceDetails/Show')
            ->has('switches', 2)
            ->where('switches.0.serial', 'SN111')
            ->where('switches.0.device_name', 'Switch-One')
            ->where('switches.0.interfaces.0.name', '1/1/1')
            ->where('switches.1.serial', 'SN222')
            ->where('switches.1.device_name', 'Switch-Two')
            ->where('switches.1.interfaces.0.name', '1/1/2')
            ->where('switches.1.interfaces.0.allowedVlanIds', [20, 30]));
});

test('device details show keeps per-switch errors isolated', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'network-monitoring/v1/devices')) {
            return Http::response([
                'items' => [['deviceName' => 'Switch-A', 'serialNumber' => 'SN12345']],
                'next' => null,
                'total' => 1,
                'count' => 1,
            ], 200);
        }

        if (str_contains($request->url(), 'network-monitoring/v1/switches/SN12345/interfaces')) {
            return Http::response([
                'items' => [[
                    'name' => '1/1/1',
                    'status' => 'Connected',
                    'operStatus' => 'Up',
                    'neighbour' => '',
                    'neighbourSerial' => '',
                    'vlanMode' => '',
                    'allowedVlanIds' => [],
                    'nativeVlan' => '',
                    'poeClass' => '',
                    'neighbourFamily' => '',
                    'neighbourFunction' => '',
                    'neighbourType' => '',
                    'transceiverType' => '',
                ]],
                'total' => 1,
                'offset' => null,
            ], 200);
        }

        if (str_contains($request->url(), 'network-monitoring/v1/switches/SN999/interfaces')) {
            return Http::response(['detail' => 'not found'], 404);
        }

        return Http::response([], 404);
    });

    $this->get(route('device-details.show', ['serials' => ['SN12345', 'SN999']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('DeviceDetails/Show')
            ->has('switches', 2)
            ->where('switches.0.serial', 'SN12345')
            ->where('switches.0.central_error', null)
            ->has('switches.0.interfaces', 1)
            ->where('switches.1.serial', 'SN999')
            ->where('switches.1.interfaces', [])
            ->where('switches.1.central_error', 'failed to get switch interfaces from central.'));
});

test('device details legacy serial path redirects to show with serials query', function () {
    $this->get(route('device-details.redirect-show', ['serial' => 'SN12345']))
        ->assertRedirect(route('device-details.show', ['serials' => ['SN12345']]));
});

test('device details show requires serials', function () {
    $this->get(route('device-details.show'))
        ->assertSessionHasErrors('serials');
});

test('device details show rejects too many serials', function () {
    $serials = [];
    for ($i = 1; $i <= 26; $i++) {
        $serials[] = 'SN'.str_pad((string) $i, 3, '0', STR_PAD_LEFT);
    }

    $this->get(route('device-details.show', ['serials' => $serials]))
        ->assertSessionHasErrors('serials');
});

test('device details show redirects when no current client is set', function () {
    $this->client->update(['current' => false]);

    $this->get(route('device-details.show', ['serials' => ['SN12345']]))
        ->assertRedirect(route('clients.index'));
});
