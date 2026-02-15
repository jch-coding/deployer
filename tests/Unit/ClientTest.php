<?php

use App\Models\Client;
use App\Models\User;

it('has a name', function () {
    $this->withoutExceptionHandling();
    $client = Client::factory()->create(['name' => 'Test Client']);
    expect($client->name)->toBe('Test Client');
});

it('has a client secret', function () {
    $client = Client::factory()->create(['client_secret' => 'secret123!']);
    expect($client->client_secret)->toBe('secret123!');
});

it('has a client id', function () {
    $client = Client::factory()->create(['client_id' => 'client123']);
    expect($client->client_id)->toBe('client123');
});

it('has a customer id', function () {
    $client = Client::factory()->create(['customer_id' => 'customer123']);
    expect($client->customer_id)->toBe('customer123');
});

it('has one user relationship', function () {
    $user = User::factory()->create();
    $client = Client::factory()->create(['user_id' => $user->id]);
    expect($client->user)
        ->toBeInstanceOf(User::class)
        ->and($client->user->id)->toBe($user->id);
});

it('has an fqdn as a url string when the base_url attribute is accessed', function () {
   $client = Client::factory()->create(['base_url' => 'us5']);
   expect($client->base_url)->toBe('https://us5.api.central.arubanetworks.com/');
});

it('has a current attribute that is a boolean and is false by default', function () {
    $client = Client::factory()->create();
    expect($client->current)->toBeFalse();
});
