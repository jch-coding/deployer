<?php

use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\SwitchPort;
use App\Models\LacpProfile;
use Illuminate\Database\UniqueConstraintViolationException;

it('has a device that it belongs to', function () {
    $device = Device::factory()->create();
    $interface = DeviceInterface::factory()->for($device)->create();

    expect($device->interfaces)->toHaveCount(1)
        ->and($device->interfaces->first())->is($interface);
});

it('has a name that is unique to a device', function () {
   $device = Device::factory()->create();
   DeviceInterface::factory()->create(['device_id' => $device->id, 'name' => '1/1/1']);
   $interface2 = DeviceInterface::factory()->make(['device_id' => $device->id, 'name' => '1/1/1']);
   $this->assertDatabaseHas('device_interfaces', ['name' => '1/1/1', 'device_id' => $device->id]);
   $interface2->save();
})->throws(UniqueConstraintViolationException::class);

it('can be enabled or not enabled', function () {
    $interface = DeviceInterface::factory()->create();
    expect($interface->enable)->toBeTrue();
    $interface->enable = false;
    expect($interface->enable)->toBeFalse();
});

it('can be configured with a description', function () {
    $interface = DeviceInterface::factory()->create(['description' => 'Test interface']);
    expect($interface->description)->toBe('Test interface');
});

it('can have an mtu between 46 and 9198', function ($mtu) {
    $interface = DeviceInterface::factory()->make(['mtu' => $mtu]);
    expect($interface->save())->toThrow(QueryException::class);
})->with([
    45,
    9199
]);

it('can have jumbo frames enabled or not enabled', function () {
    $interface = DeviceInterface::factory()->create();
    expect($interface->jumbo_frames)->toBeFalse();
    $interface->update(['jumbo_frames' => true]);
    expect($interface->jumbo_frames)->toBeTrue();
});

it('can be a routed interface', function () {
    $interface = DeviceInterface::factory()->create();
    expect($interface->routing)->toBeFalse();
    $interface->update(['routing' => true]);
    expect($interface->routing)->toBeTrue();
});

it('can be associated with a switchport profile', function () {
    $interface = DeviceInterface::factory()->create();
    $profile = SwitchPort::factory()->create(['is_profile' => true]);
    $interface->switchport()->associate($profile);
    expect($interface->switchport)->toBe($profile);
});

test('if it is a routing interface it can be associated with a vrf', function () {
    $interface = DeviceInterface::factory()->create(['routing' => true]);
    expect($interface->vrf_forwarding)->toBe('default');
    $interface->update(['vrf_forwarding' => 'vrf1']);
    expect($interface->vrf_forwarding)->toBe('vrf1');
});

it('can be associated to many switchports', function () {
    $interface = DeviceInterface::factory()->create();
    $switchport1 = SwitchPort::factory()->make();
    $switchport2 = SwitchPort::factory()->make();

    $interface->switchports()->saveMany([$switchport1, $switchport2]);
    expect($interface->switchports)->toHaveCount(2)
        ->and($interface->switchports->first()->id)->toEqual($switchport1->id)
        ->and($interface->switchports[1]->id)->toEqual($switchport2->id);
});

it('can be associated to an LACP profile', function () {
    $interface = DeviceInterface::factory()->create();
    $lacpProfile = LacpProfile::factory()->create(['mode' => 'ACTIVE', 'port_id' => 1, 'timeout' => 'SHORT']);
    $interface->lacp_profile()->associate($lacpProfile);
    expect($interface->lacp_profile)->is($lacpProfile);
});
