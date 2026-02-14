<?php

use App\Models\Client;
use App\Models\User;

test('an authenticated user can store a new client', function () {
    $user = User::factory()->create();
    $client = Client::factory()->recycle($user)->make();

    $this->actingAs($user);
    $response = $this->post(route('clients.store'), $client->toArray())
        ->assertRedirect();
    $this->assertDatabaseHas('clients', $client->refresh()->toArray());
});
