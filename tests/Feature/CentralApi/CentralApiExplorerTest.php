<?php

use App\BaseURL;
use App\Models\Client;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    app(\App\Services\CentralOpenApiRegistry::class)->clearCache();

    $this->user = User::factory()->create();
    $this->client = Client::factory()->for($this->user)->create([
        'current' => true,
        'base_url' => BaseURL::US1,
        'bearer_token' => 'test-bearer-token',
        'expires_at' => now()->addHour(),
    ]);
    $this->actingAs($this->user);
});

test('guest cannot access central api explorer', function () {
    auth()->logout();

    $this->get(route('central-api.index'))->assertRedirect(route('login'));
});

test('central api index redirects when no current client', function () {
    $this->client->update(['current' => false]);

    $this->get(route('central-api.index'))
        ->assertRedirect(route('clients.index'));
});

test('central api index renders explorer page', function () {
    $this->get(route('central-api.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('CentralApi/Explorer')
            ->has('operations_by_tag')
            ->where('base_url_display', $this->client->base_url));
});

test('execute proxies getActiveIssues with bearer token', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'sso.common.cloud.hpe.com')) {
            return Http::response(['access_token' => 'refreshed-bearer-token'], 200);
        }

        if (str_contains($request->url(), 'config-health/active-issue')) {
            expect($request->hasHeader('Authorization', 'Bearer refreshed-bearer-token'))->toBeTrue();
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            expect($query['serial'] ?? null)->toBe('SN123');

            return Http::response(['configPullFailures' => []], 200);
        }

        return Http::response([], 404);
    });

    $this->postJson(route('central-api.execute'), [
        'operation_id' => 'getActiveIssues',
        'query' => ['serial' => 'SN123'],
    ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('status', 200)
        ->assertJsonPath('body.configPullFailures', []);
});

test('execute response does not leak client secrets', function () {
    Http::fake([
        'sso.common.cloud.hpe.com/*' => Http::response(['access_token' => 'refreshed-bearer-token'], 200),
        '*config-health/active-issue*' => Http::response(['ok' => true], 200),
    ]);

    $response = $this->postJson(route('central-api.execute'), [
        'operation_id' => 'getActiveIssues',
        'query' => ['serial' => 'SN123'],
    ]);

    $encoded = json_encode($response->json());

    expect($encoded)->not->toContain('client_secret')
        ->and($encoded)->not->toContain('test-bearer-token');
});

test('execute rejects unknown operation id', function () {
    $this->postJson(route('central-api.execute'), [
        'operation_id' => 'unknownOperation',
        'query' => [],
    ])
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 422);
});

test('execute requires current client', function () {
    $this->client->update(['current' => false]);

    $this->postJson(route('central-api.execute'), [
        'operation_id' => 'getActiveIssues',
        'query' => ['serial' => 'SN123'],
    ])->assertForbidden();
});

test('execute validates required serial parameter', function () {
    $this->postJson(route('central-api.execute'), [
        'operation_id' => 'getActiveIssues',
        'query' => [],
    ])
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error', 'Missing required parameter [serial].');
});

test('execute proxies createPortchannel post with body and query parameters', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'sso.common.cloud.hpe.com')) {
            return Http::response(['access_token' => 'refreshed-bearer-token'], 200);
        }

        if (
            $request->method() === 'POST'
            && str_contains($request->url(), 'portchannels/lag1')
            && str_contains($request->url(), 'scope-id=scope-123')
        ) {
            expect($request->hasHeader('Authorization', 'Bearer refreshed-bearer-token'))->toBeTrue();
            expect($request->data())->toBe(['name' => 'lag1', 'enable' => true]);

            return Http::response(['name' => 'lag1'], 200);
        }

        return Http::response([], 404);
    });

    $this->postJson(route('central-api.execute'), [
        'operation_id' => 'createPortchannel',
        'query' => [
            'name' => 'lag1',
            'scope-id' => 'scope-123',
            'view-type' => 'LOCAL',
            'object-type' => 'LOCAL',
            'device-function' => 'ACCESS_SWITCH',
        ],
        'body' => [
            'name' => 'lag1',
            'enable' => true,
        ],
    ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('status', 200)
        ->assertJsonPath('body.name', 'lag1');
});

test('execute rejects write operations without required body', function () {
    $this->postJson(route('central-api.execute'), [
        'operation_id' => 'createPortchannel',
        'query' => [
            'name' => 'lag1',
            'scope-id' => 'scope-123',
        ],
    ])
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error', 'Request body is required for this operation.');
});

test('execute proxies deletePortchannel with path and query parameters', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'sso.common.cloud.hpe.com')) {
            return Http::response(['access_token' => 'refreshed-bearer-token'], 200);
        }

        if (
            $request->method() === 'DELETE'
            && str_contains($request->url(), 'portchannels/lag1')
            && str_contains($request->url(), 'scope-id=scope-123')
        ) {
            return Http::response([], 204);
        }

        return Http::response([], 404);
    });

    $this->postJson(route('central-api.execute'), [
        'operation_id' => 'deletePortchannel',
        'query' => [
            'name' => 'lag1',
            'scope-id' => 'scope-123',
            'view-type' => 'LOCAL',
            'object-type' => 'LOCAL',
            'device-function' => 'ACCESS_SWITCH',
        ],
    ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('status', 204);
});

test('explorer includes device options for current client', function () {
    Device::factory()->for($this->client)->for($this->user)->create([
        'serial' => 'DEVICE-SERIAL-1',
        'name' => 'Switch-1',
    ]);

    $this->get(route('central-api.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('device_options', 1)
            ->where('device_options.0.serial', 'DEVICE-SERIAL-1'));
});
