<?php

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('a user can validate central credentials for their client', function () {
    $user = User::factory()->create();
    $client = Client::factory()->recycle($user)->create([
        'client_id' => 'central-client-id-0001',
        'client_secret' => 'central-client-secret-0001',
    ]);

    Http::fake([
        'https://sso.common.cloud.hpe.com/as/token.oauth2' => Http::response(['access_token' => 'new-central-token'], 200),
    ]);

    $this->actingAs($user)
        ->post(route('clients.test_central_creds', $client), ['type' => 'central'])
        ->assertRedirect(route('clients.index'))
        ->assertSessionHas('success', 'Central credentials validated successfully.');

    expect($client->fresh()->bearer_token)->toBe('new-central-token');
});

test('a user sees an error when central credential validation fails', function () {
    $user = User::factory()->create();
    $client = Client::factory()->recycle($user)->create([
        'client_id' => 'central-client-id-0002',
        'client_secret' => 'central-client-secret-0002',
    ]);

    Http::fake([
        'https://sso.common.cloud.hpe.com/as/token.oauth2' => Http::response([], 401),
    ]);

    $this->actingAs($user)
        ->post(route('clients.test_central_creds', $client), ['type' => 'central'])
        ->assertRedirect(route('clients.index'))
        ->assertSessionHas('error', 'Failed to validate Central credentials.');
});

test('a user can validate classic credentials for their client', function () {
    $user = User::factory()->create();
    $client = Client::factory()->recycle($user)->create([
        'classic_client_id' => 'classic-client-id-0001',
        'classic_client_secret' => 'classic-client-secret-0001',
        'classic_username' => 'classic-user',
        'classic_password' => 'classic-password',
        'classic_base_url' => 'https://apigw-uswest4.central.arubanetworks.com/',
        'classic_refresh_token' => 'classic-refresh-token',
        'classic_expires_in' => now()->addHour(),
    ]);

    $this->actingAs($user)
        ->post(route('clients.test_central_creds', $client), ['type' => 'classic'])
        ->assertRedirect(route('clients.index'))
        ->assertSessionHas('success', 'Classic Central credentials validated successfully.');
});

test('a user sees an error when classic credentials are missing', function () {
    $user = User::factory()->create();
    $client = Client::factory()->recycle($user)->create([
        'classic_client_id' => null,
        'classic_client_secret' => null,
        'classic_username' => null,
        'classic_password' => null,
    ]);

    $this->actingAs($user)
        ->post(route('clients.test_central_creds', $client), ['type' => 'classic'])
        ->assertRedirect(route('clients.index'))
        ->assertSessionHas('error', 'Classic credentials are not configured for this client.');
});

test('a user cannot validate credentials for another users client', function () {
    $owner = User::factory()->has(Client::factory())->create();
    $otherUser = User::factory()->create();

    $this->actingAs($otherUser)
        ->post(route('clients.test_central_creds', $owner->clients->first()), ['type' => 'central'])
        ->assertForbidden();
});

