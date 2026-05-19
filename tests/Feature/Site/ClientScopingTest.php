<?php

use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\UploadedFile;

test('sites with the same name are distinct per client', function () {
    $user = User::factory()->create();
    $clientA = Client::factory()->for($user)->create(['current' => true]);
    $clientB = Client::factory()->for($user)->create(['current' => false]);
    $deploymentA = Deployment::factory()->for($clientA)->create();
    $deploymentB = Deployment::factory()->for($clientB)->create();

    $this->actingAs($user);

    $uploadA = UploadedFile::fake()->createWithContent(
        'devices-a.csv',
        'name,serial,device_function,site'.PHP_EOL.
        'SW-A,SNMPASS00001,ACCESS_SWITCH,HQ'.PHP_EOL
    );
    $uploadB = UploadedFile::fake()->createWithContent(
        'devices-b.csv',
        'name,serial,device_function,site'.PHP_EOL.
        'SW-B,SNMPASS00002,ACCESS_SWITCH,HQ'.PHP_EOL
    );

    $clientB->update(['current' => true]);
    $clientA->update(['current' => false]);
    $this->post(route('devices.store-many', $deploymentB), ['devices' => $uploadB])
        ->assertRedirect(route('deployments.show', $deploymentB));

    $clientA->update(['current' => true]);
    $clientB->update(['current' => false]);
    $this->post(route('devices.store-many', $deploymentA), ['devices' => $uploadA])
        ->assertRedirect(route('deployments.show', $deploymentA));

    $sites = Site::query()->where('name', 'HQ')->get();
    expect($sites)->toHaveCount(2)
        ->and($sites->pluck('client_id')->sort()->values()->all())->toBe(
            collect([$clientA->id, $clientB->id])->sort()->values()->all()
        );

    $deviceA = Device::query()->where('serial', 'SNMPASS00001')->firstOrFail();
    $deviceB = Device::query()->where('serial', 'SNMPASS00002')->firstOrFail();

    expect($deviceA->site_id)->not->toBe($deviceB->site_id)
        ->and($deviceA->site->client_id)->toBe($clientA->id)
        ->and($deviceB->site->client_id)->toBe($clientB->id);
});

test('sites with the same name are shared across deployments for one client', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create(['current' => true]);
    $deploymentA = Deployment::factory()->for($client)->create();
    $deploymentB = Deployment::factory()->for($client)->create();

    $this->actingAs($user);

    $uploadA = UploadedFile::fake()->createWithContent(
        'devices-a.csv',
        'name,serial,device_function,site'.PHP_EOL.
        'SW-A,SNMPASS00011,ACCESS_SWITCH,HQ'.PHP_EOL
    );
    $uploadB = UploadedFile::fake()->createWithContent(
        'devices-b.csv',
        'name,serial,device_function,site'.PHP_EOL.
        'SW-B,SNMPASS00012,ACCESS_SWITCH,HQ'.PHP_EOL
    );

    $this->post(route('devices.store-many', $deploymentA), ['devices' => $uploadA])
        ->assertRedirect(route('deployments.show', $deploymentA));
    $this->post(route('devices.store-many', $deploymentB), ['devices' => $uploadB])
        ->assertRedirect(route('deployments.show', $deploymentB));

    expect(Site::query()->where('client_id', $client->id)->where('name', 'HQ')->count())->toBe(1);

    $deviceA = Device::query()->where('serial', 'SNMPASS00011')->firstOrFail();
    $deviceB = Device::query()->where('serial', 'SNMPASS00012')->firstOrFail();

    expect($deviceA->site_id)->toBe($deviceB->site_id)
        ->and($deviceA->deployment_id)->toBe($deploymentA->id)
        ->and($deviceB->deployment_id)->toBe($deploymentB->id);
});
