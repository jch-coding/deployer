<?php

use App\Models\DeviceInterface;
use App\Models\SwitchPort;

it('can be associated to many interfaces', function () {
    $switchport = SwitchPort::factory()->create();
    $interface1 = DeviceInterface::factory()->create(['switchport_id' => $switchport->id]);
    $interface2 = DeviceInterface::factory()->create(['switchport_id' => $switchport->id]);
    expect($switchport->interfaces)->toHaveCount(2)
        ->and($switchport->interfaces->contains($interface1))->toBeTrue()
        ->and($switchport->interfaces->contains($interface2))->toBeTrue();
});

it('can be a switchport profile but defaults to an ethernet port', function () {
    $switchport = SwitchPort::factory()->create();
    expect($switchport->is_profile)->toBeFalse();
    $switchport->update(['is_profile' => true]);
    expect($switchport->is_profile)->toBeTrue();
});

it('can be an access port', function () {
    $switchport = SwitchPort::factory()->create(['access_vlan' => 10, 'interface_mode' => 'ACCESS']);
    expect($switchport->native_vlan)->toBeNull();
    expect($switchport->trunk_vlan_all)->toBeNull();
    expect($switchport->trunk_vlan_ranges)->toBeNull();
});

it('can be a trunk port', function () {
    $switchport = SwitchPort::factory()->create(['interface_mode' => 'TRUNK', 'native_vlan' => 10, 'access_vlan' => null, 'trunk_vlan_ranges' => '10-20']);
    expect($switchport->native_vlan)->toBe(10);
    expect($switchport->access_vlan)->toBeNull();
    expect($switchport->trunk_vlan_all)->toBeFalse();
    expect($switchport->trunk_vlan_ranges)->toBe(['10-20']);
});

it('retrieves the trunk-vlan-ranges attribute as an array', function () {
    $switchport = SwitchPort::factory()->create(['trunk_vlan_ranges' => '10&20-25&30']);
    expect($switchport->trunk_vlan_ranges)->toBe(['10','20-25','30']);
});
