<?php

use App\Models\Client;
use App\Models\User;

it('can have multiple clients', function () {
    $user = User::factory()
            ->has(Client::factory()->count(2))
            ->create();

    expect($user->clients)->toHaveCount(2);
});

it('has a current client attribute', function () {
    $this->withoutExceptionHandling();
   $user = User::factory()->has(Client::factory(2))->create();
   $currentClient = $user->clients()->first();
   $currentClient->update(['current' => true]);
   expect($user->refresh()->currentClient())->toBeInstanceOf(Client::class)->and($user->currentClient()->id)->toBe($currentClient->id);
});

it('should only share a subset of attributes in the user resource.', function () {

});
