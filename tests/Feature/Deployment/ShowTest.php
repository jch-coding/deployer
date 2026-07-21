<?php

use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\LicensingInventoryDevice;
use App\Models\Site;
use App\Models\Task;
use App\Models\User;
use App\TaskType;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()
        ->has(Client::factory())
        ->create();
    $this->client = $this->user->clients()->first();
    $this->client->update([
        'current' => true,
        'bearer_token' => 'test-bearer-token',
        'expires_at' => now()->addHour(),
        'classic_base_url' => \App\ClassicBaseUrl::US1,
        'classic_client_id' => 'classic-id',
        'classic_client_secret' => 'classic-secret',
        'classic_username' => 'user',
        'classic_password' => 'pass',
        'classic_refresh_token' => 'refresh',
        'classic_expires_in' => now()->addHour(),
        'classic_access_token' => 'access-token',
    ]);

    seedCentralScopeCache($this->client);
});

it('shows a list of devices associated with the deployment', function () {
    $deployment = Deployment::factory()->for($this->client)->create();
    $devices = Device::factory(2)->for($deployment)->create();
    $this->actingAs($this->user);
    $this->get(route('deployments.show', $deployment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Deployment/Show')
            ->where('devices.0.name', $devices->first()->name)
            ->where('devices.1.name', $devices->last()->name)
        );
});

it('includes site and group on devices and central scope options', function () {
    $deployment = Deployment::factory()->for($this->client)->create();
    $site = Site::factory()->for($this->client)->create(['name' => 'Warehouse']);
    $device = Device::factory()->for($deployment)->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'site_id' => $site->id,
        'group' => 'Edge Switches',
        'mac_address' => 'aa:bb:cc:dd:ee:ff',
    ]);

    $this->actingAs($this->user)
        ->get(route('deployments.show', $deployment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Deployment/Show')
            ->where('devices.0.id', $device->id)
            ->where('devices.0.site', 'Warehouse')
            ->where('devices.0.group', 'Edge Switches')
            ->where('devices.0.mac_address', 'aa:bb:cc:dd:ee:ff')
            ->where('central_sites_error', null)
            ->where('central_device_groups_error', null)
            ->has('central_sites', 1)
            ->has('central_device_groups', 1)
            ->has('device_group_options', 2)
            ->where('central_sites.0.scopeName', 'Central Site')
            ->where('central_device_groups.0.scopeName', 'Central Group')
            ->where('device_group_options.0.scopeName', 'Central Group')
            ->where('device_group_options.0.isClassic', false)
            ->where('device_group_options.1.scopeName', 'Classic Only Group')
            ->where('device_group_options.1.isClassic', true)
            ->has('central_sites_cache.refreshed_at')
            ->has('central_groups_cache.refreshed_at'));
});

it('includes greenlake inventory model when present and blank when missing', function () {
    $deployment = Deployment::factory()->for($this->client)->create();
    $inInventory = Device::factory()->for($deployment)->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'SN-IN-INV',
    ]);
    $notInInventory = Device::factory()->for($deployment)->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'serial' => 'SN-NOT-IN-INV',
    ]);

    LicensingInventoryDevice::factory()->for($this->client)->create([
        'serial' => $inInventory->serial,
        'model' => '6300M',
    ]);

    $this->actingAs($this->user)
        ->get(route('deployments.show', $deployment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Deployment/Show')
            ->where('devices.0.id', $inInventory->id)
            ->where('devices.0.model', '6300M')
            ->where('devices.0.in_greenlake_inventory', true)
            ->where('devices.1.id', $notInInventory->id)
            ->where('devices.1.model', null)
            ->where('devices.1.in_greenlake_inventory', false));
});

it('returns all devices in a flat array', function () {
    $deployment = Deployment::factory()->for($this->client)->create();
    Device::factory(30)->for($deployment)->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('deployments.show', $deployment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('devices', 30));
});

it('still loads when a deployment has interface task history', function () {
    $deployment = Deployment::factory()->for($this->client)->create();
    $device = Device::factory()->for($deployment)->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
    ]);
    $interface = DeviceInterface::factory()->for($device)->create([
        'interface' => '1/1/1',
    ]);
    $task = Task::factory()->for($deployment)->create([
        'task_type' => 'CONFIGURE_ETHERNET_INTERFACE',
        'status' => 'COMPLETED',
    ]);
    $task->devices()->attach($device->id, ['status' => 'COMPLETED']);
    $task->deviceInterfaces()->attach($interface->id, ['status' => 'COMPLETED']);

    $this->actingAs($this->user)
        ->get(route('deployments.show', $deployment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Deployment/Show')
            ->has('items.CONFIGURE_ETHERNET_INTERFACE', 1)
            ->where('items.CONFIGURE_ETHERNET_INTERFACE.0.name', '1/1/1'));
});

it('still loads when central api requests fail', function () {
    Http::fake(function (HttpClientRequest $request) {
        throw new \Illuminate\Http\Client\RequestException(
            Http::response(['error' => 'forbidden'], 403)
        );
    });

    $this->client->update([
        'classic_client_id' => 'classic-client-id',
        'classic_client_secret' => 'classic-client-secret',
        'classic_username' => 'classic-user',
        'classic_password' => 'classic-password',
        'classic_refresh_token' => 'expired-refresh-token',
        'classic_expires_in' => now()->subMinute(),
        'licensing_synced_at' => now(),
    ]);

    $deployment = Deployment::factory()->for($this->client)->create();

    $this->actingAs($this->user)
        ->get(route('deployments.show', $deployment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Deployment/Show'));
});

it('finalizes expired in-progress tasks when viewing the deployment', function () {
    $deployment = Deployment::factory()->for($this->client)->create();
    $task = Task::factory()->for($deployment)->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 1,
    ]);
    $task->timestamps = false;
    $task->update(['created_at' => now()->subMinutes(10)]);

    $device = Device::factory()->for($deployment)->create();
    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    $this->actingAs($this->user);
    $this->get(route('deployments.show', $deployment))
        ->assertOk();

    expect($task->fresh()->status)->toBe('TIMED_OUT');
});

it('has an upload devices button', function () {
    $this->actingAs($this->user);
    $deployment = Deployment::factory()->for($this->client)->create();
    $this->get(route('deployments.show', $deployment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Deployment/Show')
            ->has('devices', 0)
        );
});

it('has a set of task types that are available for deployment', function ($task_name) {
    $deployment = Deployment::factory()->for($this->client)->create();
    $this->actingAs($this->user);

    $this->get(route('deployments.show', $deployment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Deployment/Show')
            ->where('tasks', fn ($tasks) => collect($tasks)->pluck('task_type')->contains($task_name))
        );
})->with(array_map(fn ($t) => $t->name, TaskType::cases()));

it('can add devices to a deployment', function () {
    $deployment = Deployment::factory()->for($this->client)->create();
    $this->actingAs($this->user);
    $uploadedFile = UploadedFile::fake()
        ->createWithContent('devices.csv',
            'name,serial,device_function,site,description'.PHP_EOL.'Test Device 1,SN0000000001,CAMPUS_AP,CO Warehouse,First Test Device'.PHP_EOL.'Test Device 2,SN0000000002,CAMPUS_AP,CO Warehouse,Test Device 2'.PHP_EOL
        );
    $this->post(route('devices.store-many', $deployment), ['devices' => $uploadedFile])
        ->assertRedirect(route('deployments.show', $deployment));

    $this->assertDatabaseHas('devices', ['name' => 'Test Device 1', 'serial' => 'SN0000000001']);
    $this->assertDatabaseHas('devices', ['name' => 'Test Device 2', 'serial' => 'SN0000000002']);
});
