<?php

use App\Models\Client;
use App\Models\User;

it('can have multiple clients', function () {
    $user = User::factory()
            ->has(Client::factory()->count(2))
            ->create();

    expect($user->clients)->toHaveCount(2);
});
