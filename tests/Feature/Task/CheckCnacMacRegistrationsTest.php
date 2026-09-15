<?php

use App\BaseURL;
use App\CentralScopeCacheType;
use App\Models\CentralScopeCache;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = Client::factory()->for($this->user)->create([
        'current' => true,
        'base_url' => BaseURL::US1,
        'bearer_token' => 'test-bearer-token',
        'expires_at' => now()->addHour(),
    ]);
    $this->deployment = Deployment::factory()->for($this->client)->create();
    $this->actingAs($this->user);
});

test('check cnac mac registrations reports registered tags and unregistered devices', function () {
    CentralScopeCache::query()->create([
        'client_id' => $this->client->id,
        'type' => CentralScopeCacheType::MacRegistrations,
        'items' => [
            [
                'mac_address' => 'aa:bb:cc:dd:ee:01',
                'client_name' => 'Lobby',
                'enabled' => true,
                'static_tags' => ['BVSD-AP', 'BVSD-PUBLIC'],
            ],
        ],
        'refreshed_at' => now(),
        'last_error' => null,
    ]);

    $registered = Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'name' => 'AP-1',
        'serial' => 'SNREG1',
        'mac_address' => 'aa:bb:cc:dd:ee:01',
    ]);
    $missing = Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'name' => 'AP-2',
        'serial' => 'SNMISS1',
        'mac_address' => '11:22:33:44:55:66',
    ]);
    $invalid = Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'name' => 'AP-3',
        'serial' => 'SNBAD1',
        'mac_address' => 'not-a-mac',
    ]);

    $this->postJson(route('tasks.check_cnac_mac_registrations', $this->deployment), [
        'device_ids' => [$registered->id, $missing->id, $invalid->id],
    ])
        ->assertOk()
        ->assertJsonPath('summary.registered', 1)
        ->assertJsonPath('summary.not_registered', 1)
        ->assertJsonPath('summary.invalid_mac', 1)
        ->assertJsonPath('rows.0.registered', true)
        ->assertJsonPath('rows.0.static_tags', ['BVSD-AP', 'BVSD-PUBLIC'])
        ->assertJsonPath('rows.0.client_name', 'Lobby')
        ->assertJsonPath('rows.1.registered', false)
        ->assertJsonPath('rows.2.invalid_mac', true);
});

test('check cnac mac registrations auto-refreshes when cache is empty', function () {
    Http::fake([
        '*cnac-mac-reg/export*' => Http::response(
            "MAC Address,Client Name,Enabled,Static Tags\nAA-BB-CC-DD-EE-01,,true,TAG-A\n",
            200,
        ),
    ]);

    $device = Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'mac_address' => 'aa:bb:cc:dd:ee:01',
    ]);

    $this->postJson(route('tasks.check_cnac_mac_registrations', $this->deployment), [
        'device_ids' => [$device->id],
    ])
        ->assertOk()
        ->assertJsonPath('rows.0.registered', true)
        ->assertJsonPath('rows.0.static_tags', ['TAG-A'])
        ->assertJsonPath('cache.error', null);

    expect(
        CentralScopeCache::query()
            ->where('client_id', $this->client->id)
            ->where('type', CentralScopeCacheType::MacRegistrations)
            ->exists()
    )->toBeTrue();
});

test('check cnac mac registrations returns 403 when client does not match deployment', function () {
    $otherClient = Client::factory()->for($this->user)->create(['current' => false]);
    $deployment = Deployment::factory()->for($otherClient)->create();

    $this->postJson(route('tasks.check_cnac_mac_registrations', $deployment), [])
        ->assertStatus(403);
});
