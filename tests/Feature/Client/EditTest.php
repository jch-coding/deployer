<?php

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;

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
   $this->actingAs($user);
   $this->put(route('clients.current', $user->clients->first()));
   $current_client = $user->refresh()->currentClient();

   expect($current_client)->toBeInstanceOf(Client::class)->and($current_client->id)->toBe(1);
   expect($user->clients->where('current', true))->toHaveCount(1);
   $this->put(route('clients.current', $user->clients->last()));
   $current_client = $user->refresh()->currentClient();
   expect($current_client)->toBeInstanceOf(Client::class)->and($current_client->id)->toBe(2);
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
         ->assertRedirect(route('clients.index'));
    expect($client->refresh()->current)->toBeTrue();
});

