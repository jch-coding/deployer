<?php

use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\LacpProfile;
use App\Models\StpProfile;
use App\Models\SwitchPort;
use App\Models\User;

function makeCurrentClientUser(): User
{
    $user = User::factory()->has(Client::factory())->create();
    $client = $user->clients()->first();
    $client->update(['current' => true]);

    return $user;
}

it('accepts a no-op payload for interface updates', function () {
    $user = makeCurrentClientUser();
    $client = $user->currentClient();
    $deployment = Deployment::factory()->recycle($client)->create();
    $device = Device::factory()->recycle($client)->recycle($deployment)->create();

    $this->actingAs($user)
        ->patch(route('devices.interfaces.update', $device), ['updates' => []])
        ->assertSessionHasNoErrors();
});

it('creates missing profiles and associates them to the interface', function () {
    $user = makeCurrentClientUser();
    $client = $user->currentClient();
    $deployment = Deployment::factory()->recycle($client)->create();
    $device = Device::factory()->recycle($client)->recycle($deployment)->create();
    $interface = DeviceInterface::factory()->recycle($device)->create();

    $payload = [
        'updates' => [[
            'id' => $interface->id,
            'interface_mode' => 'TRUNK',
            'native_vlan' => 22,
            'trunk_vlan_all' => false,
            'trunk_vlan_ranges' => '22-30',
            'lacp_mode' => 'PASSIVE',
            'lacp_rate' => 'FAST',
            'trunk_type' => 'LACP',
            'lacp_port_id' => 11,
            'lacp_port_list' => ['1/1/1', '1/1/2'],
            'admin_edge_port' => true,
            'admin_edge_port_trunk' => true,
            'bpdu_guard' => true,
            'loop_guard' => false,
            'description' => 'Updated uplink',
        ]],
    ];

    $this->actingAs($user)
        ->patch(route('devices.interfaces.update', $device), $payload)
        ->assertSessionHasNoErrors();

    $interface->refresh();

    expect($interface->description)->toBe('Updated uplink')
        ->and($interface->switch_port_id)->not()->toBeNull()
        ->and($interface->lacp_profile_id)->not()->toBeNull()
        ->and($interface->stp_profile_id)->not()->toBeNull();

    $this->assertDatabaseHas('switch_ports', [
        'id' => $interface->switch_port_id,
        'interface_mode' => 'TRUNK',
        'native_vlan' => 22,
        'trunk_vlan_all' => false,
        'trunk_vlan_ranges' => '22-30',
    ]);
    $this->assertDatabaseHas('lacp_profiles', [
        'id' => $interface->lacp_profile_id,
        'mode' => 'PASSIVE',
        'rate' => 'FAST',
        'trunk_type' => 'LACP',
        'port_list' => '1/1/1&1/1/2',
    ]);
    $this->assertDatabaseHas('stp_profiles', [
        'id' => $interface->stp_profile_id,
        'admin_edge_port' => true,
        'admin_edge_port_trunk' => true,
        'bpdu_guard' => true,
        'loop_guard' => false,
    ]);
});

it('validates switchport mode dependent fields', function () {
    $user = makeCurrentClientUser();
    $client = $user->currentClient();
    $deployment = Deployment::factory()->recycle($client)->create();
    $device = Device::factory()->recycle($client)->recycle($deployment)->create();
    $interface = DeviceInterface::factory()->recycle($device)->create();

    $this->actingAs($user)
        ->patch(route('devices.interfaces.update', $device), [
            'updates' => [[
                'id' => $interface->id,
                'interface_mode' => 'ACCESS',
                'access_vlan' => null,
            ]],
        ])
        ->assertSessionHasErrors(['updates.0.access_vlan']);
});

it('rejects updates for interfaces outside the selected device', function () {
    $user = makeCurrentClientUser();
    $client = $user->currentClient();
    $deployment = Deployment::factory()->recycle($client)->create();
    $device = Device::factory()->recycle($client)->recycle($deployment)->create();
    $otherDevice = Device::factory()->recycle($client)->recycle($deployment)->create();
    $otherInterface = DeviceInterface::factory()->recycle($otherDevice)->create();

    $this->actingAs($user)
        ->patch(route('devices.interfaces.update', $device), [
            'updates' => [[
                'id' => $otherInterface->id,
                'description' => 'Nope',
            ]],
        ])
        ->assertSessionHasErrors(['updates']);
});

it('reuses existing profiles instead of creating duplicates', function () {
    $user = makeCurrentClientUser();
    $client = $user->currentClient();
    $deployment = Deployment::factory()->recycle($client)->create();
    $device = Device::factory()->recycle($client)->recycle($deployment)->create();
    $switchPort = SwitchPort::factory()->create([
        'interface_mode' => 'ACCESS',
        'access_vlan' => 10,
        'native_vlan' => null,
        'trunk_vlan_all' => null,
        'trunk_vlan_ranges' => null,
    ]);
    $lacp = LacpProfile::factory()->create([
        'mode' => 'ACTIVE',
        'rate' => 'SLOW',
        'trunk_type' => 'LACP',
        'port_list' => '1/1/1&1/1/2',
    ]);
    $stp = StpProfile::factory()->create([
        'admin_edge_port' => false,
        'admin_edge_port_trunk' => false,
        'bpdu_guard' => false,
        'loop_guard' => false,
    ]);
    $interface = DeviceInterface::factory()->recycle($device)->create();

    $this->actingAs($user)
        ->patch(route('devices.interfaces.update', $device), [
            'updates' => [[
                'id' => $interface->id,
                'interface_mode' => 'ACCESS',
                'access_vlan' => 10,
                'lacp_mode' => 'ACTIVE',
                'lacp_rate' => 'SLOW',
                'trunk_type' => 'LACP',
                'lacp_port_list' => ['1/1/1', '1/1/2'],
                'admin_edge_port' => false,
                'admin_edge_port_trunk' => false,
                'bpdu_guard' => false,
                'loop_guard' => false,
            ]],
        ])
        ->assertSessionHasNoErrors();

    $interface->refresh();
    expect($interface->switch_port_id)->toBe($switchPort->id)
        ->and($interface->lacp_profile_id)->toBe($lacp->id)
        ->and($interface->stp_profile_id)->toBe($stp->id);
});
