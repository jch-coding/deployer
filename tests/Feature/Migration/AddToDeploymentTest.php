<?php

use App\BaseURL;
use App\DeviceFunction;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create();
    $this->client = Client::factory()->for($this->user)->create([
        'current' => true,
        'base_url' => BaseURL::US1,
        'bearer_token' => 'test-bearer-token',
        'expires_at' => now()->addHour(),
    ]);
    $this->deployment = Deployment::factory()->for($this->client)->create([
        'name' => 'Existing Migration Deployment',
    ]);
    $this->actingAs($this->user);
    seedCentralScopeCache($this->client);
    Http::fake([
        '*site-collections*' => Http::response([
            'items' => [],
        ], 200),
    ]);
});

function migrationAddToDeploymentPayload(array $overrides = []): array
{
    return array_merge([
        'deployment_id' => test()->deployment->id,
        'devices' => [
            [
                'name' => 'AP-Lobby-01',
                'serial' => 'CN1234567890',
                'mac_address' => 'aa:bb:cc:dd:ee:01',
                'controller_joined_ip' => '10.44.30.27',
                'site' => 'Central Site',
                'group' => 'Central Group',
            ],
            [
                'name' => 'AP-Lobby-02',
                'serial' => 'CN1234567891',
                'mac_address' => 'aa-bb-cc-dd-ee-02',
                'controller_joined_ip' => null,
                'site' => null,
                'group' => null,
            ],
        ],
        'parsed_controllers' => [
            [
                'controller_name' => 'CTRL-1',
                'devices' => [
                    ['name' => 'AP-Lobby-01', 'serial' => 'CN1234567890', 'mac' => 'aa:bb:cc:dd:ee:01'],
                ],
                'lldp_neighbors' => [],
                'auth_servers' => [],
                'server_groups' => [],
                'wlan_profiles' => [],
                'radio_profiles' => [],
                'user_roles' => [],
            ],
        ],
    ], $overrides);
}

test('add to deployment redirects when no current client is set', function () {
    $this->client->update(['current' => false]);

    $this->post(route('migrations.add-to-deployment'), migrationAddToDeploymentPayload())
        ->assertRedirect(route('clients.index'));
});

test('add to deployment adds campus ap devices to the chosen deployment', function () {
    $this->post(route('migrations.add-to-deployment'), migrationAddToDeploymentPayload())
        ->assertRedirect(route('migrations.index'));

    $this->get(route('migrations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Migration/Index')
            ->has('parsed_controllers', 1)
            ->where('parsed_controllers.0.controller_name', 'CTRL-1')
            ->where('last_added_to_deployment.name', 'Existing Migration Deployment')
            ->where('last_added_to_deployment.device_count', 2)
            ->has('deployments', 1)
            ->where('deployments.0.id', $this->deployment->id)
            ->where('deployments.0.name', 'Existing Migration Deployment'));

    expect($this->deployment->fresh()->devices)->toHaveCount(2);

    $first = Device::query()->where('serial', 'CN1234567890')->first();
    $second = Device::query()->where('serial', 'CN1234567891')->first();

    expect($first)->not->toBeNull()
        ->and($first->name)->toBe('AP-Lobby-01')
        ->and($first->device_function)->toBe(DeviceFunction::CAMPUS_AP->name)
        ->and($first->mac_address)->toBe('aa:bb:cc:dd:ee:01')
        ->and($first->controller_joined_ip)->toBe('10.44.30.27')
        ->and($first->group)->toBe('Central Group')
        ->and($first->deployment_id)->toBe($this->deployment->id)
        ->and($first->client_id)->toBe($this->client->id)
        ->and($first->user_id)->toBe($this->user->id);

    expect($first->site)->not->toBeNull()
        ->and($first->site->name)->toBe('Central Site')
        ->and($first->site->client_id)->toBe($this->client->id);

    expect($second)->not->toBeNull()
        ->and($second->device_function)->toBe(DeviceFunction::CAMPUS_AP->name)
        ->and($second->mac_address)->toBe('aa:bb:cc:dd:ee:02')
        ->and($second->controller_joined_ip)->toBeNull()
        ->and($second->group)->toBeNull()
        ->and($second->site_id)->toBeNull()
        ->and($second->deployment_id)->toBe($this->deployment->id);
});

test('add to deployment applies different site and group per device', function () {
    Site::firstOrCreateForClient($this->client, 'Warehouse');

    $this->post(route('migrations.add-to-deployment'), migrationAddToDeploymentPayload([
        'devices' => [
            [
                'name' => 'AP-One',
                'serial' => 'SNAAAAAAAAAAAA',
                'mac_address' => '11:22:33:44:55:66',
                'site' => 'Central Site',
                'group' => 'Central Group',
            ],
            [
                'name' => 'AP-Two',
                'serial' => 'SNBBBBBBBBBBBB',
                'mac_address' => '11:22:33:44:55:77',
                'site' => 'Warehouse',
                'group' => 'Classic Only Group',
            ],
        ],
    ]))
        ->assertRedirect(route('migrations.index'));

    $this->get(route('migrations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('last_added_to_deployment.device_count', 2));

    $one = Device::query()->where('serial', 'SNAAAAAAAAAAAA')->first();
    $two = Device::query()->where('serial', 'SNBBBBBBBBBBBB')->first();

    expect($one->site->name)->toBe('Central Site')
        ->and($one->group)->toBe('Central Group')
        ->and($one->deployment_id)->toBe($this->deployment->id);
    expect($two->site->name)->toBe('Warehouse')
        ->and($two->group)->toBe('Classic Only Group')
        ->and($two->deployment_id)->toBe($this->deployment->id);
});

test('add to deployment upserts existing device by serial for the user', function () {
    $otherDeployment = Deployment::factory()->for($this->client)->create(['name' => 'Old']);
    $existing = Device::factory()->create([
        'name' => 'Old Name',
        'serial' => 'CN1234567890',
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $otherDeployment->id,
        'device_function' => DeviceFunction::ACCESS_SWITCH->name,
        'mac_address' => null,
    ]);

    $this->post(route('migrations.add-to-deployment'), migrationAddToDeploymentPayload([
        'devices' => [
            [
                'name' => 'AP-Lobby-01',
                'serial' => 'CN1234567890',
                'mac_address' => 'aa:bb:cc:dd:ee:01',
                'controller_joined_ip' => '10.44.30.27',
                'site' => 'Central Site',
                'group' => 'Central Group',
            ],
        ],
    ]))
        ->assertRedirect(route('migrations.index'));

    $this->get(route('migrations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('last_added_to_deployment.device_count', 1));

    $existing->refresh();

    expect(Device::query()->where('serial', 'CN1234567890')->count())->toBe(1)
        ->and($existing->name)->toBe('AP-Lobby-01')
        ->and($existing->device_function)->toBe(DeviceFunction::CAMPUS_AP->name)
        ->and($existing->deployment_id)->toBe($this->deployment->id)
        ->and($existing->mac_address)->toBe('aa:bb:cc:dd:ee:01')
        ->and($existing->controller_joined_ip)->toBe('10.44.30.27')
        ->and($existing->group)->toBe('Central Group');
});

test('add to deployment rejects a deployment owned by another client', function () {
    $otherUser = User::factory()->create();
    $otherClient = Client::factory()->for($otherUser)->create([
        'current' => true,
        'base_url' => BaseURL::US1,
        'bearer_token' => 'other-bearer-token',
        'expires_at' => now()->addHour(),
    ]);
    $foreignDeployment = Deployment::factory()->for($otherClient)->create([
        'name' => 'Foreign Deployment',
    ]);

    $this->post(route('migrations.add-to-deployment'), migrationAddToDeploymentPayload([
        'deployment_id' => $foreignDeployment->id,
    ]))
        ->assertRedirect(route('migrations.index'))
        ->assertSessionHasErrors('deployment_id');

    expect(Device::query()->count())->toBe(0);
});

test('add to deployment stays on migrations page', function () {
    $response = $this->post(route('migrations.add-to-deployment'), migrationAddToDeploymentPayload());

    $response->assertRedirect(route('migrations.index'));
    expect($response->headers->get('X-Inertia-Location'))->toBeNull();

    $this->get(route('migrations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Migration/Index')
            ->has('parsed_controllers', 1)
            ->where('last_added_to_deployment.name', 'Existing Migration Deployment'));
});
