<?php

use App\ClassicBaseUrl;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = Client::factory()->for($this->user)->create([
        'classic_base_url' => ClassicBaseUrl::US1,
        'classic_client_id' => 'classic-id',
        'classic_client_secret' => 'classic-secret',
        'classic_username' => 'user',
        'classic_password' => 'pass',
        'classic_refresh_token' => 'refresh',
        'classic_expires_in' => now()->addHour(),
        'classic_access_token' => 'access-token',
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
        'current' => true,
    ]);
    $this->deployment = Deployment::factory()->for($this->client)->create();
    $this->actingAs($this->user);
});

test('check greenlake inventory step 0 syncs and reports progress', function () {
    Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'serial' => 'SN-IN-GL-001',
        'mac_address' => 'aa:bb:cc:dd:ee:01',
    ]);

    fakeLicensingCentralApis(
        devices: [[
            'serial' => 'SN-IN-GL-001',
            'mac' => 'aa:bb:cc:dd:ee:01',
            'model' => 'AP-515',
            'device_type' => 'IAP',
            'licensed' => false,
        ]],
    );

    $this->getJson(route('tasks.check_greenlake_inventory.step', [$this->deployment, 0]))
        ->assertOk()
        ->assertJsonPath('progress.current', 1)
        ->assertJsonPath('progress.total', 2)
        ->assertJsonPath('progress.message', 'Syncing GreenLake inventory...')
        ->assertJsonPath('done', false)
        ->assertJsonPath('partial.missing_from_inventory', [])
        ->assertJsonPath('partial.missing_mac', []);
});

test('check greenlake inventory steps succeed when all devices are present', function () {
    $device = Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'name' => 'AP-One',
        'serial' => 'SN-IN-GL-001',
        'mac_address' => 'aa:bb:cc:dd:ee:01',
    ]);

    fakeLicensingCentralApis(
        devices: [[
            'serial' => 'SN-IN-GL-001',
            'mac' => 'aa:bb:cc:dd:ee:01',
            'model' => 'AP-515',
            'device_type' => 'IAP',
            'licensed' => false,
        ]],
    );

    $this->getJson(route('tasks.check_greenlake_inventory.step', [$this->deployment, 0]))
        ->assertOk();

    $this->getJson(route('tasks.check_greenlake_inventory.step', [$this->deployment, 1]))
        ->assertOk()
        ->assertJsonPath('progress.current', 2)
        ->assertJsonPath('progress.total', 2)
        ->assertJsonPath('progress.message', "Checking {$device->name} ({$device->serial})...")
        ->assertJsonPath('done', true)
        ->assertJsonPath('partial.missing_from_inventory', [])
        ->assertJsonPath('partial.missing_mac', [])
        ->assertJsonPath('summary.ok', true)
        ->assertJsonPath('summary.passed_count', 1)
        ->assertJsonPath('summary.failed_devices', [])
        ->assertJsonPath(
            'summary.message',
            'All deployment devices are present in GreenLake inventory.',
        );
});

test('check greenlake inventory steps report devices missing from inventory', function () {
    Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'name' => 'Missing-AP',
        'serial' => 'SN-MISSING-001',
        'mac_address' => 'aa:bb:cc:dd:ee:02',
    ]);
    $present = Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'name' => 'Present-AP',
        'serial' => 'SN-PRESENT-001',
        'mac_address' => 'aa:bb:cc:dd:ee:03',
    ]);

    fakeLicensingCentralApis(
        devices: [[
            'serial' => 'SN-PRESENT-001',
            'mac' => 'aa:bb:cc:dd:ee:03',
            'model' => 'AP-515',
            'device_type' => 'IAP',
            'licensed' => false,
        ]],
    );

    $this->getJson(route('tasks.check_greenlake_inventory.step', [$this->deployment, 0]))
        ->assertOk();

    $this->getJson(route('tasks.check_greenlake_inventory.step', [$this->deployment, 1]))
        ->assertOk()
        ->assertJsonPath('partial.missing_from_inventory', ['SN-MISSING-001'])
        ->assertJsonPath('done', false);

    $this->getJson(route('tasks.check_greenlake_inventory.step', [$this->deployment, 2]))
        ->assertOk()
        ->assertJsonPath('progress.message', "Checking {$present->name} ({$present->serial})...")
        ->assertJsonPath('partial.missing_from_inventory', [])
        ->assertJsonPath('done', true)
        ->assertJsonPath('summary.ok', false)
        ->assertJsonPath('summary.passed_count', 1)
        ->assertJsonPath('summary.failed_devices', ['SN-MISSING-001'])
        ->assertJsonPath(
            'summary.message',
            'These devices were not found in GreenLake inventory: SN-MISSING-001.',
        );
});

test('check greenlake inventory notes devices missing mac_address', function () {
    Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'serial' => 'SN-NO-MAC-001',
        'mac_address' => null,
    ]);

    fakeLicensingCentralApis(
        devices: [[
            'serial' => 'SN-NO-MAC-001',
            'mac' => 'aa:bb:cc:dd:ee:04',
            'model' => 'AP-515',
            'device_type' => 'IAP',
            'licensed' => false,
        ]],
    );

    $this->getJson(route('tasks.check_greenlake_inventory.step', [$this->deployment, 0]))
        ->assertOk();

    $this->getJson(route('tasks.check_greenlake_inventory.step', [$this->deployment, 1]))
        ->assertOk()
        ->assertJsonPath('done', true)
        ->assertJsonPath('partial.missing_mac', ['SN-NO-MAC-001'])
        ->assertJsonPath('summary.ok', true)
        ->assertJsonPath('summary.passed_count', 1)
        ->assertJsonPath('summary.failed_devices', [])
        ->assertJsonPath(
            'summary.message',
            'All deployment devices are present in GreenLake inventory. Devices missing mac_address: SN-NO-MAC-001.',
        );
});

test('check greenlake inventory step 0 returns error when sync fails', function () {
    Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'serial' => 'SN-ANY-001',
        'mac_address' => 'aa:bb:cc:dd:ee:05',
    ]);

    Http::fake([
        '*' => Http::response(['detail' => 'unavailable'], 500),
    ]);

    $this->getJson(route('tasks.check_greenlake_inventory.step', [$this->deployment, 0]))
        ->assertStatus(422)
        ->assertJsonStructure(['message']);
});

test('check greenlake inventory requires matching current client', function () {
    $otherClient = Client::factory()->for($this->user)->create([
        'classic_base_url' => ClassicBaseUrl::US1,
        'classic_client_id' => 'other-id',
        'classic_client_secret' => 'other-secret',
        'classic_username' => 'user',
        'classic_password' => 'pass',
        'classic_refresh_token' => 'refresh',
        'classic_expires_in' => now()->addHour(),
        'classic_access_token' => 'access-token',
        'bearer_token' => 'greenlake-token',
        'expires_at' => now()->addHour(),
        'current' => false,
    ]);
    $otherDeployment = Deployment::factory()->for($otherClient)->create();

    Device::factory()->create([
        'client_id' => $otherClient->id,
        'user_id' => $this->user->id,
        'deployment_id' => $otherDeployment->id,
        'serial' => 'SN-OTHER-001',
        'mac_address' => 'aa:bb:cc:dd:ee:06',
    ]);

    $this->getJson(route('tasks.check_greenlake_inventory.step', [$otherDeployment, 0]))
        ->assertForbidden()
        ->assertJsonPath(
            'message',
            'Please set current client to match this deployment before checking GreenLake inventory.',
        );

    Http::assertNothingSent();
});

test('check greenlake inventory returns 422 when deployment has no devices', function () {
    $this->getJson(route('tasks.check_greenlake_inventory.step', [$this->deployment, 0]))
        ->assertStatus(422)
        ->assertJsonPath('message', 'No devices are in this deployment.');
});
