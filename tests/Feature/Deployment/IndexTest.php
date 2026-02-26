<?php

use App\Models\Client;
use App\Models\Deployment;
use App\Models\User;

test('index page returns a list of deployments for an authenticated user', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $deployments = Deployment::factory(2)->for($user)->create();
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
   visit(route('deployments.index'))
       ->click('@add-deployment-trigger')
       ->fill('name', 'New Deployment')
       ->click('@add-deployment');
   $this->assertDatabaseHas('deployments', ['client_id' => $user->clients()->first()->id]);
});

test('a user can click on a deployment to view it', function () {
    $user = User::factory()->create();
    $deployment = Deployment::factory()->for($user)->create();
    $this->actingAs($user);
    visit(route('deployments.index'))
         ->assertSee($deployment->name)
        ->click('@deployment-link')
        ->assertUrlIs(route('deployments.show', $deployment));
});

test('a user can delete a deployment on the index page', function () {
    $user = User::factory()->create();
    $deployment = Deployment::factory()->for($user)->create();
    $this->actingAs($user);
    $this->visit(route('deployments.index'))
        ->click('@delete')
        ->assertRedirect(route('deployments.index'))
        ->assertDontSeeHtml($deployment->name);
});

test('a user can edit a deployment on the index page.', function () {
    $user = User::factory()->create();
    $deployment = Deployment::factory()->for($user)->create();
    $this->actingAs($user);
    $this->visit(route('deployments.index'))
        ->click('@edit')
        ->assertSeeHtml($deployment->name)
        ->fill('name', 'New Deployment Name')
        ->click('@update')
        ->assertRedirect(route('deployments.index'))
        ->assertSeeHtml('New Deployment Name');
});

it('has a button to add a new deployment', function () {
    $deployment = Deployment::factory()->create();
    $this->actingAs($deployment->user);
    visit(route('deployments.index'))
        ->assertSee('Add Deployment')
        ->click('@add-deployment-trigger')
        ->fill('name', 'New Deployment')
        ->click('@add-deployment');
    $this->assertDatabaseHas('deployments', [
        'name' => $deployment->name,
        'description' => $deployment->description,
        'user_id' => $deployment->user_id,
        'id' => $deployment->id
    ]);
});
