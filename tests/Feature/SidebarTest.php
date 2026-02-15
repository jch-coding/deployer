<?php

use App\Models\Client;
use App\Models\User;

test('an authenticated user sees clients in the sidebar', function () {
    $this->withoutExceptionHandling();
    $after_auth_links = ['Clients'];
    $user = User::factory()->has(Client::factory(2))->create();
    $this->actingAs($user);
    $response = $this->get('/dashboard')
                     ->assertSee($after_auth_links);
});
