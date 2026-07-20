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

test('check greenlake inventory flashes success when all devices are present', function () {
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

    $this->post(route('tasks.check_greenlake_inventory', $this->deployment), [
        'task_type' => 'ADD_DEVICES_TO_GREENLAKE_INVENTORY',
    ])
        ->assertRedirect()
        ->assertSessionHas('success', 'All deployment devices are present in GreenLake inventory.')
        ->assertSessionHas('missing_greenlake_inventory', []);
});

test('check greenlake inventory flashes error listing devices missing from inventory', function () {
    Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'serial' => 'SN-MISSING-001',
        'mac_address' => 'aa:bb:cc:dd:ee:02',
    ]);
    Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
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

    $this->post(route('tasks.check_greenlake_inventory', $this->deployment), [
        'task_type' => 'ADD_DEVICES_TO_GREENLAKE_INVENTORY',
    ])
        ->assertRedirect()
        ->assertSessionHas('error', 'These devices were not found in GreenLake inventory: SN-MISSING-001.')
        ->assertSessionHas('missing_greenlake_inventory', ['SN-MISSING-001']);
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

    $this->post(route('tasks.check_greenlake_inventory', $this->deployment), [
        'task_type' => 'ADD_DEVICES_TO_GREENLAKE_INVENTORY',
    ])
        ->assertRedirect()
        ->assertSessionHas(
            'success',
            'All deployment devices are present in GreenLake inventory. Devices missing mac_address: SN-NO-MAC-001.',
        );
});

test('check greenlake inventory flashes error when sync fails', function () {
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

    $this->post(route('tasks.check_greenlake_inventory', $this->deployment), [
        'task_type' => 'ADD_DEVICES_TO_GREENLAKE_INVENTORY',
    ])
        ->assertRedirect()
        ->assertSessionHas('error');
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

    $this->post(route('tasks.check_greenlake_inventory', $otherDeployment), [
        'task_type' => 'ADD_DEVICES_TO_GREENLAKE_INVENTORY',
    ])
        ->assertRedirect()
        ->assertSessionHas(
            'error',
            'Please set current client to match this deployment before checking GreenLake inventory.',
        );

    Http::assertNothingSent();
});
