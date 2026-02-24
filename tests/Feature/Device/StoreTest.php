<?php

use App\DeviceFunction;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\User;

test('must be authenticated to store a device', function () {
    $deployment = Deployment::factory()->create();
    $this->post(route('devices.store', $deployment), [
        'name' => 'Test Device',
        'serial' => 'TEST123',
        'device_function' => 'CAMPUS_AP'
    ])
         ->assertRedirect(route('login'));
});

test('a device must have a name, serial and device function', function (array $value, array $errors) {
    $user = User::factory()
        ->has(Client::factory())
        ->create();
    $deployment = Deployment::factory()->for($user->clients()->first())->create();
    $this->actingAs($user);
    $this->post(route('devices.store', $deployment), $value)
        ->assertSessionHasErrors($errors);
})->with([
    [['name' => ''], ['name']],
    [['name' => null], ['name']],
    [['name' => 1], ['name']],
    [['name' => 1.5], ['name']],
    [['name' => str_repeat('a', 256)], ['name']],
    [['serial' => ''], ['serial']],
    [['serial' => null], ['serial']],
    [['serial' => 1], ['serial']],
    [['serial' => 1.5], ['serial']],
    [['device_function' => ''], ['device_function']],
    [['device_function' => null], ['device_function']],
    [['device_function' => 1], ['device_function']],
    [['device_function' => 1.5], ['device_function']],
]);

test('a device is associated with the current client for the authenticated user by default', function () {
    $this->withoutExceptionHandling();
   $user = User::factory()
                ->has(Client::factory())
                ->create();
   $client = $user->clients()->first();
   $client->update(['current' => true]);
   $client->deployments()->create(['name' => 'Test Deployment']);
   $this->actingAs($user);
   $this->post(route('devices.store', $client->deployments()->first()), [
       'name' => 'Test Device',
       'serial' => 'TEST12356789',
           'device_function' => DeviceFunction::CAMPUS_AP->name
       ]
   )
       ->assertRedirect(route('deployments.show', $client->deployments()->first()));
   $this->assertDatabaseHas('devices', [
       'name' => 'Test Device',
       'serial' => 'TEST12356789',
       'device_function' => DeviceFunction::CAMPUS_AP,
       'client_id' => $user->clients()->first()->id,
       'deployment_id' => $client->deployments()->first()->id
   ]);
});

test('adding a device already in the database will update the device', function () {
    $this->withoutExceptionHandling();
    $user = User::factory()
        ->has(Client::factory())
        ->create();
    $client = $user->clients()->first();
    $client->update(['current' => true]);
    $client->refresh();
    $deployment = Deployment::factory()->recycle($client)->create();
    $device = Device::factory()->recycle($client)->recycle($deployment)->create(['deployment_id' => $deployment->id]);
    $this->travelTo(now()->addHour());
    $this->actingAs($user);
    $this->post(route('devices.store', $deployment), $device->toArray())
       ->assertSessionHasNoErrors();
    $this->assertDatabaseCount('devices', 1);
});
