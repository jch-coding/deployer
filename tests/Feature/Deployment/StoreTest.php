<?php

use App\Models\User;
use App\Models\Client;

it('can store a deployment', function () {
    $user = User::factory()->has(Client::factory())->create();
    $user->clients()->first()->update(['current' => true]);
    $this->actingAs($user);
    $this->post(route('deployments.store'), ['name' => 'Test Deployment'])
        ->assertRedirect(route('deployments.index'));
});

it('requires authentication', function () {
    $this->post(route('deployments.store'))->assertRedirect(route('login'));
});

test('a user cannot create a deployment with an invalid name', function ($invalidName) {
    $user = User::factory()->has(Client::factory())->create();
    $user->clients()->first()->update(['current' => true]);
    $this->actingAs($user);
    $this->post(route('deployments.store'), ['name' => $invalidName])
        ->assertSessionHasErrors('name');
})->with([
    1,
    1.5,
    null,
    '',
    str_repeat('a', 2),
    str_repeat('a', 256),
]);

test('a user cannot create a deployment with a duplicate name', function () {
    $user = User::factory()->has(Client::factory())->create();
    $user->clients()->first()->update(['current' => true]);
    $this->actingAs($user);
    $this->post(route('deployments.store'), ['name' => 'Test Deployment'])
        ->assertRedirect(route('deployments.index'));
    $this->post(route('deployments.store'), ['name' => 'Test Deployment'])
        ->assertSessionHasErrors('name');
});
