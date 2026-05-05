<?php

use App\InterfaceKind;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\LacpProfile;
use App\Models\StpProfile;
use App\Models\SwitchPort;
use Illuminate\Database\UniqueConstraintViolationException;

it('has a device that it belongs to', function () {
    $device = Device::factory()->create();
    $interface = DeviceInterface::factory()->for($device)->create();

    expect($device->interfaces)->toHaveCount(1)
        ->and($device->interfaces->first())->is($interface);
});

it('has a name that is unique to a device', function () {
    $device = Device::factory()->create();
    DeviceInterface::factory()->create(['device_id' => $device->id, 'interface' => '1/1/1']);
    $interface2 = DeviceInterface::factory()->make(['device_id' => $device->id, 'interface' => '1/1/1']);
    $this->assertDatabaseHas('device_interfaces', ['interface' => '1/1/1', 'device_id' => $device->id]);
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

// it('can have an mtu between 46 and 9198', function ($mtu) {
//    $interface = DeviceInterface::factory()->make(['mtu' => $mtu]);
//    expect($interface->save())->toThrow(QueryException::class);
// })->with([
//    45,
//    9199
// ]);

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
    $interface->switch_port()->associate($profile);
    expect($interface->switch_port)->toBe($profile);
});

test('if it is a routing interface it can be associated with a vrf', function () {
    $interface = DeviceInterface::factory()->create(['routing' => true]);
    expect($interface->vrf_forwarding)->toBe('default');
    $interface->update(['vrf_forwarding' => 'vrf1']);
    expect($interface->vrf_forwarding)->toBe('vrf1');
});

it('can be associated to a switchport', function () {
    $interface = DeviceInterface::factory()->create();
    $switchport = SwitchPort::factory()->make();
    $interface->switch_port()->associate($switchport);
    expect($interface->switch_port)->toBe($switchport);
});

it('can be associated to an LACP profile', function () {
    $interface = DeviceInterface::factory()->create();
    $lacpProfile = LacpProfile::factory()->create(['mode' => 'ACTIVE', 'port_id' => 1, 'rate' => 'FAST']);
    $interface->lacp_profile()->associate($lacpProfile);
    expect($interface->lacp_profile)->toBe($lacpProfile);
});

it('can be associated with an stp profile', function () {
    $interface = DeviceInterface::factory()->create();
    $stpProfile = StpProfile::factory()->create(['admin_edge_port' => true, 'bpdu_guard' => true, 'loop_guard' => true]);
    $interface->stp_profile()->associate($stpProfile);
    expect($interface->stp_profile)->toBe($stpProfile);
});

it('can be a vlan that has a static address assigned', function () {
    $interface = DeviceInterface::factory()->create([
        'interface' => 'vlan30',
        'ip_address' => '10.10.30.11/24',
        'interface_kind' => InterfaceKind::VLAN,
    ]);
    $this->assertDatabaseHas('device_interfaces', [
        'interface' => 'vlan30',
        'ip_address' => '10.10.30.11/24',
        'enable' => true,
    ]);
});

it('can be assigned to a portchannel_lag', function () {
    $interface = DeviceInterface::factory()->create(['portchannel_lag' => '10']);
    expect($interface->portchannel_lag)->toBe('10');
    $this->assertDatabaseHas('device_interfaces', ['portchannel_lag' => '10']);
});
