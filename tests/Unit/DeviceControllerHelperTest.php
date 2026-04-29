<?php

use App\Helper\CSVHelper;
use App\Http\Controllers\DeviceController;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\LacpProfile;
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
       'unique_lacp' => [],
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
       ],
       'total_interfaces' => 4,
   ];

   $interfaces = DeviceController::getInterfaces($this->processed_data);
   expect($interfaces)->toEqual($expected_result);
});

test('expand range of interfaces to array of interfaces expands a range correctly', function () {
   $interface_range = '1/1/1-1/1/2&2/1/1-2/1/2';
   $expected_result = ['1/1/1', '1/1/2', '2/1/1', '2/1/2'];
   $actual_result = DeviceController::expandInterfaceRange($interface_range);
   expect($actual_result)->toEqual($expected_result);
});

test('get interfaces returns an array of interfaces when an interface range is provided', function () {
    $interface_range =
            [
                'name' => 'CO-IDF1-SW1',
                'serial' => 'SN0000000001',
                'device_function' => 'ACCESS_SWITCH',
                'interface' => '1/1/1-1/1/2&2/1/1-2/1/2',
                'access_vlan' => '10',
                'interface_mode' => 'ACCESS',
                'native_vlan' => null,
                'trunk_vlan_all' => null,
                'admin_edge_port' => 'true',
                'admin_edge_port_trunk' => null,
                'bpdu_guard' => 'true',
                'loop_guard' => 'true',
            ];
    $expected = [
        [...$interface_range, 'interface' => '1/1/1'],
        [...$interface_range, 'interface' => '1/1/2'],
        [...$interface_range, 'interface' => '2/1/1'],
        [...$interface_range, 'interface' => '2/1/2'],
    ];
    $actual = DeviceController::expandInterfaceRangeConfig($interface_range);
    $this->assertEquals($expected, $actual);
});

test('get interfaces deals with interface ranges correctly', function () {
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
        'unique_lacp' => [],
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
                    'interface' => '1/1/3',
                    'access_vlan' => null,
                    'interface_mode' => 'TRUNK',
                    'native_vlan' => '10',
                    'trunk_vlan_all' => 'true',
                    'admin_edge_port' => null,
                    'admin_edge_port_trunk' => 'true',
                    'bpdu_guard' => null,
                    'loop_guard' => null,
                ],
                [
                'name' => 'CO-IDF1-SW1',
                'serial' => 'SN0000000001',
                'device_function' => 'ACCESS_SWITCH',
                'interface' => '1/1/4',
                'access_vlan' => null,
                'interface_mode' => 'TRUNK',
                'native_vlan' => '10',
                'trunk_vlan_all' => 'true',
                'admin_edge_port' => null,
                'admin_edge_port_trunk' => 'true',
                'bpdu_guard' => null,
                'loop_guard' => null,
                    ],
            ],
        ],
    'total_interfaces' => 4,
    ];
    $csv_raw = CSVHelper::processCSVFile('tests/Unit/testcsvs/device_interface_range.csv');
    $processed_data = CSVHelper::createDeviceArrays($csv_raw);
    $interfaces = DeviceController::getInterfaces($processed_data);
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

    $deviceOneId = Device::query()->where('serial', 'SN0000000001')->value('id');
    $deviceTwoId = Device::query()->where('serial', 'SN0000000002')->value('id');

    $this->assertDatabaseHas('device_interfaces', [
        'interface' => '1/1/1',
        'device_id' => $deviceOneId,
        'switch_port_id' => $switchport1->id,
        'stp_profile_id' => $stp1->id,
    ]);

    $switchport2 = Switchport::where('access_vlan',8)->first();

    $this->assertDatabaseHas('device_interfaces', [
        'interface' => '1/1/1',
        'device_id' => $deviceTwoId,
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
        'device_id' => $deviceOneId,
        'switch_port_id' => $switchport3->id,
        'stp_profile_id' => $stp2->id,
    ]);

    $switchport4 = Switchport::where('native_vlan',8)->first();
    $this->assertDatabaseHas('device_interfaces', [
        'interface' => '1/1/2',
        'device_id' => $deviceTwoId,
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
        'device_function' => 'CAMPUS_AP',
    ]);
});

test('getInterfaces correctly parses trunk-vlan-ranges as arrays of strings', function () {
   $raw_csv = CSVHelper::processCSVFile('tests/Unit/testcsvs/diff_trunk_options.csv');
   $processed_data = CSVHelper::createDeviceArrays($raw_csv);
   $interfaces = DeviceController::getInterfaces($processed_data);
   $expected_swp = [
       'interface_mode' => 'TRUNK',
       'access_vlan' => null,
       'native_vlan' => 10,
       'trunk_vlan_all' => false,
       'trunk_vlan_ranges' => '8&10-20',
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
        'native_vlan' => 10,
        'trunk_vlan_all' => false,
        'trunk_vlan_ranges' => '8&10-20',
    ];
    $expected_swp2 = [
        'interface_mode' => 'TRUNK',
        'access_vlan' => null,
        'native_vlan' => 10,
        'trunk_vlan_all' => true,
        'trunk_vlan_ranges' => null,
    ];
    expect($interfaces['unique_switchports'][0])->toEqual($expected_swp);
    expect($interfaces['unique_switchports'][1])->toEqual($expected_swp2);
});

test ('get sites returns an array of sites and their associated devices by serial number', function () {
    $csv_raw = CSVHelper::processCSVFile('tests/Unit/testcsvs/sites.csv');
    $processed_data = CSVHelper::createDeviceArrays($csv_raw);
    $sites = DeviceController::getSitesWithDeviceSerials($processed_data);
    $expected = [
        [
            'name' => 'Site A',
            'devices' => [
                'SN0000000001',
                'SN0000000002',
            ]
        ],
        [
            'name' => 'Site B',
            'devices' => [
                'SN0000000003',
                'SN0000000004',
            ]
        ],
    ];
    $sites_a_devices = $sites[0]['devices'];
    $sites_b_devices = $sites[2]['devices'];
    expect(in_array('SN0000000001', $sites_a_devices))->toBeTrue();
    expect(in_array('SN0000000002', $sites_a_devices))->toBeTrue();
    expect(in_array('SN0000000003', $sites_b_devices))->toBeTrue();
    expect(in_array('SN0000000004', $sites_b_devices))->toBeTrue();
});

test('save sites saves sites with their devices associated', function () {
   $devices_site_a = Device::factory()->count(2)->create();
   $devices_site_b = Device::factory()->count(2)->create();
   $sites = [
       [
           'name' => 'Site A',
           'devices' => $devices_site_a->pluck('serial')->toArray(),
       ],
       [
           'name' => 'Site B',
           'devices' => $devices_site_b->pluck('serial')->toArray(),
       ]
   ];
   $saved_sites = DeviceController::saveSitesWithDevices($sites);
   $collected_saved_sites = collect($saved_sites);
   $this->assertDatabaseCount('sites', 2);
   $this->assertDatabaseHas('sites', [
       'name' => 'Site A',
   ]);
    $this->assertDatabaseHas('sites', [
        'name' => 'Site B',
    ]);

   $site_a = $collected_saved_sites->first();
    $site_b = $collected_saved_sites->last();
    expect($site_a->devices)->toHaveCount(2);
    expect($site_b->devices)->toHaveCount(2);
});

test('port profiles are saved correctly when the port_profile column is present in the csv file', function () {
    $csv_raw = CSVHelper::processCSVFile('tests/Unit/testcsvs/port_profiles.csv');
    $processed_data = CSVHelper::createDeviceArrays($csv_raw);
    $device_info = $processed_data[0];
    $deployment = Deployment::factory()->create();
    Device::factory()->for($deployment)->create([
        'name' => $device_info['name'],
        'serial' => $device_info['serial'],
        'device_function' => $device_info['device_function'],
    ]);
    $actual = DeviceController::getInterfaces($processed_data);
    $expected = [
        'unique_switchports' => [
            [
                'interface_mode' => 'ACCESS',
                'access_vlan' => '10',
                'native_vlan' => null,
                'trunk_vlan_all' => null,
                'trunk_vlan_ranges' => null,
            ],
        ],
        'unique_stp' => [
            [
            'admin_edge_port' => 'true',
            'admin_edge_port_trunk' => false,
            'bpdu_guard' => 'true',
            'loop_guard' => 'true',
                ]
        ],
        'unique_lacp' => [],
        'devices_grouped_config' => [
            [
                [
            'name' => 'CO-IDF1-SW1',
            'serial' => 'SN0000000001',
            'device_function' => 'ACCESS_SWITCH',
            'interface' => '1/1/1',
            'port_profile' => 'portProfile1',
            'interface_mode' => 'ACCESS',
            'access_vlan' => '10',
            'native_vlan' => null,
            'trunk_vlan_all' => null,
            'admin_edge_port' => 'true',
            'admin_edge_port_trunk' => null,
            'bpdu_guard' => 'true',
            'loop_guard' => 'true',
                    ]
                ]
        ],
        'total_interfaces' => 1,
    ];
    expect($actual)->toEqual($expected);

    $savedInterfaces = DeviceController::saveInterfaces($actual);
    $this->assertEquals(1, $savedInterfaces);
    $this->assertDatabaseCount('device_interfaces', 1);
    $this->assertDatabaseHas('device_interfaces', [
        'interface' => '1/1/1',
        'sw_profile' => 'portProfile1',
    ]);
});

test("it saves portchannel interfaces from a csv", function () {
    $csv_raw = CSVHelper::processCSVFile('tests/Unit/testcsvs/portchannels.csv');
    $processed_data = CSVHelper::createDeviceArrays($csv_raw);
    $device_info = $processed_data[0];
    $deployment = Deployment::factory()->create();
    Device::factory()->for($deployment)->create([
        'name' => $device_info['name'],
        'serial' => $device_info['serial'],
        'device_function' => $device_info['device_function'],
    ]);
    $actual = DeviceController::getInterfaces($processed_data);
    $savedInterfaces = DeviceController::saveInterfaces($actual);
    $this->assertEquals(1, $savedInterfaces);
    $this->assertDatabaseCount('device_interfaces', 1);
    $this->assertDatabaseHas('device_interfaces', [
        'interface' => '5',
    ]);
    $this->assertDatabaseHas('lacp_profiles', [
        'port_list' => '1/1/14-1/1/15'
    ]);
    $interface = DeviceInterface::first();
    $this->assertEquals($interface->lacp_profile->port_list, ['1/1/14','1/1/15']);
    $this->assertEquals($interface->switch_port->interface_mode, 'TRUNK');
    $this->assertEquals($interface->switch_port->native_vlan, 10);
    $this->assertEquals($interface->switch_port->trunk_vlan_all, true);
});

test('saveInterfaces updates existing interface optional fields for the same device and interface', function () {
    $deployment = Deployment::factory()->create();
    $device = Device::factory()->for($deployment)->create([
        'serial' => 'SN-UPDATE-0001',
        'name' => 'SW-UPDATE-1',
        'device_function' => 'ACCESS_SWITCH',
    ]);

    $initial = [
        'unique_switchports' => [],
        'unique_stp' => [],
        'unique_lacp' => [],
        'devices_grouped_config' => [[
            [
                'serial' => $device->serial,
                'interface' => '1/1/1',
                'description' => 'old description',
                'ip_address' => '10.0.0.1/24',
                'port_profile' => 'old-profile',
            ],
        ]],
        'total_interfaces' => 1,
    ];

    DeviceController::saveInterfaces($initial);

    $updated = [
        'unique_switchports' => [],
        'unique_stp' => [],
        'unique_lacp' => [],
        'devices_grouped_config' => [[
            [
                'serial' => $device->serial,
                'interface' => '1/1/1',
                'description' => 'new description',
                'ip_address' => '10.0.0.2/24',
                'port_profile' => 'new-profile',
            ],
        ]],
        'total_interfaces' => 1,
    ];

    DeviceController::saveInterfaces($updated);

    $this->assertDatabaseCount('device_interfaces', 1);
    $this->assertDatabaseHas('device_interfaces', [
        'device_id' => $device->id,
        'interface' => '1/1/1',
        'description' => 'new description',
        'ip_address' => '10.0.0.2/24',
        'sw_profile' => 'new-profile',
    ]);
});

test('saveInterfaces links switchport, stp, and lacp profiles from optional columns', function () {
    $deployment = Deployment::factory()->create();
    $device = Device::factory()->for($deployment)->create([
        'serial' => 'SN-LACP-0001',
        'name' => 'SW-LACP-1',
        'device_function' => 'ACCESS_SWITCH',
    ]);

    $interfaces = [
        'unique_switchports' => [[
            'interface_mode' => 'TRUNK',
            'access_vlan' => null,
            'native_vlan' => 20,
            'trunk_vlan_all' => false,
            'trunk_vlan_ranges' => '20-30',
        ]],
        'unique_stp' => [[
            'admin_edge_port' => false,
            'admin_edge_port_trunk' => true,
            'bpdu_guard' => false,
            'loop_guard' => true,
        ]],
        'unique_lacp' => [[
            'mode' => 'ACTIVE',
            'trunk_type' => 'LACP',
            'port_list' => '1/1/1-1/1/2',
            'rate' => 'FAST',
        ]],
        'devices_grouped_config' => [[
            [
                'serial' => $device->serial,
                'interface' => '10',
                'interface_mode' => 'TRUNK',
                'native_vlan' => 20,
                'trunk_vlan_all' => false,
                'trunk_vlan_ranges' => '20-30',
                'admin_edge_port' => false,
                'admin_edge_port_trunk' => true,
                'bpdu_guard' => false,
                'loop_guard' => true,
                'lacp_mode' => 'ACTIVE',
                'trunk_type' => 'LACP',
                'port_list' => '1/1/1-1/1/2',
                'lacp_rate' => 'FAST',
            ],
        ]],
        'total_interfaces' => 1,
    ];

    DeviceController::saveInterfaces($interfaces);

    $switchPort = SwitchPort::query()->firstOrFail();
    $stp = StpProfile::query()->firstOrFail();
    $lacp = LacpProfile::query()->firstOrFail();

    $this->assertDatabaseHas('device_interfaces', [
        'device_id' => $device->id,
        'interface' => '10',
        'switch_port_id' => $switchPort->id,
        'stp_profile_id' => $stp->id,
        'lacp_profile_id' => $lacp->id,
    ]);
});

test('saveSitesWithDevices updates device site_id based on optional site column mapping', function () {
    $first = Device::factory()->create(['serial' => 'SN-SITE-0001']);
    $second = Device::factory()->create(['serial' => 'SN-SITE-0002']);

    DeviceController::saveSitesWithDevices([
        [
            'name' => 'Optional Site Alpha',
            'devices' => [$first->serial, $second->serial],
        ],
    ]);

    $this->assertDatabaseHas('sites', ['name' => 'Optional Site Alpha']);
    $siteId = \App\Models\Site::query()->where('name', 'Optional Site Alpha')->value('id');

    $this->assertDatabaseHas('devices', ['id' => $first->id, 'site_id' => $siteId]);
    $this->assertDatabaseHas('devices', ['id' => $second->id, 'site_id' => $siteId]);
});
