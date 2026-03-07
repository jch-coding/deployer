<?php

use App\Helper\CSVHelper;
use App\Http\Controllers\DeviceController;
use App\Models\Device;
use App\Models\StpProfile;
use App\Models\SwitchPort;

beforeEach(function () {
    $csv_raw = CSVHelper::processCSVFile('tests/Unit/testcsvs/interface_test.csv');
    $this->processed_data = CSVHelper::createDeviceArrays($csv_raw);
});

it('replaces empty strings with null values', function () {
    $interfaces = DeviceController::getInterfaces($this->processed_data);
    $expected_swp = [
        'interface_mode' => 'ACCESS',
        'access_vlan' => 10,
        'native_vlan' => null,
        'trunk_vlan_all' => null,
        'trunk_vlan_ranges' => null,
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
        'access_vlan' => '10',
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
               'access_vlan' => '10',
               'native_vlan' => null,
               'trunk_vlan_all' => null,
               'trunk_vlan_ranges' => null,
           ],
           [
               'interface_mode' => 'TRUNK',
               'access_vlan' => null,
               'native_vlan' => '10',
               'trunk_vlan_all' => 'true',
               'trunk_vlan_ranges' => null,
           ],
           [
               'interface_mode' => 'ACCESS',
               'access_vlan' => '8',
               'native_vlan' => null,
               'trunk_vlan_all' => null,
               'trunk_vlan_ranges' => null,
           ],
           [
               'interface_mode' => 'TRUNK',
               'access_vlan' => null,
               'native_vlan' => '8',
               'trunk_vlan_all' => 'true',
               'trunk_vlan_ranges' => null,
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
                    'access_vlan' => '10',
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
                    'native_vlan' => '10',
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
                   'access_vlan' => '8',
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
                   'native_vlan' => '8',
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
       'trunk_vlan_ranges' => null,
   ]);
    $this->assertDatabaseHas('switch_ports', [
        'access_vlan' => 8,
        'interface_mode' => 'ACCESS',
        'native_vlan' => null,
        'trunk_vlan_all' => null,
        'trunk_vlan_ranges' => null,
    ]);
    $this->assertDatabaseHas('switch_ports', [
        'access_vlan' => null,
        'interface_mode' => 'TRUNK',
        'native_vlan' => 10,
        'trunk_vlan_all' => "true",
        'trunk_vlan_ranges' => null,
    ]);
    $this->assertDatabaseHas('switch_ports', [
        'access_vlan' => null,
        'interface_mode' => 'TRUNK',
        'native_vlan' => 8,
        'trunk_vlan_all' => "true",
        'trunk_vlan_ranges' => null,
        ]);
});

test('it saves unique stp profiles to the database', function () {
  $stp_profiles = DeviceController::getInterfaces($this->processed_data)['unique_stp'];

  StpProfile::factory()->create(['admin_edge_port' => 'true', 'bpdu_guard' => 'true', 'loop_guard' => 'true', 'admin_edge_port_trunk' => false]);

  DeviceController::saveStp($stp_profiles);

  $this->assertDatabaseCount('stp_profiles', 2);

  $this->assertDatabaseHas('stp_profiles', [
      'admin_edge_port' => 'true',
      'admin_edge_port_trunk' => 0,
      'bpdu_guard' => 'true',
      'loop_guard' => 'true',
  ]);
  $this->assertDatabaseHas('stp_profiles', [
      'admin_edge_port' => 0,
      'admin_edge_port_trunk' => 'true',
      'bpdu_guard' => 0,
      'loop_guard' => 0,
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
        ->where('admin_edge_port_trunk', 0)
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
    $stp2 = StpProfile::where('admin_edge_port', 0)
        ->where('bpdu_guard', 0)
        ->where('loop_guard', 0)
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

test('multiple types of devices with different device functions can be uploaded and saved to the database', function () {
    $raw_csv = CSVHelper::processCSVFile('tests/Unit/testcsvs/devices_different_types_test.csv');
    $processed_data = CSVHelper::createDeviceArrays($raw_csv);
    array_map(fn($name, $serial, $device_function) => Device::factory()->create([
        'name' => $name,
        'serial' => $serial,
        'device_function' => $device_function,
    ]), array_column($processed_data, 'name'),
        array_column($processed_data, 'serial'),
        array_column($processed_data, 'device_function')
    );
    $interfaces = DeviceController::getInterfaces($processed_data);
    $savedInterfaces = DeviceController::saveInterfaces($interfaces);
    $this->assertEquals(1, $savedInterfaces);
    $this->assertDatabaseCount('device_interfaces', 1);
    $this->assertDatabaseCount('devices', 2);
    $this->assertDatabaseCount('switch_ports', 1);
    $this->assertDatabaseCount('stp_profiles', 1);
    $this->assertDatabaseHas('devices', [
        'name' => 'CO-IDF1-SW1',
        'serial' => 'SN0000000001',
        'device_function' => 'ACCESS_SWITCH',
    ]);
    $this->assertDatabaseHas('devices', [
        'name' => 'CO-AP-IDF1-001',
        'serial' => 'SN0000000002',
        'device_function' => 'ACCESS_POINT',
    ]);
});

test('getInterfaces correctly parses trunk-vlan-ranges as arrays of strings', function () {
   $raw_csv = CSVHelper::processCSVFile('tests/Unit/testcsvs/diff_trunk_options.csv');
   $processed_data = CSVHelper::createDeviceArrays($raw_csv);
   $interfaces = DeviceController::getInterfaces($processed_data);
   $expected_swp = [
       'interface_mode' => 'TRUNK',
       'access_vlan' => null,
       'native_vlan' => '10',
       'trunk_vlan_all' => false,
       'trunk_vlan_ranges' => ['8', '10-20'],
   ];
   expect($interfaces['unique_switchports'][0])->toEqual($expected_swp);
});

test('configure ethernet trunk interfaces configures trunk-vlan-all and trunk-vlan-ranges as mutually exclusive configs', function () {
    $raw_csv = CSVHelper::processCSVFile('tests/Unit/testcsvs/diff_trunk_options.csv');
    $processed_data = CSVHelper::createDeviceArrays($raw_csv);
    $interfaces = DeviceController::getInterfaces($processed_data);
    $expected_swp = [
        'interface_mode' => 'TRUNK',
        'access_vlan' => null,
        'native_vlan' => '10',
        'trunk_vlan_all' => false,
        'trunk_vlan_ranges' => ['8', '10-20'],
    ];
    $expected_swp2 = [
        'interface_mode' => 'TRUNK',
        'access_vlan' => null,
        'native_vlan' => '10',
        'trunk_vlan_all' => 'true',
        'trunk_vlan_ranges' => null,
    ];
    expect($interfaces['unique_switchports'][0])->toEqual($expected_swp);
    expect($interfaces['unique_switchports'][1])->toEqual($expected_swp2);
});
