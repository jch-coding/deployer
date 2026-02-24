<?php

use App\Models\Client;
use App\Models\Deployment;
use App\Models\User;

test('an authenticated user can delete a deployment', function () {
    $deployment = Deployment::factory()->create();
    $this->delete(route('deployments.destroy', $deployment))
         ->assertRedirect(route('login'));
});

test('a user can delete their own deployment', function () {
    $user = User::factory()
                 ->has(Client::factory())
                 ->create();
    $client = $user->clients()->first();
    $deployment = Deployment::factory()
                 ->for($client)
                 ->create();
    $this->actingAs($user)
         ->delete(route('deployments.destroy', $deployment))
         ->assertRedirect(route('deployments.index'));
    $this->assertDatabaseMissing('deployments', ['id' => $deployment->id]);
});

test('a user cannot delete a deployment they do not own', function () {
    $user1 = User::factory()
                  ->has(Client::factory())
                  ->create();
    $user2 = User::factory()
        ->has(Client::factory())
        ->create();
    $client1 = $user1->clients()->first();
    $deployment = Deployment::factory()
        ->for($client1)
        ->create();
    $this->actingAs($user2)
         ->delete(route('deployments.destroy', $deployment))
         ->assertForbidden();
});
