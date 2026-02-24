<?php

use App\Models\Client;
use App\Models\Device;
use App\Models\User;

it('can be updated with a new deployment', function () {
   $user = User::factory()
       ->has(Client::factory())
       ->create();
   $client = $user->clients->first()->update(['current' => true]);
   $deployment1 = $client->deployments()->create(['name' => 'Test Deployment 1']);
    $deployment2 = $client->deployments()->create(['name' => 'Test Deployment 2']);
    $device = $client->devices()->create(['name' => 'Test Device', 'deployment_id' => $deployment1->id]);
    $this->assertDatabaseHas('devices', ['name' => 'Test Device', 'deployment_id' => $deployment1->id]);
    $this->put(route('devices.edit', $device), ['deployment_id' => $deployment2->id]);
    $this->assertDatabaseHas('devices', ['name' => 'Test Device', 'deployment_id' => $deployment2->id]);
});

it('cannot be updated with a deployment not in client deployments', function () {
   $user = User::factory()
           ->has(Client::factory(2))
           ->create();
   $client1 = $user->clients()->first();
   $client2 = $user->clients()->skip(1)->first();
   $deployment1 = $client1->deployments()->create(['name' => 'Test Deployment']);
    $deployment2 = $client2->deployments()->create(['name' => 'Test Deployment 2']);
    $device = Device::factory()->create(['deployment_id' => $deployment2->id, 'client_id' => $client2->id]);

   $this->actingAs($user);
   $this->put(route('devices.edit', $device), ['deployment_id' => $deployment1->id])
       ->assertRedirectBack()
       ->assertSessionHasErrorsIn('deployment_id');
});
