<?php

use App\Helper\CSVHelper;
use App\Http\Controllers\DeviceController;
use App\Models\Device;
use App\Models\StpProfile;
use App\Models\SwitchPort;

beforeEach(function () {
    $csv_raw = CSVHelper::processCSVFile('tests/Unit/interface_test.csv');
    $this->processed_data = CSVHelper::createDeviceArrays($csv_raw);
});

it('replaces empty strings with null values', function () {
    $interfaces = DeviceController::getInterfaces($this->processed_data);
    $expected_swp = [
        'interface_mode' => 'ACCESS',
        'access_vlan' => 10,
        'native_vlan' => null,
        'trunk_vlan_all' => null,
    ];
    $expected_stp = [
        'admin_edge_port' => 'true',
        'admin_edge_port_trunk' => null,
        'bpdu_guard' => 'true',
        'loop_guard' => 'true',
    ];
    $expected_interface = [
        'name' => 'CO-IDF1-SW1',
        'serial' => 'SN0000000001',
        'device_function' => 'ACCESS_SWITCH',
        'interface' => '1/1/1',
        'access_vlan' => 10,
        'interface_mode' => 'ACCESS',
        'native_vlan' => null,
        'trunk_vlan_all' => null,
        'admin_edge_port' => 'true',
        'admin_edge_port_trunk' => null,
        'bpdu_guard' => 'true',
        'loop_guard' => 'true',
    ];

    expect($interfaces['devices_grouped_config'][0][0])->toEqual($expected_interface);
    expect($interfaces['unique_switchports'][0])->toEqual($expected_swp);
    expect($interfaces['unique_stp'][0])->toEqual($expected_stp);
});

it('gets a list of unique profiles and interfaces separated by devices when getInterfaces is called', function () {
   $expected_result = [
       'unique_switchports' => [
           [
               'interface_mode' => 'ACCESS',
               'access_vlan' => 10,
               'native_vlan' => null,
               'trunk_vlan_all' => null,
           ],
           [
               'interface_mode' => 'TRUNK',
               'access_vlan' => null,
               'native_vlan' => 10,
               'trunk_vlan_all' => 'true',
           ],
           [
               'interface_mode' => 'ACCESS',
               'access_vlan' => 8,
               'native_vlan' => null,
               'trunk_vlan_all' => null,
           ],
           [
               'interface_mode' => 'TRUNK',
               'access_vlan' => null,
               'native_vlan' => 8,
               'trunk_vlan_all' => 'true',
           ],
       ],
       'unique_stp' => [
           [
               'admin_edge_port' => 'true',
               'admin_edge_port_trunk' => null,
               'bpdu_guard' => 'true',
               'loop_guard' => 'true',
           ],
           [
               'admin_edge_port' => null,
               'admin_edge_port_trunk' => 'true',
               'bpdu_guard' => null,
               'loop_guard' => null,
           ]
       ],
       'devices_grouped_config' => [
            [
                [
                    'name' => 'CO-IDF1-SW1',
                    'serial' => 'SN0000000001',
                    'device_function' => 'ACCESS_SWITCH',
                    'interface' => '1/1/1',
                    'access_vlan' => 10,
                    'interface_mode' => 'ACCESS',
                    'native_vlan' => null,
                    'trunk_vlan_all' => null,
                    'admin_edge_port' => 'true',
                    'admin_edge_port_trunk' => null,
                    'bpdu_guard' => 'true',
                    'loop_guard' => 'true',
                ],
                [
                    'name' => 'CO-IDF1-SW1',
                    'serial' => 'SN0000000001',
                    'device_function' => 'ACCESS_SWITCH',
                    'interface' => '1/1/2',
                    'access_vlan' => null,
                    'interface_mode' => 'TRUNK',
                    'native_vlan' => 10,
                    'trunk_vlan_all' => 'true',
                    'admin_edge_port' => null,
                    'admin_edge_port_trunk' => 'true',
                    'bpdu_guard' => null,
                    'loop_guard' => null,
                ]
            ],
           [
               [
                   'name' => 'CO-IDF2-SW1',
                   'serial' => 'SN0000000002',
                   'device_function' => 'ACCESS_SWITCH',
                   'interface' => '1/1/1',
                   'access_vlan' => 8,
                   'interface_mode' => 'ACCESS',
                   'native_vlan' => null,
                   'trunk_vlan_all' => null,
                   'admin_edge_port' => 'true',
                   'admin_edge_port_trunk' => null,
                   'bpdu_guard' => 'true',
                   'loop_guard' => 'true',
               ],
               [
                   'name' => 'CO-IDF2-SW1',
                   'serial' => 'SN0000000002',
                   'device_function' => 'ACCESS_SWITCH',
                   'interface' => '1/1/2',
                   'access_vlan' => null,
                   'interface_mode' => 'TRUNK',
                   'native_vlan' => 8,
                   'trunk_vlan_all' => 'true',
                   'admin_edge_port' => null,
                   'admin_edge_port_trunk' => 'true',
                   'bpdu_guard' => null,
                   'loop_guard' => null,
               ]
           ],
       ]
   ];

   $interfaces = DeviceController::getInterfaces($this->processed_data);
   expect($interfaces)->toEqual($expected_result);
});

test("save switchports only saves unique switchports to the database", function () {
   $interfaces = DeviceController::getInterfaces($this->processed_data);
   $switchports = $interfaces['unique_switchports'];
   DeviceController::saveSwitchports($switchports);

   $this->assertDatabaseCount('switch_ports', 4);

   $this->assertDatabaseHas('switch_ports', [
       'access_vlan' => 10,
       'interface_mode' => 'ACCESS',
       'native_vlan' => null,
       'trunk_vlan_all' => null,
   ]);
    $this->assertDatabaseHas('switch_ports', [
        'access_vlan' => 8,
        'interface_mode' => 'ACCESS',
        'native_vlan' => null,
        'trunk_vlan_all' => null,
    ]);
    $this->assertDatabaseHas('switch_ports', [
        'access_vlan' => null,
        'interface_mode' => 'TRUNK',
        'native_vlan' => 10,
        'trunk_vlan_all' => "true",
    ]);
    $this->assertDatabaseHas('switch_ports', [
        'access_vlan' => null,
        'interface_mode' => 'TRUNK',
        'native_vlan' => 8,
        'trunk_vlan_all' => "true",
        ]);
});

test('it saves unique stp profiles to the database', function () {
  $stp_profiles = DeviceController::getInterfaces($this->processed_data)['unique_stp'];

  StpProfile::factory()->create(['admin_edge_port' => 'true', 'bpdu_guard' => 'true', 'loop_guard' => 'true', 'admin_edge_port_trunk' => null]);

  DeviceController::saveStp($stp_profiles);

  $this->assertDatabaseCount('stp_profiles', 2);

  $this->assertDatabaseHas('stp_profiles', [
      'admin_edge_port' => 'true',
      'admin_edge_port_trunk' => null,
      'bpdu_guard' => 'true',
      'loop_guard' => 'true',
  ]);
  $this->assertDatabaseHas('stp_profiles', [
      'admin_edge_port' => null,
      'admin_edge_port_trunk' => 'true',
      'bpdu_guard' => null,
      'loop_guard' => null,
  ]);
});

test('interfaces are saved to the database with the corresponding switchport profile, device and stp profile', function () {
    $interfaces = DeviceController::getInterfaces($this->processed_data);
    $savedDevice = Device::factory()->createMany([
        ['name' => 'CO-IDF1-SW1', 'serial' => 'SN0000000001', 'device_function' => 'ACCESS_SWITCH'],
        ['name' => 'CO-IDF2-SW1', 'serial' => 'SN0000000002', 'device_function' => 'ACCESS_SWITCH'],
    ]);

    DeviceController::saveInterfaces($interfaces);

    $this->assertDatabaseCount('device_interfaces', 4);

    $switchport1 = Switchport::where('access_vlan',10)->first();
    $stp1 = StpProfile::where('admin_edge_port', 'true')
        ->where('bpdu_guard', 'true')
        ->where('loop_guard', 'true')
        ->where('admin_edge_port_trunk', null)
        ->first();

    $this->assertDatabaseHas('device_interfaces', [
        'interface' => '1/1/1',
        'device_id' => 1,
        'switch_port_id' => $switchport1->id,
        'stp_profile_id' => $stp1->id,
    ]);

    $switchport2 = Switchport::where('access_vlan',8)->first();

    $this->assertDatabaseHas('device_interfaces', [
        'interface' => '1/1/1',
        'device_id' => 2,
        'switch_port_id' => $switchport2->id,
        'stp_profile_id' => $stp1->id,
    ]);

    $switchport3 = Switchport::where('native_vlan',10)->first();
    $stp2 = StpProfile::where('admin_edge_port', null)
        ->where('bpdu_guard', null)
        ->where('loop_guard', null)
        ->where('admin_edge_port_trunk', 'true')
        ->first();

    $this->assertDatabaseHas('device_interfaces', [
        'interface' => '1/1/2',
        'device_id' => 1,
        'switch_port_id' => $switchport3->id,
        'stp_profile_id' => $stp2->id,
    ]);

    $switchport4 = Switchport::where('native_vlan',8)->first();
    $this->assertDatabaseHas('device_interfaces', [
        'interface' => '1/1/2',
        'device_id' => 2,
        'switch_port_id' => $switchport4->id,
        'stp_profile_id' => $stp2->id,
    ]);
});
