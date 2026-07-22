<?php

use App\BaseURL;
use App\DeviceFunction;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\Site;
use App\Models\User;
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
    $this->actingAs($this->user);
    seedCentralScopeCache($this->client);
});

function migrationCreateDeploymentPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Migration AP Deployment',
        'devices' => [
            [
                'name' => 'AP-Lobby-01',
                'serial' => 'CN1234567890',
                'mac_address' => 'aa:bb:cc:dd:ee:01',
                'site' => 'Central Site',
                'group' => 'Central Group',
            ],
            [
                'name' => 'AP-Lobby-02',
                'serial' => 'CN1234567891',
                'mac_address' => 'aa-bb-cc-dd-ee-02',
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
                'wlan_profiles' => [],
            ],
        ],
    ], $overrides);
}

test('create deployment redirects when no current client is set', function () {
    $this->client->update(['current' => false]);

    $this->post(route('migrations.create-deployment'), migrationCreateDeploymentPayload())
        ->assertRedirect(route('clients.index'));
});

test('create deployment creates deployment and campus ap devices for current client', function () {
    $this->post(route('migrations.create-deployment'), migrationCreateDeploymentPayload())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Migration/Index')
            ->has('parsed_controllers', 1)
            ->where('parsed_controllers.0.controller_name', 'CTRL-1')
            ->where('last_created_deployment.name', 'Migration AP Deployment')
            ->where('last_created_deployment.device_count', 2)
            ->has('device_group_options', 2));

    $deployment = Deployment::query()
        ->where('client_id', $this->client->id)
        ->where('name', 'Migration AP Deployment')
        ->first();

    expect($deployment)->not->toBeNull();
    expect($deployment->devices)->toHaveCount(2);

    $first = Device::query()->where('serial', 'CN1234567890')->first();
    $second = Device::query()->where('serial', 'CN1234567891')->first();

    expect($first)->not->toBeNull()
        ->and($first->name)->toBe('AP-Lobby-01')
        ->and($first->device_function)->toBe(DeviceFunction::CAMPUS_AP->name)
        ->and($first->mac_address)->toBe('aa:bb:cc:dd:ee:01')
        ->and($first->group)->toBe('Central Group')
        ->and($first->deployment_id)->toBe($deployment->id)
        ->and($first->client_id)->toBe($this->client->id)
        ->and($first->user_id)->toBe($this->user->id);

    expect($first->site)->not->toBeNull()
        ->and($first->site->name)->toBe('Central Site')
        ->and($first->site->client_id)->toBe($this->client->id);

    expect($second)->not->toBeNull()
        ->and($second->device_function)->toBe(DeviceFunction::CAMPUS_AP->name)
        ->and($second->mac_address)->toBe('aa:bb:cc:dd:ee:02')
        ->and($second->group)->toBeNull()
        ->and($second->site_id)->toBeNull()
        ->and($second->deployment_id)->toBe($deployment->id);
});

test('create deployment applies different site and group per device', function () {
    Site::firstOrCreateForClient($this->client, 'Warehouse');

    $this->post(route('migrations.create-deployment'), migrationCreateDeploymentPayload([
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
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('last_created_deployment.device_count', 2));

    $one = Device::query()->where('serial', 'SNAAAAAAAAAAAA')->first();
    $two = Device::query()->where('serial', 'SNBBBBBBBBBBBB')->first();

    expect($one->site->name)->toBe('Central Site')
        ->and($one->group)->toBe('Central Group');
    expect($two->site->name)->toBe('Warehouse')
        ->and($two->group)->toBe('Classic Only Group');
});

test('create deployment rejects duplicate deployment name for the same client', function () {
    Deployment::factory()->for($this->client)->create(['name' => 'Existing Deployment']);

    $this->post(route('migrations.create-deployment'), migrationCreateDeploymentPayload([
        'name' => 'Existing Deployment',
    ]))
        ->assertSessionHasErrors('name');

    expect(Device::query()->count())->toBe(0);
});

test('create deployment upserts existing device by serial for the user', function () {
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

    $this->post(route('migrations.create-deployment'), migrationCreateDeploymentPayload([
        'devices' => [
            [
                'name' => 'AP-Lobby-01',
                'serial' => 'CN1234567890',
                'mac_address' => 'aa:bb:cc:dd:ee:01',
                'site' => 'Central Site',
                'group' => 'Central Group',
            ],
        ],
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('last_created_deployment.device_count', 1));

    $existing->refresh();
    $newDeployment = Deployment::query()->where('name', 'Migration AP Deployment')->first();

    expect(Device::query()->where('serial', 'CN1234567890')->count())->toBe(1)
        ->and($existing->name)->toBe('AP-Lobby-01')
        ->and($existing->device_function)->toBe(DeviceFunction::CAMPUS_AP->name)
        ->and($existing->deployment_id)->toBe($newDeployment->id)
        ->and($existing->mac_address)->toBe('aa:bb:cc:dd:ee:01')
        ->and($existing->group)->toBe('Central Group');
});

test('create deployment validates devices and mac address', function () {
    $this->post(route('migrations.create-deployment'), [
        'name' => 'Bad Devices',
        'devices' => [
            [
                'name' => 'AP',
                'serial' => 'short',
                'mac_address' => 'not-a-mac',
            ],
        ],
        'parsed_controllers' => [],
    ])
        ->assertSessionHasErrors([
            'devices.0.name',
            'devices.0.serial',
            'devices.0.mac_address',
        ]);
});

test('create deployment preserves parsed controllers and does not redirect to deployment show', function () {
    $response = $this->post(route('migrations.create-deployment'), migrationCreateDeploymentPayload());

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Migration/Index')
            ->has('parsed_controllers', 1));

    expect($response->headers->get('X-Inertia-Location'))->toBeNull();
    $deployment = Deployment::query()->where('name', 'Migration AP Deployment')->first();
    expect($response->headers->get('Location'))->toBeNull();
    expect($deployment)->not->toBeNull();
});
