<?php

use App\Http\Controllers\TaskController;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->has(Client::factory())->create();
    $this->client = $this->user->clients->first();
    $this->deployment = Deployment::factory()->for($this->client)->create();
    $this->device = Device::factory()->for($this->deployment)->create();
});

test('it configures lags before ethernet interfaces', function () {
   $deviceInterfaces = $this->device->interfaces()->createMany([
       [
           'interface' => '1/1/1',
           'portchannel_lag' => '10',
       ],
       [
           'interface' => 'lag10',
           'interface_mode' => 'TRUNK',
           'access_vlan' => null,
           'native_vlan' => 10,
           'trunk_vlan_all' => 'true',
           'trunk_vlan_ranges' => null,
       ]
   ]);

   $interface_order = TaskController::orderInterfaces($deviceInterfaces);
   expect($interface_order[0]['interface'])->toEqual('lag10');
   expect($interface_order[1]['interface'])->toEqual('1/1/1');
});
