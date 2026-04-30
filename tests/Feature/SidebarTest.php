<?php

use App\Models\Client;
use App\Models\User;

test('an authenticated user sees clients in the sidebar', function () {
    $this->withoutExceptionHandling();
    $user = User::factory()->has(Client::factory(2))->create();
    $this->actingAs($user);
    $this->get('/clients')
        ->assertSuccessful()
        ->assertSeeText('Clients');
});
