<?php

use App\Actions\Provisioning\AddDevicesToGreenLakeInventoryAction;
use App\Helper\GreenLakeAPIHelper;
use App\Models\Client;
use App\Models\Device;
use App\Models\User;

it('fails when serial or mac is missing', function () {
    $device = Device::factory()->make([
        'serial' => 'SN1',
        'mac_address' => null,
    ]);
    $helper = mock(GreenLakeAPIHelper::class);

    $result = (new AddDevicesToGreenLakeInventoryAction)->execute($device, $helper);

    expect($result->isFailed())->toBeTrue()
        ->and($result->message)->toContain('serial or mac_address');
});

it('adds a device to greenlake inventory without post-add metadata', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $device = Device::factory()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'serial' => 'SN-GL-1',
        'mac_address' => 'aa:bb:cc:dd:ee:01',
    ]);

    $helper = mock(GreenLakeAPIHelper::class);
    $helper->shouldReceive('addNetworkDevices')
        ->once()
        ->with(
            \Mockery::on(fn (array $devices): bool => $devices[0]['serial'] === 'SN-GL-1'),
            false,
        )
        ->andReturn([
            'success' => true,
            'results' => ['SN-GL-1' => true],
        ]);

    $result = (new AddDevicesToGreenLakeInventoryAction)->execute($device, $helper);

    expect($result->isCompleted())->toBeTrue()
        ->and($result->message)->toContain('added to GreenLake inventory');
});
