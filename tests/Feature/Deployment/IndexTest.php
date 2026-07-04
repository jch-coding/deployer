<?php

use App\Models\Client;
use App\Models\Deployment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('index page returns a list of deployments for an authenticated user', function () {
    $user = User::factory()->has(Client::factory())->create();
    $client = $user->clients()->first();
    $client->update(['current' => true]);
    seedCentralScopeCache($client);
    $this->actingAs($user);
    $deployments = Deployment::factory(2)->for($client)->create();
    $this->get(route('deployments.index'))
        ->assertOk()
        ->assertSeeHtml($deployments->first()->name)
        ->assertSeeHtml($deployments->last()->name);
});

test('index page requires authentication', function () {
    $this->get(route('deployments.index'))->assertRedirect(route('login'));
});

test('a deployment is created with the current client by default', function () {
    $user = User::factory()
        ->has(Client::factory())
        ->create();
    $user->refresh()->clients()->first()->update(['current' => true]);
    $this->actingAs($user);
    $this->post(route('deployments.store'), ['name' => 'New Deployment'])
        ->assertRedirect(route('deployments.index'));
    $this->assertDatabaseHas('deployments', [
        'name' => 'New Deployment',
        'client_id' => $user->clients()->first()->id,
    ]);
});

test('a user can click on a deployment to view it', function () {
    $user = User::factory()->has(Client::factory())->create();
    $client = $user->clients()->first();
    $client->update(['current' => true]);
    seedCentralScopeCache($client);
    $deployment = Deployment::factory()->for($client)->create();
    $this->actingAs($user);
    $this->get(route('deployments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Deployment/Index')
            ->where('deployments.0.id', $deployment->id)
            ->where('deployments.0.name', $deployment->name)
            ->has('central_sites_cache.refreshed_at')
            ->has('central_groups_cache.refreshed_at')
        );
});

test('a user can delete a deployment on the index page', function () {
    $user = User::factory()->has(Client::factory())->create();
    $client = $user->clients()->first();
    $client->update(['current' => true]);
    $deployment = Deployment::factory()->for($client)->create();
    $this->actingAs($user);
    $this->delete(route('deployments.destroy', $deployment))
        ->assertRedirect(route('deployments.index'));
    $this->assertDatabaseMissing('deployments', ['id' => $deployment->id]);
});

it('has a button to add a new deployment', function () {
    $user = User::factory()->has(Client::factory())->create();
    $client = $user->clients()->first();
    $client->update(['current' => true]);
    seedCentralScopeCache($client);
    $this->actingAs($user);
    $this->get(route('deployments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Deployment/Index')
            ->has('deployments')
        );
});
