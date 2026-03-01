<?php

use App\DeviceFunction;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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

it('can add a list of devices to a deployment from a csv file upload', function () {
    Storage::fake('devices');
    $user = User::factory()
        ->has(Client::factory())
        ->create();
    $client = $user->clients()->first();
    $client->update(['current' => true]);
    $deployment = Deployment::factory()->recycle($client)->create();
    $this->actingAs($user);
    $uploadedFile = UploadedFile::fake()
        ->createWithContent('devices.csv',
            'name,serial,device_function' . PHP_EOL . 'Test Device 1,SN0000000001,CAMPUS_AP' . PHP_EOL . 'Test Device 2,SN0000000002,CAMPUS_AP' . PHP_EOL
            );
    $this->post(route('devices.store-many', $deployment), [
        'devices' => $uploadedFile
    ])
        ->assertRedirect(route('deployments.show', $deployment));
    $this->assertDatabaseCount('devices', 2);
});

it('selectively saves only required fields when adding a list of devices from a csv file upload', function () {
    $this->withoutExceptionHandling();
    $user = User::factory()
        ->has(Client::factory())
        ->create();
    $client = $user->clients()->first();
    $client->update(['current' => true]);
    $deployment = Deployment::factory()->recycle($client)->create();
    $this->actingAs($user);
    $uploadedFile = UploadedFile::fake()
        ->createWithContent('devices.csv',
            'name,serial,device_function,site,description' . PHP_EOL . 'Test Device 1,SN0000000001,CAMPUS_AP,CO Warehouse,First Test Device' . PHP_EOL . 'Test Device 2,SN0000000002,CAMPUS_AP,CO Warehouse,Test Device 2' . PHP_EOL
        );
    $this->post(route('devices.store-many', $deployment), [
        'devices' => $uploadedFile
    ]);
    $this->assertDatabaseCount('devices', 2);
    $this->assertDatabaseHas('devices', ['name' => 'Test Device 1', 'serial' => 'SN0000000001', 'device_function' => DeviceFunction::CAMPUS_AP]);
    $this->assertDatabaseHas('devices', ['name' => 'Test Device 2', 'serial' => 'SN0000000002', 'device_function' => DeviceFunction::CAMPUS_AP]);
});

it('uploading an existing device with different information will update it in the database', function () {
    $this->withoutExceptionHandling();
    $user = User::factory()
        ->has(Client::factory())
        ->create();
    $client = $user->clients()->first();
    $client->update(['current' => true]);
    $deployment = Deployment::factory()->recycle($client)->create();
    Device::factory()
        ->recycle($client)
        ->recycle($deployment)
        ->create([
            'name' => 'Test Device Original',
            'serial' => 'SN0000000001',
            'device_function' => DeviceFunction::CAMPUS_AP,
            'deployment_id' => $deployment->id,
        ]);
    $this->actingAs($user);
    $uploadedFile = UploadedFile::fake()
        ->createWithContent('devices.csv',
            'name,serial,device_function,site,description' . PHP_EOL . 'Test Device 1,SN0000000001,CAMPUS_AP,CO Warehouse,First Test Device' . PHP_EOL . 'Test Device 2,SN0000000002,CAMPUS_AP,CO Warehouse,Test Device 2' . PHP_EOL
        );
    $this->post(route('devices.store-many', $deployment), [
        'devices' => $uploadedFile
    ]);
    $this->assertDatabaseCount('devices', 2);
    $this->assertDatabaseHas('devices', ['name' => 'Test Device 1', 'serial' => 'SN0000000001', 'device_function' => DeviceFunction::CAMPUS_AP]);
    $this->assertDatabaseHas('devices', ['name' => 'Test Device 2', 'serial' => 'SN0000000002', 'device_function' => DeviceFunction::CAMPUS_AP]);
});

test('uploading interface information for a new device will populate the device interfaces table', function () {
    $this->withoutExceptionHandling();
    $user = User::factory()
        ->has(Client::factory())
        ->create();
    $client = $user->clients()->first();
    $client->update(['current' => true]);
    $deployment = Deployment::factory()->recycle($client)->create();
    $this->actingAs($user);
    $uploadedFile = UploadedFile::fake()
        ->createWithContent('devices.csv',
            'name,serial,device_function,interface,access-vlan,interface-mode,native-vlan,trunk-vlan-all,interface_description'
            . PHP_EOL .
            'CO-IDF-SW1,SN0000000001,ACCESS_SWITCH,1/1/1,30,ACCESS,,,to AP'
            . PHP_EOL .
            'CO-IDF-SW2,SN0000000002,ACCESS_SWITCH,1/1/2,30,ACCESS,,,to AP'
            . PHP_EOL
        );
    $this->post(route('devices.store-many', $deployment), [
        'devices' => $uploadedFile
    ]);
    $this->assertDatabaseCount('devices', 2);
    $this->assertDatabaseHas('device_interfaces', ['device_id' => 1, 'switch_port_id' => 1, 'name' => '1/1/1']);
    $this->assertDatabaseHas('device_interfaces', ['device_id' => 2, 'switch_port_id' => 1, 'name' => '1/1/2']);
    $this->assertDatabaseHas('switch_ports', [
        'access_vlan' => 30,
        'interface_mode' => 'ACCESS',
        'native_vlan' => null,
        'trunk_vlan_all' => null,
        'description' => 'CO IDF Stack 1'
    ]);
});

test('interface ranges create the set of switch ports indicated in the range', function () {
    $this->withoutExceptionHandling();
    $user = User::factory()
        ->has(Client::factory())
        ->create();
    $client = $user->clients()->first();
    $client->update(['current' => true]);
    $deployment = Deployment::factory()->recycle($client)->create();
    $this->actingAs($user);
    $uploadedFile = UploadedFile::fake()
        ->createWithContent('devices.csv',
            'name,serial,device_function,interface,access-vlan,interface-mode,native-vlan,trunk-vlan-all,interface_description'
            . PHP_EOL .
            'CO-IDF-SW1,SN0000000001,ACCESS_SWITCH,1/1/1-1/1/8&2/1/1-2/1/8,30,ACCESS,,,to AP'
            . PHP_EOL .
            'CO-IDF-SW1,SN0000000001,ACCESS_SWITCH,1/1/9-1/1/48&2/1/9-2/1/48,,TRUNK,8,true,voice and data'
            . PHP_EOL
        );
    $this->post(route('devices.store-many', $deployment), [
        'devices' => $uploadedFile
    ]);
    $this->assertDatabaseCount('devices', 1);
    $this->assertDatabaseCount('device_interfaces', 96);
    $this->assertDatabaseCount('switch_ports', 2);
    $this->assertDatabaseHas('switch_ports', [
        'access_vlan' => 30,
        'interface_mode' => 'ACCESS',
        'native_vlan' => null,
        'trunk_vlan_all' => false,
        'description' => 'CO IDF Stack 1'
    ]);
    $this->assertDatabaseHas('switch_ports', [
        'access_vlan' => null,
        'interface_mode' => 'TRUNK',
        'native_vlan' => 8,
        'trunk_vlan_all' => true,
        'description' => 'CO IDF Stack 1'
    ]);
});

test('a device can have a lag interface', function () {
    $this->withoutExceptionHandling();
    $user = User::factory()
        ->has(Client::factory())
        ->create();
    $client = $user->clients()->first();
    $client->update(['current' => true]);
    $deployment = Deployment::factory()->recycle($client)->create();
    $this->actingAs($user);
    $uploadedFile = UploadedFile::fake()
        ->createWithContent('devices.csv',
            'name,serial,device_function,interface,access-vlan,interface-mode,native-vlan,trunk-vlan-all,interface_description,lag_id'
            . PHP_EOL .
            'CO-IDF-SW1,SN0000000001,ACCESS_SWITCH,1/1/51&2/1/51&3/1/52&4/1/52,,TRUNK,8,true,LAG to Core,11'
            . PHP_EOL .
            'CO-IDF-SW1,SN0000000001,ACCESS_SWITCH,1/1/9-1/1/48&2/1/9-2/1/48,,TRUNK,8,true,voice and data'
            . PHP_EOL
        );
    $this->post(route('devices.store-many', $deployment), [
        'devices' => $uploadedFile
    ]);

    $this->assertDatabaseCount('devices', 1);
    $this->assertDatabaseCount('device_interfaces', 44);
    $this->assertDatabaseCount('switch_ports', 2);
    $this->assertDatabaseCount('lacp_profiles', 1);
    $this->assertDatabaseHas('lacp_profiles', ['port_id' => 11, 'mode' => 'ACTIVE', 'timeout' => 'SHORT']);
});
