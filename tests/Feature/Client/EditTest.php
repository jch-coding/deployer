<?php

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\Http;

test('a user cannot edit another user\'s client', function () {
    $user1 = User::factory()
                   ->has(Client::factory()->count(1))
                   ->create();
    $user2 = User::factory()->create();
    $this->actingAs($user2);
    $this->put(route('clients.edit', $user1->clients->first()), ['name' => 'New Name'])
         ->assertForbidden();
});

test('a user cannot edit client details with invalid name data', function ($value) {
    $user = User::factory()
                ->has(Client::factory()->count(1)->state(fn () => ['current' => true]))
                ->create();
    $this->actingAs($user);
    $client = $user->clients->first();
    $this->put(route('clients.edit', $client), $value)
          ->assertSessionHasErrors(['name']);
    $this->assertDatabaseHas('clients', [
        'name' => $client->name,
        'client_id' => $client->client_id,
        'customer_id' => $client->customer_id,
    ]);
})->with([
    fn() => ['name' => ''],
    fn() => ['name' => null],
    fn() => ['name' => str_repeat('a', 256)],
]);

test('a user cannot edit client details with invalid client_id data', function ($value) {
    $user = User::factory()
        ->has(Client::factory()->count(1)->state(fn () => ['current' => true]))
        ->create();
    $this->actingAs($user);
    $client = $user->clients->first();
    $this->put(route('clients.edit', $client), $value)
        ->assertSessionHasErrors(['client_id']);
    $this->assertDatabaseHas('clients', [
        'client_id' => $client->client_id,
    ]);
})->with([
    fn() => ['client_id' => ''],
    fn() => ['client_id' => null],
]);

test('a user cannot edit client details with invalid client_secret data', function ($value) {
    $user = User::factory()
        ->has(Client::factory()->count(1)->state(fn () => ['current' => true]))
        ->create();
    $this->actingAs($user);
    $client = $user->clients->first();
    $this->put(route('clients.edit', $client), $value)
        ->assertSessionHasErrors(['client_secret']);
})->with([
    fn() => ['client_secret' => ''],
    fn() => ['client_secret' => null],
]);

test('a user cannot edit client details with invalid customer_id data', function ($value) {
    $user = User::factory()
        ->has(Client::factory()->count(1)->state(fn () => ['current' => true]))
        ->create();
    $this->actingAs($user);
    $client = $user->clients->first();
    $this->put(route('clients.edit', $client), $value)
        ->assertSessionHasErrors(['customer_id']);
    $this->assertDatabaseHas('clients', [
        'customer_id' => $client->customer_id,
    ]);
})->with([
    fn() => ['customer_id' => ''],
    fn() => ['customer_id' => null],
]);

test('a user cannot edit client details with invalid base_url data', function ($value) {
    $user = User::factory()
        ->has(Client::factory()->count(1)->state(fn () => ['current' => true]))
        ->create();
    $this->actingAs($user);
    $client = $user->clients->first();
    $this->put(route('clients.edit', $client), $value)
        ->assertSessionHasErrors(['base_url']);
})->with([
    fn() => ['base_url' => ''],
    fn() => ['base_url' => null],
]);

test('a user can edit client details with valid data', function () {
    $this->withoutExceptionHandling();
    $user = User::factory()
                ->has(Client::factory()->count(1)->state(fn () => ['current' => true]))
                ->create();
    $this->actingAs($user);
    $client = $user->clients->first();
    $this->put(route('clients.edit', $client), ['name' => 'New Name'])
         ->assertRedirect(route('clients.index'));
    $this->assertDatabaseHas('clients', ['name' => 'New Name']);
});

test('only one client can be set as current for a user', function () {
    $this->withoutExceptionHandling();
   $user = User::factory()
               ->has(Client::factory(2)->state(['current' => false]))
               ->create();
   $firstClient = $user->clients->first();
   $lastClient = $user->clients->last();
   $this->actingAs($user);
   $this->put(route('clients.current', $firstClient));
   $current_client = $user->refresh()->currentClient();

   expect($current_client)->toBeInstanceOf(Client::class)->and($current_client->id)->toBe($firstClient->id);
   expect($user->clients->where('current', true))->toHaveCount(1);
   $this->put(route('clients.current', $lastClient));
   $current_client = $user->refresh()->currentClient();
   expect($current_client)->toBeInstanceOf(Client::class)->and($current_client->id)->toBe($lastClient->id);
   expect($user->clients->where('current', true))->toHaveCount(1);
});

test('a user can set a client as current', function () {
    $this->withoutExceptionHandling();
    $user = User::factory()
                ->has(Client::factory()->count(1))
                ->create();
    $this->actingAs($user);
    $client = $user->clients->first();
    $this->put(route('clients.current', $client))
         ->assertRedirect();
    expect($client->refresh()->current)->toBeTrue();
});

function classicClientForRefreshToken(User $user, array $overrides = []): Client
{
    return Client::factory()->recycle($user)->create(array_merge([
        'classic_client_id' => 'classic-client-id-0001',
        'classic_client_secret' => 'classic-client-secret-0001',
        'classic_username' => 'classic-user',
        'classic_password' => 'classic-password',
        'classic_base_url' => 'https://apigw-uswest4.central.arubanetworks.com/',
        'classic_refresh_token' => 'old-classic-refresh-token',
        'classic_expires_in' => now()->subHour(),
    ], $overrides));
}

test('a user can save a classic refresh token without exchanging it with Central', function () {
    $user = User::factory()->create();
    $client = classicClientForRefreshToken($user, [
        'classic_access_token' => 'existing-classic-access-token',
    ]);

    Http::fake();

    $this->actingAs($user)
        ->put(route('clients.edit', $client), ['classic_refresh_token' => 'new-classic-refresh-token-input'])
        ->assertRedirect(route('clients.index'))
        ->assertSessionHas('success', 'Classic Central tokens saved.');

    $client->refresh();
    expect($client->classic_refresh_token)->toBe('new-classic-refresh-token-input')
        ->and($client->classic_access_token)->toBe('existing-classic-access-token')
        ->and($client->classic_expires_in)->toBeGreaterThan(now()->addHour())
        ->and($client->classic_expires_in)->toBeLessThanOrEqual(now()->addHours(2))
        ->and($client->classic_refresh_expires_in)->toBeGreaterThan(now()->addDays(14))
        ->and($client->classic_refresh_expires_in)->toBeLessThanOrEqual(now()->addDays(15));

    Http::assertNothingSent();
});

test('a user cannot save only an access token when no classic refresh token exists', function () {
    $user = User::factory()->create();
    $client = classicClientForRefreshToken($user, [
        'classic_refresh_token' => null,
        'classic_access_token' => null,
        'classic_expires_in' => null,
    ]);

    $this->actingAs($user)
        ->put(route('clients.edit', $client), ['classic_access_token' => 'new-classic-access-token-input'])
        ->assertRedirect(route('clients.index'))
        ->assertSessionHas('error', 'Failed to save Classic Central tokens.');

    expect($client->fresh()->classic_access_token)->toBeNull()
        ->and($client->fresh()->classic_refresh_token)->toBeNull();
});

test('a user cannot save an invalid classic refresh token', function (string $value) {
    $user = User::factory()->create();
    $client = classicClientForRefreshToken($user);
    $originalToken = $client->classic_refresh_token;

    $this->actingAs($user)
        ->put(route('clients.edit', $client), ['classic_refresh_token' => $value])
        ->assertSessionHasErrors(['classic_refresh_token']);

    expect($client->fresh()->classic_refresh_token)->toBe($originalToken);
})->with([
    '',
    'short',
]);

test('a user can save a classic access token without exchanging tokens with Central', function () {
    $user = User::factory()->create();
    $client = classicClientForRefreshToken($user, [
        'classic_access_token' => 'old-classic-access-token',
        'classic_refresh_token' => 'existing-classic-refresh-token',
    ]);

    Http::fake();

    $this->actingAs($user)
        ->put(route('clients.edit', $client), ['classic_access_token' => 'new-classic-access-token-input'])
        ->assertRedirect(route('clients.index'))
        ->assertSessionHas('success', 'Classic Central tokens saved.');

    $client->refresh();
    expect($client->classic_access_token)->toBe('new-classic-access-token-input')
        ->and($client->classic_refresh_token)->toBe('existing-classic-refresh-token')
        ->and($client->classic_expires_in)->toBeGreaterThan(now()->addHour())
        ->and($client->classic_expires_in)->toBeLessThanOrEqual(now()->addHours(2))
        ->and($client->classic_refresh_expires_in)->toBeGreaterThan(now()->addDays(14))
        ->and($client->classic_refresh_expires_in)->toBeLessThanOrEqual(now()->addDays(15));

    Http::assertNothingSent();
});

test('a user can save classic refresh and access tokens together', function () {
    $user = User::factory()->create();
    $client = classicClientForRefreshToken($user);

    Http::fake();

    $this->actingAs($user)
        ->put(route('clients.edit', $client), [
            'classic_refresh_token' => 'new-classic-refresh-token-input',
            'classic_access_token' => 'new-classic-access-token-input',
        ])
        ->assertRedirect(route('clients.index'))
        ->assertSessionHas('success', 'Classic Central tokens saved.');

    $client->refresh();
    expect($client->classic_access_token)->toBe('new-classic-access-token-input')
        ->and($client->classic_refresh_token)->toBe('new-classic-refresh-token-input')
        ->and($client->classic_expires_in)->toBeGreaterThan(now()->addHour())
        ->and($client->classic_expires_in)->toBeLessThanOrEqual(now()->addHours(2))
        ->and($client->classic_refresh_expires_in)->toBeGreaterThan(now()->addDays(14))
        ->and($client->classic_refresh_expires_in)->toBeLessThanOrEqual(now()->addDays(15));

    Http::assertNothingSent();
});

test('a user cannot save an invalid classic access token', function (string $value) {
    $user = User::factory()->create();
    $client = classicClientForRefreshToken($user);
    $originalToken = $client->classic_access_token;

    $this->actingAs($user)
        ->put(route('clients.edit', $client), ['classic_access_token' => $value])
        ->assertSessionHasErrors(['classic_access_token']);

    expect($client->fresh()->classic_access_token)->toBe($originalToken);
})->with([
    '',
    'short',
]);

test('a user cannot save classic tokens without providing refresh or access token values', function () {
    $user = User::factory()->create();
    $client = classicClientForRefreshToken($user);

    $this->actingAs($user)
        ->put(route('clients.edit', $client), [
            'classic_refresh_token' => '',
            'classic_access_token' => '',
        ])
        ->assertSessionHasErrors(['classic_refresh_token', 'classic_access_token']);
});

test('a user can save a classic client id from the classic central tokens form', function () {
    $user = User::factory()->create();
    $client = classicClientForRefreshToken($user);

    $this->actingAs($user)
        ->put(route('clients.edit', $client), ['classic_client_id' => 'updated-classic-client-id'])
        ->assertRedirect(route('clients.index'))
        ->assertSessionHas('success', 'Classic client ID saved.');

    expect($client->fresh()->classic_client_id)->toBe('updated-classic-client-id');
});

test('a user cannot save an invalid classic client id', function (string $value) {
    $user = User::factory()->create();
    $client = classicClientForRefreshToken($user);
    $originalClientId = $client->classic_client_id;

    $this->actingAs($user)
        ->put(route('clients.edit', $client), ['classic_client_id' => $value])
        ->assertSessionHasErrors(['classic_client_id']);

    expect($client->fresh()->classic_client_id)->toBe($originalClientId);
})->with([
    '',
    'short',
]);

test('a user cannot save a classic refresh token for another users client', function () {
    $owner = User::factory()->create();
    $client = classicClientForRefreshToken($owner);
    $otherUser = User::factory()->create();

    $this->actingAs($otherUser)
        ->put(route('clients.edit', $client), ['classic_refresh_token' => 'another-users-refresh-token'])
        ->assertForbidden();
});

