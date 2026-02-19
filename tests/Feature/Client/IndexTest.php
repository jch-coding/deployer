<?php

use App\Models\Client;
use App\Models\User;

it('requires authentication', function () {
    $response = $this->get(route('clients.index'));
    $response->assertRedirect(route('login'));
});

it('returns a list of clients', function () {
    $this->withoutExceptionHandling();
    $user = User::factory()
            ->has(Client::factory()->count(2))
            ->create();
    $user->refresh();
    $this->actingAs($user)->get(route('clients.index'))
         ->assertOk()
         ->assertSeeHtml([
             $user->clients->first()->name,
             $user->clients->last()->name,
         ]);
});
