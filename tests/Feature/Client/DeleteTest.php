<?php

use App\Models\Client;
use App\Models\User;

test('an authenticated user can delete own client', function () {
    $user = User::factory()
        ->has(Client::factory())->create();

    $client = $user->clients->first();

    $this->actingAs($user);
    $this->delete(route('clients.destroy', $client))
        ->assertRedirect();

    $this->assertDatabaseMissing('clients', $client->toArray());
});

test('clients cannot be deleted by other users', function () {
    $user = User::factory()
        ->has(Client::factory())->create();
    $user2 = User::factory()->create();

    $client = $user->clients->first();

    $this->actingAs($user2);
    $this->delete(route('clients.destroy', $client))
        ->assertForbidden();
});
