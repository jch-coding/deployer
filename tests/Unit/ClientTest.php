<?php

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

it('has a name', function () {
    $this->withoutExceptionHandling();
    $client = Client::factory()->create(['name' => 'Test Client']);
    expect($client->name)->toBe('Test Client');
});

it('has a client secret', function () {
    $client = Client::factory()->create(['client_secret' => 'secret123!']);
    expect($client->client_secret)->toBe('secret123!');
});

it('has a client id', function () {
    $client = Client::factory()->create(['client_id' => 'client123']);
    expect($client->client_id)->toBe('client123');
});

it('has a customer id', function () {
    $client = Client::factory()->create(['customer_id' => 'customer123']);
    expect($client->customer_id)->toBe('customer123');
});

it('has one user relationship', function () {
    $user = User::factory()->create();
    $client = Client::factory()->create(['user_id' => $user->id]);
    expect($client->user)
        ->toBeInstanceOf(User::class)
        ->and($client->user->id)->toBe($user->id);
});

it('has an fqdn as a url string when the base_url attribute is accessed', function () {
    $client = Client::factory()->create(['base_url' => 'us5']);
    expect($client->base_url)->toBe('https://us5.api.central.arubanetworks.com/');
});

it('has a current attribute that is a boolean and is false by default', function () {
    $client = Client::factory()->create();
    expect($client->current)->toBeFalse();
});

it('treats a null classic refresh expiry as expired', function () {
    $client = Client::factory()->create([
        'classic_refresh_expires_in' => null,
    ]);

    expect($client->classicRefreshTokenIsExpired())->toBeTrue();
});

it('refreshes classic tokens when the access token is expired and the refresh token is still valid', function () {
    $client = Client::factory()->create([
        'classic_client_id' => 'classic-client-id-0001',
        'classic_client_secret' => 'classic-client-secret-0001',
        'classic_username' => 'classic-user',
        'classic_password' => 'classic-password',
        'classic_base_url' => 'https://apigw-uswest4.central.arubanetworks.com/',
        'classic_refresh_token' => 'valid-refresh-token',
        'classic_access_token' => 'expired-access-token',
        'classic_expires_in' => now()->subMinute(),
        'classic_refresh_expires_in' => now()->addDays(10),
    ]);

    Http::fake([
        'https://apigw-uswest4.central.arubanetworks.com/oauth2/token/*' => Http::response([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 7200,
        ], 200),
    ]);

    expect($client->handleClassicBearerToken())->toBeTrue();

    $client->refresh();
    expect($client->classic_access_token)->toBe('new-access-token')
        ->and($client->classic_refresh_token)->toBe('new-refresh-token')
        ->and($client->classic_expires_in)->toBeGreaterThan(now()->addHour())
        ->and($client->classic_refresh_expires_in)->toBeGreaterThan(now()->addDays(14))
        ->and($client->classic_refresh_expires_in)->toBeLessThanOrEqual(now()->addDays(15));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'grant_type=refresh_token'));
});

it('uses the full OAuth grant when the classic refresh token is expired', function () {
    $client = Client::factory()->create([
        'classic_client_id' => 'classic-client-id-0001',
        'classic_client_secret' => 'classic-client-secret-0001',
        'classic_username' => 'classic-user',
        'classic_password' => 'classic-password',
        'customer_id' => 'customer-id-0001',
        'classic_base_url' => 'https://apigw-uswest4.central.arubanetworks.com/',
        'classic_refresh_token' => 'expired-refresh-token',
        'classic_access_token' => 'expired-access-token',
        'classic_expires_in' => now()->subMinute(),
        'classic_refresh_expires_in' => now()->subDay(),
    ]);

    Http::fake([
        'https://apigw-uswest4.central.arubanetworks.com/oauth2/authorize/central/api/login*' => Http::response(
            [],
            200,
            [
                'Set-Cookie' => [
                    'csrftoken=test-csrf-token; Path=/',
                    'session=test-session-id; Path=/',
                ],
            ]
        ),
        'https://apigw-uswest4.central.arubanetworks.com/oauth2/authorize/central/api/*' => Http::response([
            'auth_code' => 'test-auth-code',
        ], 200),
        'https://apigw-uswest4.central.arubanetworks.com/oauth2/token/*' => Http::response([
            'access_token' => 'oauth-access-token',
            'refresh_token' => 'oauth-refresh-token',
            'expires_in' => 7200,
        ], 200),
    ]);

    expect($client->handleClassicBearerToken())->toBeTrue();

    $client->refresh();
    expect($client->classic_access_token)->toBe('oauth-access-token')
        ->and($client->classic_refresh_token)->toBe('oauth-refresh-token')
        ->and($client->classic_refresh_expires_in)->toBeGreaterThan(now()->addDays(14))
        ->and($client->classic_refresh_expires_in)->toBeLessThanOrEqual(now()->addDays(15));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth2/authorize/central/api/login'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'grant_type=refresh_token'));
});

it('falls back to full OAuth when classic refresh fails', function () {
    $client = Client::factory()->create([
        'classic_client_id' => 'classic-client-id-0001',
        'classic_client_secret' => 'classic-client-secret-0001',
        'classic_username' => 'classic-user',
        'classic_password' => 'classic-password',
        'customer_id' => 'customer-id-0001',
        'classic_base_url' => 'https://apigw-uswest4.central.arubanetworks.com/',
        'classic_refresh_token' => 'invalid-refresh-token',
        'classic_access_token' => 'expired-access-token',
        'classic_expires_in' => now()->subMinute(),
        'classic_refresh_expires_in' => now()->addDays(10),
    ]);

    Http::fake([
        'https://apigw-uswest4.central.arubanetworks.com/oauth2/authorize/central/api/login*' => Http::response(
            [],
            200,
            [
                'Set-Cookie' => [
                    'csrftoken=test-csrf-token; Path=/',
                    'session=test-session-id; Path=/',
                ],
            ]
        ),
        'https://apigw-uswest4.central.arubanetworks.com/oauth2/authorize/central/api/*' => Http::response([
            'auth_code' => 'test-auth-code',
        ], 200),
        'https://apigw-uswest4.central.arubanetworks.com/oauth2/token/*' => Http::sequence()
            ->push(['error' => 'invalid_grant'], 400)
            ->push([
                'access_token' => 'oauth-access-token',
                'refresh_token' => 'oauth-refresh-token',
                'expires_in' => 7200,
            ], 200),
    ]);

    expect($client->handleClassicBearerToken())->toBeTrue();

    $client->refresh();
    expect($client->classic_access_token)->toBe('oauth-access-token')
        ->and($client->classic_refresh_token)->toBe('oauth-refresh-token')
        ->and($client->classic_refresh_expires_in)->toBeGreaterThan(now()->addDays(14))
        ->and($client->classic_refresh_expires_in)->toBeLessThanOrEqual(now()->addDays(15));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'grant_type=refresh_token'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth2/authorize/central/api/login'));
});

it('logs the failing Classic OAuth step with status and body', function () {
    Log::spy();

    $client = Client::factory()->create([
        'classic_client_id' => 'classic-client-id-0001',
        'classic_client_secret' => 'classic-client-secret-0001',
        'classic_username' => 'classic-user',
        'classic_password' => 'classic-password',
        'customer_id' => 'customer-id-0001',
        'classic_base_url' => 'https://apigw-uswest4.central.arubanetworks.com/',
        'classic_refresh_token' => 'expired-refresh-token',
        'classic_access_token' => 'expired-access-token',
        'classic_expires_in' => now()->subMinute(),
        'classic_refresh_expires_in' => now()->subDay(),
    ]);

    Http::fake([
        'https://apigw-uswest4.central.arubanetworks.com/oauth2/authorize/central/api/login*' => Http::response(
            ['status' => false, 'message' => 'Invalid username or password'],
            401,
        ),
    ]);

    expect($client->handleClassicBearerToken())->toBeFalse();

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'Classic Central OAuth failed.'
                && ($context['step'] ?? null) === 'login'
                && ($context['status'] ?? null) === 401
                && str_contains((string) ($context['body'] ?? ''), 'Invalid username or password');
        });
});
