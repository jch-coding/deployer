<?php

use App\Models\Client;
use App\Models\User;

test('a user cannot edit another user\'s client', function () {
    $user1 = User::factory()
                   ->has(Client::factory()->count(1))
                   ->create();
    $user2 = User::factory()->create();
    $this->actingAs($user2);
    $this->put(route('clients.edit', $user1->clients->first()), ['name' => 'New Name'])
         ->assertForbidden();
});

test('a user cannot edit client details with invalid data', function ($value) {
    $user = User::factory()
                ->has(Client::factory()->count(1))
                ->create();
    $this->actingAs($user);
    $client = $user->clients->first();
    $this->put(route('clients.edit', $client), $value)
         ->assertSessionHas('errors');
    $this->assertDatabaseHas('clients', [
        'name' => $client->name,
        'client_secret' => $client->client_secret,
        'client_id' => $client->client_id,
        'customer_id' => $client->customer_id
    ]);
})->with([
    fn() => ['name' => ''],
    fn() => ['name' => null],
    fn() => ['name' => str_repeat('a', 256)],
    fn() => ['client_secret' => ''],
    fn() => ['client_secret' => null],
    fn() => ['client_id' => ''],
    fn() => ['client_id' => null],
    fn() => ['customer_id' => ''],
    fn() => ['customer_id' => null],
    fn() => ['base_url' => ''],
    fn() => ['base_url' => null],
]);

test('a user can edit client details with valid data', function () {
    $user = User::factory()
                ->has(Client::factory()->count(1))
                ->create();
    $this->actingAs($user);
    $client = $user->clients->first();
    $this->put(route('clients.edit', $client), ['name' => 'New Name'])
         ->assertRedirect(route('clients.index'));
    $this->assertDatabaseHas('clients', ['name' => 'New Name']);
});
