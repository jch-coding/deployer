<?php

use App\DeviceFunction;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('must be authenticated to store a device', function () {
    $deployment = Deployment::factory()->create();
    $this->post(route('devices.store', $deployment), [
        'name' => 'Test Device',
        'serial' => 'TEST123',
        'device_function' => 'CAMPUS_AP',
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
        'device_function' => DeviceFunction::CAMPUS_AP->name,
    ]
    )
        ->assertRedirect(route('deployments.show', $client->deployments()->first()));
    $this->assertDatabaseHas('devices', [
        'name' => 'Test Device',
        'serial' => 'TEST12356789',
        'device_function' => DeviceFunction::CAMPUS_AP,
        'client_id' => $user->clients()->first()->id,
        'deployment_id' => $client->deployments()->first()->id,
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

test('users can each create devices with the same serial', function () {
    $userOne = User::factory()->has(Client::factory())->create();
    $clientOne = $userOne->clients()->first();
    $clientOne->update(['current' => true]);
    $deploymentOne = Deployment::factory()->recycle($clientOne)->create();

    $userTwo = User::factory()->has(Client::factory())->create();
    $clientTwo = $userTwo->clients()->first();
    $clientTwo->update(['current' => true]);
    $deploymentTwo = Deployment::factory()->recycle($clientTwo)->create();

    $payload = [
        'name' => 'Shared Serial Device',
        'serial' => 'SHAREDSERIAL01',
        'device_function' => DeviceFunction::ACCESS_SWITCH->name,
    ];

    $this->actingAs($userOne)
        ->post(route('devices.store', $deploymentOne), $payload)
        ->assertSessionHasNoErrors();

    $this->actingAs($userTwo)
        ->post(route('devices.store', $deploymentTwo), $payload)
        ->assertSessionHasNoErrors();

    $this->assertDatabaseCount('devices', 2);
    $this->assertDatabaseHas('devices', ['serial' => 'SHAREDSERIAL01', 'user_id' => $userOne->id]);
    $this->assertDatabaseHas('devices', ['serial' => 'SHAREDSERIAL01', 'user_id' => $userTwo->id]);
});

test('same user re-adding an existing serial updates their own device row', function () {
    $user = User::factory()->has(Client::factory())->create();
    $client = $user->clients()->first();
    $client->update(['current' => true]);
    $deployment = Deployment::factory()->recycle($client)->create();

    Device::factory()
        ->recycle($client)
        ->recycle($deployment)
        ->create([
            'name' => 'Original Name',
            'serial' => 'USEROWNEDSERIAL1',
            'user_id' => $user->id,
            'client_id' => $client->id,
            'deployment_id' => $deployment->id,
            'device_function' => DeviceFunction::ACCESS_SWITCH->name,
        ]);

    $this->actingAs($user)
        ->post(route('devices.store', $deployment), [
            'name' => 'Updated Name',
            'serial' => 'USEROWNEDSERIAL1',
            'device_function' => DeviceFunction::CAMPUS_AP->name,
        ])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseCount('devices', 1);
    $this->assertDatabaseHas('devices', [
        'serial' => 'USEROWNEDSERIAL1',
        'name' => 'Updated Name',
        'user_id' => $user->id,
        'device_function' => DeviceFunction::CAMPUS_AP->name,
    ]);
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
            'name,serial,device_function'.PHP_EOL.'Test Device 1,SN0000000001,CAMPUS_AP'.PHP_EOL.'Test Device 2,SN0000000002,CAMPUS_AP'.PHP_EOL
        );
    $this->post(route('devices.store-many', $deployment), [
        'devices' => $uploadedFile,
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
            'name,serial,device_function,site,description'.PHP_EOL.'Test Device 1,SN0000000001,CAMPUS_AP,CO Warehouse,First Test Device'.PHP_EOL.'Test Device 2,SN0000000002,CAMPUS_AP,CO Warehouse,Test Device 2'.PHP_EOL
        );
    $this->post(route('devices.store-many', $deployment), [
        'devices' => $uploadedFile,
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
            'name,serial,device_function,site,description'.PHP_EOL.'Test Device 1,SN0000000001,CAMPUS_AP,CO Warehouse,First Test Device'.PHP_EOL.'Test Device 2,SN0000000002,CAMPUS_AP,CO Warehouse,Test Device 2'.PHP_EOL
        );
    $this->post(route('devices.store-many', $deployment), [
        'devices' => $uploadedFile,
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
            .PHP_EOL.
            'CO-IDF-SW1,SN0000000001,ACCESS_SWITCH,1/1/1,30,ACCESS,,,to AP'
            .PHP_EOL.
            'CO-IDF-SW2,SN0000000002,ACCESS_SWITCH,1/1/2,30,ACCESS,,,to AP'
            .PHP_EOL
        );
    $this->post(route('devices.store-many', $deployment), [
        'devices' => $uploadedFile,
    ]);
    $this->assertDatabaseCount('devices', 2);
    $firstDevice = Device::query()->where('serial', 'SN0000000001')->firstOrFail();
    $secondDevice = Device::query()->where('serial', 'SN0000000002')->firstOrFail();
    $this->assertDatabaseHas('device_interfaces', ['device_id' => $firstDevice->id, 'interface' => '1/1/1']);
    $this->assertDatabaseHas('device_interfaces', ['device_id' => $secondDevice->id, 'interface' => '1/1/2']);
    $this->assertDatabaseHas('switch_ports', [
        'access_vlan' => 30,
        'interface_mode' => 'ACCESS',
        'native_vlan' => null,
        'trunk_vlan_all' => null,
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
            .PHP_EOL.
            'CO-IDF-SW1,SN0000000001,ACCESS_SWITCH,1/1/1-1/1/8&2/1/1-2/1/8,30,ACCESS,,,to AP'
            .PHP_EOL.
            'CO-IDF-SW1,SN0000000001,ACCESS_SWITCH,1/1/9-1/1/48&2/1/9-2/1/48,,TRUNK,8,true,voice and data'
            .PHP_EOL
        );
    $this->post(route('devices.store-many', $deployment), [
        'devices' => $uploadedFile,
    ]);
    $this->assertDatabaseCount('devices', 1);
    $this->assertDatabaseCount('device_interfaces', 96);
    $this->assertDatabaseCount('switch_ports', 2);
    $this->assertDatabaseHas('switch_ports', [
        'access_vlan' => 30,
        'interface_mode' => 'ACCESS',
        'native_vlan' => null,
        'trunk_vlan_all' => null,
    ]);
    $this->assertDatabaseHas('switch_ports', [
        'access_vlan' => null,
        'interface_mode' => 'TRUNK',
        'native_vlan' => 8,
        'trunk_vlan_all' => true,
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
            .PHP_EOL.
            'CO-IDF-SW1,SN0000000001,ACCESS_SWITCH,1/1/51&2/1/51&3/1/52&4/1/52,,TRUNK,8,true,LAG to Core,11'
            .PHP_EOL.
            'CO-IDF-SW1,SN0000000001,ACCESS_SWITCH,1/1/9-1/1/48&2/1/9-2/1/48,,TRUNK,8,true,voice and data'
            .PHP_EOL
        );
    $this->post(route('devices.store-many', $deployment), [
        'devices' => $uploadedFile,
    ]);

    $this->assertDatabaseCount('devices', 1);
    $this->assertDatabaseCount('device_interfaces', 81);
    $this->assertDatabaseCount('switch_ports', 1);
    $this->assertDatabaseCount('lacp_profiles', 1);
    $this->assertDatabaseHas('lacp_profiles', ['port_id' => 11, 'mode' => 'ACTIVE', 'rate' => 'SLOW']);
});

it('updates device optionals without mutating interfaces when a later upload has no interface column', function () {
    $user = User::factory()->has(Client::factory())->create();
    $client = $user->clients()->first();
    $client->update(['current' => true]);
    $deployment = Deployment::factory()->recycle($client)->create();
    $this->actingAs($user);

    $initialUpload = UploadedFile::fake()->createWithContent(
        'devices.csv',
        'name,serial,device_function,group,sku,site,interface,description,ip_address'.PHP_EOL.
        'SW-1,SNMPASS00001,ACCESS_SWITCH,Group-A,JL660A,Site A,1/1/1,Original Desc,10.10.10.1/24'.PHP_EOL
    );

    $this->post(route('devices.store-many', $deployment), ['devices' => $initialUpload])
        ->assertRedirect(route('deployments.show', $deployment));

    $device = Device::query()->where('serial', 'SNMPASS00001')->firstOrFail();
    $interface = DeviceInterface::query()->where('device_id', $device->id)->where('interface', '1/1/1')->firstOrFail();
    $originalSiteId = $device->site_id;

    $secondUpload = UploadedFile::fake()->createWithContent(
        'devices.csv',
        'name,serial,device_function,group,sku,site'.PHP_EOL.
        'SW-1 Renamed,SNMPASS00001,ACCESS_SWITCH,Group-B,JL661A,Site B'.PHP_EOL
    );

    $this->post(route('devices.store-many', $deployment), ['devices' => $secondUpload])
        ->assertRedirect(route('deployments.show', $deployment));

    $device->refresh();
    $interface->refresh();

    expect($device->name)->toBe('SW-1 Renamed')
        ->and($device->group)->toBe('Group-B')
        ->and($device->sku)->toBe('JL661A')
        ->and($device->site_id)->not()->toBe($originalSiteId)
        ->and(Site::query()->where('name', 'Site B')->exists())->toBeTrue();

    expect($interface->description)->toBe('Original Desc')
        ->and($interface->ip_address)->toBe('10.10.10.1/24');
});

it('accepts snake_case optional headers and persists interface-related fields', function () {
    $user = User::factory()->has(Client::factory())->create();
    $client = $user->clients()->first();
    $client->update(['current' => true]);
    $deployment = Deployment::factory()->recycle($client)->create();
    $this->actingAs($user);

    $uploadedFile = UploadedFile::fake()->createWithContent(
        'devices.csv',
        'name,serial,device_function,interface,interface_mode,native_vlan,trunk_vlan_all,trunk_vlan_ranges,description,admin_edge_port,bpdu_guard'.PHP_EOL.
        'SW-2,SN_SNAKE_0001,ACCESS_SWITCH,1/1/10,TRUNK,20,false,20-30,Uplink Interface,true,true'.PHP_EOL
    );

    $this->post(route('devices.store-many', $deployment), ['devices' => $uploadedFile])
        ->assertRedirect(route('deployments.show', $deployment));

    $device = Device::query()->where('serial', 'SN_SNAKE_0001')->firstOrFail();

    $this->assertDatabaseHas('device_interfaces', [
        'device_id' => $device->id,
        'interface' => '1/1/10',
        'description' => 'Uplink Interface',
    ]);
    $this->assertDatabaseHas('switch_ports', [
        'interface_mode' => 'TRUNK',
        'native_vlan' => 20,
        'trunk_vlan_all' => false,
        'trunk_vlan_ranges' => '20-30',
    ]);
    $this->assertDatabaseHas('stp_profiles', [
        'admin_edge_port' => true,
        'bpdu_guard' => true,
    ]);
});

it('persists routed ethernet interfaces with routing and optional vrf_forwarding', function () {
    $user = User::factory()->has(Client::factory())->create();
    $client = $user->clients()->first();
    $client->update(['current' => true]);
    $deployment = Deployment::factory()->recycle($client)->create();
    $this->actingAs($user);

    $uploadedFile = UploadedFile::fake()->createWithContent(
        'devices.csv',
        'name,serial,device_function,interface,description,ip_address,vrf_forwarding'.PHP_EOL.
        'SW-2,SN_ROUTE_0001,ACCESS_SWITCH,1/1/53,Routed uplink,10.255.0.1/30,my-vrf'.PHP_EOL
    );

    $this->post(route('devices.store-many', $deployment), ['devices' => $uploadedFile])
        ->assertRedirect(route('deployments.show', $deployment));

    $device = Device::query()->where('serial', 'SN_ROUTE_0001')->firstOrFail();
    $interface = DeviceInterface::query()->where('device_id', $device->id)->where('interface', '1/1/53')->firstOrFail();

    expect($interface->ip_address)->toBe('10.255.0.1/30')
        ->and($interface->routing)->toBeTrue()
        ->and($interface->vrf_forwarding)->toBe('my-vrf')
        ->and($interface->switch_port_id)->toBeNull()
        ->and($interface->stp_profile_id)->toBeNull()
        ->and($interface->lacp_profile_id)->toBeNull()
        ->and($interface->sw_profile)->toBeNull();
});

it('persists routed LAG interfaces with routing and optional vrf_forwarding', function () {
    $user = User::factory()->has(Client::factory())->create();
    $client = $user->clients()->first();
    $client->update(['current' => true]);
    $deployment = Deployment::factory()->recycle($client)->create();
    $this->actingAs($user);

    $uploadedFile = UploadedFile::fake()->createWithContent(
        'devices.csv',
        'name,serial,device_function,interface,description,port_list,ip_address,vrf_forwarding,trunk_type,lacp_mode'.PHP_EOL.
        'SW-2,SN_LAG_ROUTE01,ACCESS_SWITCH,11,Routed LAG,1/1/1-1/1/2,10.255.0.1/30,my-vrf,LACP,ACTIVE'.PHP_EOL
    );

    $this->post(route('devices.store-many', $deployment), ['devices' => $uploadedFile])
        ->assertRedirect(route('deployments.show', $deployment));

    $device = Device::query()->where('serial', 'SN_LAG_ROUTE01')->firstOrFail();
    $interface = DeviceInterface::query()->where('device_id', $device->id)->where('interface', '11')->firstOrFail();

    expect($interface->ip_address)->toBe('10.255.0.1/30')
        ->and($interface->routing)->toBeTrue()
        ->and($interface->vrf_forwarding)->toBe('my-vrf')
        ->and($interface->interface_kind->value)->toBe('LAG')
        ->and($interface->switch_port_id)->toBeNull()
        ->and($interface->stp_profile_id)->toBeNull()
        ->and($interface->sw_profile)->toBeNull()
        ->and($interface->lacp_profile_id)->not->toBeNull();
});

it('rejects csv rows that mix ip_address with switchport columns on ethernet interfaces', function () {
    $user = User::factory()->has(Client::factory())->create();
    $client = $user->clients()->first();
    $client->update(['current' => true]);
    $deployment = Deployment::factory()->recycle($client)->create();
    $this->actingAs($user);

    $uploadedFile = UploadedFile::fake()->createWithContent(
        'devices.csv',
        'name,serial,device_function,interface,interface_mode,native_vlan,description,ip_address'.PHP_EOL.
        'SW-2,SN_MIXED0001,ACCESS_SWITCH,1/1/10,TRUNK,20,Uplink Interface,10.20.30.1/24'.PHP_EOL
    );

    $this->post(route('devices.store-many', $deployment), ['devices' => $uploadedFile])
        ->assertSessionHasErrors();
});

it('does not create interfaces when optional interface column is present but blank', function () {
    $user = User::factory()->has(Client::factory())->create();
    $client = $user->clients()->first();
    $client->update(['current' => true]);
    $deployment = Deployment::factory()->recycle($client)->create();
    $this->actingAs($user);

    $uploadedFile = UploadedFile::fake()->createWithContent(
        'devices.csv',
        'name,serial,device_function,interface,description,ip_address'.PHP_EOL.
        'SW-3,SNBLANKINT01,ACCESS_SWITCH,,Should Be Ignored,10.0.0.1/24'.PHP_EOL
    );

    $this->post(route('devices.store-many', $deployment), ['devices' => $uploadedFile])
        ->assertRedirect(route('deployments.show', $deployment));

    $this->assertDatabaseHas('devices', ['serial' => 'SNBLANKINT01']);
    $this->assertDatabaseMissing('device_interfaces', ['description' => 'Should Be Ignored']);
});

it('rejects a csv with an invalid device_function value', function () {
    $user = User::factory()->has(Client::factory())->create();
    $user->clients()->first()->update(['current' => true]);
    $deployment = Deployment::factory()->recycle($user->clients()->first())->create();
    $this->actingAs($user);

    $uploadedFile = UploadedFile::fake()->createWithContent(
        'devices.csv',
        'name,serial,device_function'.PHP_EOL.
        'Bad Name,SN0000000001,INVALID_DEVICE_FUNCTION'.PHP_EOL
    );

    $this->post(route('devices.store-many', $deployment), ['devices' => $uploadedFile])
        ->assertSessionHasErrors();
});

it('uploads devices with vsx profile columns', function () {
    Storage::fake('devices');
    $user = User::factory()
        ->has(Client::factory())
        ->create();
    $client = $user->clients()->first();
    $client->update(['current' => true]);
    $deployment = Deployment::factory()->recycle($client)->create();
    $this->actingAs($user);

    $uploadedFile = UploadedFile::fake()->createWithContent(
        'devices.csv',
        'name,serial,device_function,group,site,vsx_profile,vsx_role,vsx_system_mac'.PHP_EOL.
        'Primary-SW,SN0000000001,ACCESS_SWITCH,WHSE-TEST,Site-A,vsx-pair-1,VSX_PRIMARY,2:0:0:0:0:1'.PHP_EOL.
        'Secondary-SW,SN0000000002,ACCESS_SWITCH,WHSE-TEST,Site-A,vsx-pair-1,VSX_SECONDARY,2:0:0:0:0:1'.PHP_EOL
    );

    $this->post(route('devices.store-many', $deployment), ['devices' => $uploadedFile])
        ->assertRedirect(route('deployments.show', $deployment));

    $this->assertDatabaseHas('devices', [
        'serial' => 'SN0000000001',
        'vsx_profile' => 'vsx-pair-1',
        'vsx_role' => 'VSX_PRIMARY',
        'vsx_system_mac' => '02:00:00:00:00:01',
    ]);
});

it('rejects a csv missing the serial header with a specific error', function () {
    $user = User::factory()->has(Client::factory())->create();
    $user->clients()->first()->update(['current' => true]);
    $deployment = Deployment::factory()->recycle($user->clients()->first())->create();
    $this->actingAs($user);

    $uploadedFile = UploadedFile::fake()->createWithContent(
        'devices.csv',
        'name,device_function'.PHP_EOL.
        'SW-1,ACCESS_SWITCH'.PHP_EOL
    );

    $this->post(route('devices.store-many', $deployment), ['devices' => $uploadedFile])
        ->assertSessionHasErrors('CSV headers: missing required columns');
});

it('rejects a csv with an unrecognized column header', function () {
    $user = User::factory()->has(Client::factory())->create();
    $user->clients()->first()->update(['current' => true]);
    $deployment = Deployment::factory()->recycle($user->clients()->first())->create();
    $this->actingAs($user);

    $uploadedFile = UploadedFile::fake()->createWithContent(
        'devices.csv',
        'name,serial,device_function,acces_vlan'.PHP_EOL.
        'SW-1,SN0000000001,ACCESS_SWITCH,10'.PHP_EOL
    );

    $this->post(route('devices.store-many', $deployment), ['devices' => $uploadedFile])
        ->assertSessionHasErrors('CSV headers: unrecognized columns');
});

it('uploads devices when child interface rows omit serial and device_function', function () {
    $user = User::factory()->has(Client::factory())->create();
    $user->clients()->first()->update(['current' => true]);
    $deployment = Deployment::factory()->recycle($user->clients()->first())->create();
    $this->actingAs($user);

    $uploadedFile = UploadedFile::fake()->createWithContent(
        'devices.csv',
        'name,serial,device_function,interface'.PHP_EOL.
        'ACC-SWITCH-1,SN0000000001,ACCESS_SWITCH,1/1/1'.PHP_EOL.
        'ACC-SWITCH-1,,,1/1/2'.PHP_EOL
    );

    $this->post(route('devices.store-many', $deployment), ['devices' => $uploadedFile])
        ->assertRedirect(route('deployments.show', $deployment));

    $this->assertDatabaseHas('devices', ['serial' => 'SN0000000001', 'name' => 'ACC-SWITCH-1']);
});

it('uploads devices when a name-only organizational row is present', function () {
    $user = User::factory()->has(Client::factory())->create();
    $user->clients()->first()->update(['current' => true]);
    $deployment = Deployment::factory()->recycle($user->clients()->first())->create();
    $this->actingAs($user);

    $uploadedFile = UploadedFile::fake()->createWithContent(
        'devices.csv',
        'name,serial,device_function,interface'.PHP_EOL.
        'Building A Switches,,,'.PHP_EOL.
        'SW-1,SN0000000002,ACCESS_SWITCH,1/1/1'.PHP_EOL
    );

    $this->post(route('devices.store-many', $deployment), ['devices' => $uploadedFile])
        ->assertRedirect(route('deployments.show', $deployment));

    $this->assertDatabaseHas('devices', ['serial' => 'SN0000000002', 'name' => 'SW-1']);
});
