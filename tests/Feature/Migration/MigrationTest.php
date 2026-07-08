<?php

use App\BaseURL;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
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

test('migrations index redirects when no current client is set', function () {
    $this->client->update(['current' => false]);

    $this->get(route('migrations.index'))
        ->assertRedirect(route('clients.index'));
});

test('migrations index renders with site options', function () {
    $this->get(route('migrations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Migration/Index')
            ->where('parsed_controllers', [])
            ->where('deploy_results', [])
            ->has('site_options', 1)
            ->where('site_options.0.siteId', 'scope-site')
            ->where('site_options.0.siteName', 'Central Site'));
});

test('migrations parse uploads config file and returns parsed controllers', function () {
    $file = new UploadedFile(
        base_path('tests/fixtures/daytona_config.txt'),
        'daytona_config.txt',
        'text/plain',
        null,
        true,
    );

    $this->post(route('migrations.parse'), [
        'config_file' => $file,
    ])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Migration/Index')
            ->has('parsed_controllers', 1)
            ->where('parsed_controllers.0.controller_name', 'DAY-HUB-WLC1')
            ->has('parsed_controllers.0.devices', 106)
            ->has('parsed_controllers.0.lldp_neighbors')
            ->has('parsed_controllers.0.wlan_profiles'));
});

test('migrations deploy wlan posts profiles to central with scope query parameters', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $body = [
        'essid' => ['name' => 'DAYKIT'],
        'g-legacy-rates' => ['basic-rates' => [], 'tx-rates' => []],
        'opmode' => 'WPA2_PERSONAL',
        'personal-security' => [
            'passphrase-format' => 'STRING',
            'wpa-passphrase' => 'secret-passphrase',
        ],
        'type' => 'EMPLOYEE',
        'internal-auth-server' => 'INTERNAL_SERVER',
        'vlan-name' => 'WCD_KIT',
        'vlan-selector' => 'NAMED_VLAN',
        'enable' => true,
        'ssid' => 'DAYKIT_ssid_prof',
        'a-legacy-rates' => ['basic-rates' => ['RATE_12MB'], 'tx-rates' => ['RATE_12MB']],
    ];

    $this->post(route('migrations.deploy-wlan'), [
        'scope_id' => 'scope-site',
        'profiles' => [
            [
                'ssid_profile_name' => 'DAYKIT_ssid_prof',
                'body' => $body,
            ],
        ],
        'parsed_controllers' => [],
    ])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Migration/Index')
            ->has('deploy_results', 1)
            ->where('deploy_results.0.ssid', 'DAYKIT_ssid_prof')
            ->where('deploy_results.0.status', 'success'));

    Http::assertSent(function (Request $request) use ($body) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return $request->method() === 'POST'
            && str_contains($request->url(), 'network-config/v1alpha1/wlan-ssids/DAYKIT_ssid_prof')
            && ($query['object-type'] ?? null) === 'LOCAL'
            && ($query['view-type'] ?? null) === 'LOCAL'
            && ($query['scope-id'] ?? null) === 'scope-site'
            && ($query['device-function'] ?? null) === 'CAMPUS_AP'
            && json_decode($request->body(), true) === $body;
    });
});

test('migrations deploy wlan skips profiles missing passphrase or vlan', function () {
    Http::fake();

    $this->post(route('migrations.deploy-wlan'), [
        'scope_id' => 'scope-site',
        'profiles' => [
            [
                'ssid_profile_name' => 'incomplete-profile',
                'body' => [
                    'vlan-name' => null,
                    'personal-security' => ['wpa-passphrase' => null],
                ],
            ],
        ],
        'parsed_controllers' => [],
    ])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('deploy_results.0.status', 'skipped'));

    Http::assertNothingSent();
});
