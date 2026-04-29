<?php

use App\BaseURL;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('an authenticated user can store a new client', function () {
    $this->withoutExceptionHandling();
    $user = User::factory()->create();
    $client = Client::factory()->recycle($user)->make();
    $rawBaseUrl = $client->getAttributes()['base_url'] ?? BaseURL::US1->value;
    $baseUrl = $rawBaseUrl instanceof BaseURL ? $rawBaseUrl->value : (string) $rawBaseUrl;
    $payload = [
        'name' => $client->name,
        'client_id' => $client->client_id,
        'client_secret' => $client->getAttributes()['client_secret'],
        'customer_id' => $client->customer_id,
        'base_url' => $baseUrl,
    ];
    Http::fake([
        'https://sso.common.cloud.hpe.com/as/token.oauth2' => Http::response(['access_token' => 'fake-token'], 200),
    ]);

    $this->actingAs($user);
    $this->post(route('clients.store'), $payload)
         ->assertRedirect();
    $this->assertDatabaseHas('clients', [
        'name' => $client->name,
        'client_id' => $client->client_id,
        'customer_id' => $client->customer_id,
        'base_url' => $baseUrl,
        'user_id' => $user->id,
    ]);
});
