<?php

use App\Models\Client;
use App\Models\Device;
use App\Services\ClientDetailsLookupService;
use App\BaseURL;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function makeClientDetailsLookupService(): ClientDetailsLookupService
{
    return new ClientDetailsLookupService(
        pollIntervalUs: 0,
        maxPollAttempts: 5,
        sleeper: static fn () => null,
    );
}

function makeClientForClientDetails(): Client
{
    return Client::factory()->create([
        'expires_at' => now()->addHour(),
        'bearer_token' => 'test-bearer-token',
        'base_url' => BaseURL::US1->value,
    ]);
}

$macTableOutput = <<<'OUTPUT'
MAC age-time            : 300 seconds
Number of MAC addresses : 2

MAC Address          VLAN     Type                      Port      
--------------------------------------------------------------
88:22:5b:7d:ff:00    1        dynamic                   1/1/1     
aa:bb:cc:dd:ee:01    10       dynamic                   1/1/1     
OUTPUT;

test('client details lookup resolves direct neighbour serial MAC', function () {
    Http::fake(function (Request $request) {
        expect($request->url())->toContain('network-monitoring/v1/clients/'.rawurlencode('05:50:35:a1:a0:01'));

        return Http::response([
            'ipv4' => '10.0.0.5',
            'clientVendor' => 'Android',
            'port' => null,
            'vlanId' => '100',
            'clientOperatingSystem' => 'Android',
            'clientFunction' => 'Mobile',
            'role' => 'wireless',
            'clientTags' => 'tag1,tag2',
            'clientManufacturer' => 'Samsung',
            'clientCategory' => 'Smart Device',
            'authenticationType' => 'WPA2',
        ], 200);
    });

    $client = makeClientForClientDetails();
    $service = makeClientDetailsLookupService();

    $result = $service->lookup($client, 'SN12345', [[
        'name' => '1/1/10',
        'neighbour' => 'Phone',
        'neighbourSerial' => '05:50:35:a1:a0:01',
    ]]);

    expect($result['errors'])->toBe([])
        ->and($result['rows'])->toHaveCount(1)
        ->and($result['rows'][0]['macAddress'])->toBe('05:50:35:a1:a0:01')
        ->and($result['rows'][0]['interface'])->toBe('1/1/10')
        ->and($result['rows'][0]['ipv4'])->toBe('10.0.0.5')
        ->and($result['rows'][0]['clientTags'])->toBe('tag1,tag2');

    Http::assertSentCount(1);
});

test('client details lookup extracts MAC from tpd_ neighbour serial', function () {
    Http::fake(function (Request $request) {
        expect($request->url())->toContain('network-monitoring/v1/clients/'.rawurlencode('aa:bb:cc:dd:ee:ff'));

        return Http::response([
            'ipv4' => '10.0.0.9',
            'clientVendor' => '',
            'port' => '',
            'vlanId' => '',
            'clientOperatingSystem' => '',
            'clientFunction' => '',
            'role' => '',
            'clientTags' => '',
            'clientManufacturer' => '',
            'clientCategory' => '',
            'authenticationType' => '',
        ], 200);
    });

    $client = makeClientForClientDetails();
    $service = makeClientDetailsLookupService();

    $result = $service->lookup($client, 'SN12345', [[
        'name' => '1/1/2',
        'neighbour' => 'Unmanaged-Peer',
        'neighbourSerial' => 'tpd_aabbccddeeff',
    ]]);

    expect($result['errors'])->toBe([])
        ->and($result['rows'])->toHaveCount(1)
        ->and($result['rows'][0]['macAddress'])->toBe('aa:bb:cc:dd:ee:ff');
});

test('client details lookup resolves MAC from local device by neighbour serial', function () {
    Http::fake(function (Request $request) {
        expect($request->url())->toContain('network-monitoring/v1/clients/'.rawurlencode('11:22:33:44:55:66'));

        return Http::response([
            'ipv4' => '10.1.1.1',
            'clientVendor' => 'Apple',
            'port' => '',
            'vlanId' => '20',
            'clientOperatingSystem' => 'iOS',
            'clientFunction' => 'Mobile',
            'role' => 'employee',
            'clientTags' => '',
            'clientManufacturer' => 'Apple',
            'clientCategory' => 'Phone',
            'authenticationType' => '802.1X',
        ], 200);
    });

    $client = makeClientForClientDetails();
    Device::factory()->create([
        'client_id' => $client->id,
        'serial' => 'AP00000001',
        'mac_address' => '11:22:33:44:55:66',
    ]);

    $service = makeClientDetailsLookupService();
    $result = $service->lookup($client, 'SN12345', [[
        'name' => '1/1/3',
        'neighbour' => 'Lobby-AP',
        'neighbourSerial' => 'AP00000001',
    ]]);

    expect($result['errors'])->toBe([])
        ->and($result['rows'])->toHaveCount(1)
        ->and($result['rows'][0]['macAddress'])->toBe('11:22:33:44:55:66')
        ->and($result['rows'][0]['clientVendor'])->toBe('Apple');
});

test('client details lookup falls back to mac-address-table for unresolved ports', function () use ($macTableOutput) {
    $taskId = 'c7a3f2d1-e8a9-4b7c-8d1e-0f9a3b2c1d0e';

    Http::fake(function (Request $request) use ($taskId, $macTableOutput) {
        $url = $request->url();

        if (str_contains($url, 'network-monitoring/v1/switches')) {
            return Http::response(['items' => []], 200);
        }

        if ($request->method() === 'POST' && str_contains($url, 'showCommands')) {
            return Http::response([
                'location' => "/network-troubleshooting/v1/cx/SN12345/showCommands/async-operations/{$taskId}",
                'status' => 'INITIATED',
            ], 202);
        }

        if (str_contains($url, 'showCommands/async-operations/'.$taskId)) {
            return Http::response([
                'status' => 'COMPLETED',
                'progressPercent' => 100,
                'output' => [
                    'results' => [[
                        'command' => 'show mac-address-table',
                        'output' => $macTableOutput,
                    ]],
                ],
            ], 200);
        }

        if (str_contains($url, 'network-monitoring/v1/clients/')) {
            $mac = urldecode(basename(parse_url($url, PHP_URL_PATH) ?: ''));

            return Http::response([
                'ipv4' => '10.0.0.'.(str_contains($mac, '88:22') ? '1' : '2'),
                'clientVendor' => 'Vendor',
                'port' => '1/1/1',
                'vlanId' => '1',
                'clientOperatingSystem' => '',
                'clientFunction' => '',
                'role' => 'wired',
                'clientTags' => '',
                'clientManufacturer' => '',
                'clientCategory' => '',
                'authenticationType' => '',
            ], 200);
        }

        return Http::response(['detail' => 'unexpected'], 500);
    });

    $client = makeClientForClientDetails();
    $service = makeClientDetailsLookupService();

    $result = $service->lookup($client, 'SN12345', [[
        'name' => '1/1/1',
        'neighbour' => '',
        'neighbourSerial' => '',
    ]]);

    expect($result['errors'])->toBe([])
        ->and($result['rows'])->toHaveCount(2)
        ->and(collect($result['rows'])->pluck('macAddress')->all())->toEqualCanonicalizing([
            '88:22:5b:7d:ff:00',
            'aa:bb:cc:dd:ee:01',
        ]);
});

test('client details lookup records soft errors when central client lookup fails', function () {
    Http::fake([
        '*network-monitoring/v1/clients/*' => Http::response(['detail' => 'error'], 500),
    ]);

    $client = makeClientForClientDetails();
    $service = makeClientDetailsLookupService();

    $result = $service->lookup($client, 'SN12345', [[
        'name' => '1/1/4',
        'neighbour' => '',
        'neighbourSerial' => '05:50:35:a1:a0:01',
    ]]);

    expect($result['rows'])->toBe([])
        ->and($result['errors'])->toHaveCount(1)
        ->and($result['errors'][0]['interface'])->toBe('1/1/4')
        ->and($result['errors'][0]['message'])->toBe('failed to get client details from central.');
});
