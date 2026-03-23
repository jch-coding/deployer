<?php

namespace App\Http\Controllers;

use App\DeviceFunction;
use App\Helper\CSVHelper;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\LacpProfile;
use App\Models\Site;
use App\Models\StpProfile;
use App\Models\SwitchPort;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeviceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Deployment $deployment)
    {
        $data = $request->validate([
            'name' => 'required|string|min:3|max:255',
            'serial' => 'required|string|min:12',
            'device_function' => [
                'required',
                Rule::in(DeviceFunction::cases()),
            ],
        ]);

        if ($request->user()->currentClient()->id !== $deployment->client_id) {
            return redirect()->route('deployments.show', $deployment)->with('error', 'Device cannot be created for this deployment');
        }

        if (Device::where('serial', $data['serial'])->exists()) {
            $device = Device::where('serial', $data['serial'])->first();
            $device->update([
                ...$data,
                'client_id' => $request->user()->currentClient()->id,
                'deployment_id' => $deployment->id,
            ]);

            return redirect()->route('deployments.show', $deployment)->with('success', 'Device updated successfully');
        }
        Device::create([
            ...$data,
            'client_id' => $request->user()->currentClient()->id,
            'deployment_id' => $deployment->id,
        ]);

        return redirect()->route('deployments.show', $deployment)->with('success', 'Device created successfully');
    }

    /**
     *  Parse CSV file uploaded and store information about devices
     */
    public function storeMany(Request $request, Deployment $deployment)
    {
        if (! $request->hasFile('devices')) {
            return back()->withErrors('No file uploaded');
        }

        $file = $request->file('devices');
        $csvData = CSVHelper::processCSVFile($file->getPathname());
        $devices = CSVHelper::createDeviceArrays($csvData);

        if (count($devices) === 0) {
            return back()->withErrors('No devices found in CSV file');
        }

        $headers = $csvData[0];
        if (! in_array('name', $headers) || ! in_array('serial', $headers) || ! in_array('device_function', $headers)) {
            return back()->withErrors('CSV file does not contain required headers. Must include name, serial and device_function');
        }

        $withDeployment = array_map(
            fn ($arr) => [
                'name' => $arr['name'],
                'serial' => $arr['serial'],
                'device_function' => $arr['device_function'],
                'deployment_id' => $deployment->id,
            ],
            $devices
        );

        $savedDevices = $request->user()->currentClient()->devices()->upsert($withDeployment, ['serial'], ['name', 'device_function', 'deployment_id']);

        $errors = [];
        $unsaved_devices = [];
        $unsaved_interfaces = [];
        $unsaved_sites = [];

        if ($savedDevices !== count($devices)) {
            array_push($errors, ['unsaved_devices_error' => 'Only '.($savedDevices).' of '.count($devices).' devices were saved']);
            $unsaved_devices = array_filter($devices, fn ($device) => Device::where('serial', $device['serial'])->doesntExist());
        }

        if (in_array('site', $headers)) {
            $sites_with_devices = static::getSitesWithDeviceSerials($devices);
            $saved_sites = static::saveSitesWithDevices($sites_with_devices);
            if (count($saved_sites) !== count($sites_with_devices)) {
                array_push($errors, ['unsaved_sites_error' => 'Only '.(count($saved_sites)).' of '.count($sites_with_devices).' sites were saved']);
            }
            $unsaved_sites = array_filter($sites_with_devices, fn ($site) => Site::where('name', $site['name'])->doesntExist());
        }

        if (in_array('interface', $headers)) {

            $interfaces = static::getInterfaces($devices);

            $savedInterfaces = static::saveInterfaces($interfaces);

            if ($savedInterfaces !== $interfaces['total_interfaces']) {
                array_push($errors, ['unsaved_interfaces_error' => 'Only '.($savedInterfaces).' of '.count($interfaces).' interfaces were saved']);
                $unsaved_interfaces = array_filter($interfaces,
                    fn ($interface) => DeviceInterface::where('interface', $interface['interface'])
                        ->where('device_id', $interface['device_id'])
                        ->doesntExist());
            }
        }

        return redirect()->route('deployments.show', $deployment)
            ->withErrors($errors)
            ->with([
                'unsaved_devices' => $unsaved_devices,
                'unsaved_interfaces' => $unsaved_interfaces,
                'unsaved_sites' => $unsaved_sites,
            ]);
    }

    public static function expandInterfaceRange(string $range)
    {
        $interface_pairs = array_map(fn ($pair) => explode('-', $pair), explode('&', $range));
        $expanded_ranges = [];
        foreach ($interface_pairs as $pair) {
            if (count($pair) == 2) {
                $interface_parts = explode('/', $pair[0]);
                $prefix = $interface_parts[0] . '/' . $interface_parts[1] . '/';
                $start = (int)$interface_parts[2];
                $end = (int)explode('/', $pair[1])[2];
                $expanded_ranges = array_merge($expanded_ranges, array_map(fn($i) => $prefix . $i, range($start, $end)));
            }
            else {
                $expanded_ranges[] = $pair[0];
            }
        }
        return $expanded_ranges;
    }

    public static function expandInterfaceRangeConfig(array $interface_config)
    {
        $interface_range = static::expandInterfaceRange($interface_config['interface']);
        $interface_range_configs = array_map(fn ($range) => array_merge($interface_config, ['interface' => $range]), $interface_range);
        return $interface_range_configs;
    }

    public static function getInterfaces($devices)
    {
        $unique_devices = array_unique(array_column($devices, 'serial'));
        $devices_with_interface_info = array_filter($devices, fn ($device) => array_key_exists('interface', $device) && $device['interface'] !== '');
        $normalized_devices = array_map(fn ($device) => array_map(fn ($v) => $v === '' ? null : $v, $device), $devices_with_interface_info);

        $unique_switchports = [];
        foreach ($normalized_devices as $device) {
            if (array_key_exists('interface_mode', $device) && $device['interface_mode'] !== null) {
                if (array_key_exists('port_profile', $device) && $device['port_profile'] !== null) {
                    $device['sw_profile'] = $device['port_profile'];
                }
                if ($device['interface_mode'] === 'TRUNK') {
                    $current_switchport = [
                        'interface_mode' => $device['interface_mode'],
                        'access_vlan' => null,
                        'native_vlan' => $device['native_vlan'] ?? 1,
                        'trunk_vlan_all' => $device['trunk_vlan_all'] ?? false,
                        'trunk_vlan_ranges' => $device['trunk_vlan_ranges'] ?? null,
                    ];
                } else {
                    $current_switchport = [
                        'interface_mode' => $device['interface_mode'],
                        'access_vlan' => $device['access_vlan'] ?? 1,
                        'native_vlan' => null,
                        'trunk_vlan_all' => null,
                        'trunk_vlan_ranges' => null,
                    ];
                }
                if (! in_array($current_switchport, $unique_switchports)) {
                    $unique_switchports[] = $current_switchport;
                }
            }
        }

        $unique_stp = [];
        $stp_keys = ['admin_edge_port', 'admin_edge_port_trunk', 'bpdu_guard', 'loop_guard'];
        foreach ($normalized_devices as $device) {
            if (array_any($device, fn ($v, $k) => in_array($k, $stp_keys)) && $device['interface'] !== null) {
                $current_stp = [
                    'admin_edge_port' => $device['admin_edge_port'] ?? false,
                    'admin_edge_port_trunk' => $device['admin_edge_port_trunk'] ?? false,
                    'bpdu_guard' => $device['bpdu_guard'] ?? false,
                    'loop_guard' => $device['loop_guard'] ?? false,
                ];
                if (! in_array($current_stp, $unique_stp)) {
                    $unique_stp[] = $current_stp;
                }
            }
        }

        $unique_lacp = [];
        $lacp_keys = ['lacp_mode', 'trunk_type', 'port_list', 'lacp_rate'];
        foreach ($normalized_devices as $device) {
            if (array_any($device, fn ($v, $k) => in_array($k, $lacp_keys)) && $device['interface'] !== null) {
                $current_lacp = [
                    'mode' => $device['lacp_mode'] ?? 'ACTIVE',
                    'trunk_type' => $device['trunk_type'] ?? 'LACP',
                    'port_list' => $device['port_list'] ?? null,
                    'rate' => $device['lacp_rate'] ?? 'SLOW',
                ];
                if (! in_array($current_lacp, $unique_lacp)) {
                    $unique_lacp[] = $current_lacp;
                }
            }
        }

        $devices_grouped_config_all = array_map(
            fn ($serial) => array_filter($normalized_devices, fn ($device) => $device['serial'] === $serial),
            $unique_devices
        );

        $devices_grouped_config_with_interface_ranges = array_filter($devices_grouped_config_all, fn ($device_group) => count($device_group) > 0);
        $devices_grouped_config = array_map(fn($device_group) =>
                                    array_reduce(
                                       array_map(fn($device) => static::expandInterfaceRangeConfig($device), $device_group), fn($carry, $item) => array_merge($carry, $item), []),
            $devices_grouped_config_with_interface_ranges);

        $total_interfaces = 0;
        array_map(function ($interfaces) use (&$total_interfaces) {
            $total_interfaces += count($interfaces);
        }, $devices_grouped_config);

        return [
            'unique_switchports' => $unique_switchports,
            'unique_stp' => $unique_stp,
            'unique_lacp' => $unique_lacp,
            'devices_grouped_config' => $devices_grouped_config,
            'total_interfaces' => $total_interfaces,
        ];
    }

    /**
     * Save the interfaces to the device_interfaces, swich_ports and lacp_profiles tables
     */
    public static function saveInterfaces($interfaces)
    {
        $has_switchport = false;
        $has_stp = false;
        $has_lacp = false;

        // start with the switchport configurations, if any
        if (count($interfaces['unique_switchports']) > 0) {
            static::saveSwitchPorts($interfaces['unique_switchports']);
            $has_switchport = true;
        }

        if (count($interfaces['unique_stp']) > 0) {
            static::saveStp($interfaces['unique_stp']);
            $has_stp = true;
        }

        if (count($interfaces['unique_lacp']) > 0) {
            static::saveLacp($interfaces['unique_lacp']);
            $has_lacp = true;
        }

        $saved_interfaces = 0;
        foreach ($interfaces['devices_grouped_config'] as $device_interfaces) {
            foreach ($device_interfaces as $device_interface) {
                if ($has_switchport) {
                    $switchport = SwitchPort::where('interface_mode', $device_interface['interface_mode'])
                        ->where('access_vlan', $device_interface['access_vlan'])
                        ->where('native_vlan', $device_interface['native_vlan'])
                        ->where('trunk_vlan_all',(bool) $device_interface['trunk_vlan_all'])
                        ->where('trunk_vlan_ranges', $device_interface['trunk_vlan_ranges'])
                        ->first();
                }

                if ($has_stp) {
                    $null_to_false = fn ($v) => $v === null ? false : $v;
                    $stp_profile = StpProfile::where('admin_edge_port', $null_to_false($device_interface['admin_edge_port']))
                        ->where('admin_edge_port_trunk', $null_to_false($device_interface['admin_edge_port_trunk']))
                        ->where('bpdu_guard', $null_to_false($device_interface['bpdu_guard']))
                        ->where('loop_guard', $null_to_false($device_interface['loop_guard']))
                        ->first();
                }

                if ($has_lacp) {
                    $lacp_profile = LacpProfile::where('mode', $device_interface['lacp_mode'])
                        ->where('trunk_type', $device_interface['trunk_type'])
                        ->where('port_list', $device_interface['port_list'])
                        ->where('rate', $device_interface['lacp_rate'])
                        ->first();
                }

                $device = Device::where('serial', $device_interface['serial'])->first();
                $device_interface_config = [
                    'device_id' => $device->id,
                    'interface' => $device_interface['interface'],
                    'sw_profile' => $device_interface['port_profile'] ?? null,
                    'switch_port_id' => $switchport->id ?? null,
                    'stp_profile_id' => $stp_profile->id ?? null,
                    'lacp_profile_id' => $lacp_profile->id ?? null,
                ];

                DeviceInterface::upsert($device_interface_config, ['interface', 'device_id'], ['sw_profile','switch_port_id','stp_profile_id', 'lacp_profile_id']);
                $saved_interfaces++;
            }
        }

        return $saved_interfaces;
    }

    /**
     * Save switchport configurations in the switch_ports table
     */
    public static function saveSwitchPorts($unique_switchports)
    {
        foreach ($unique_switchports as $unique_switchport) {
            if ($unique_switchport['interface_mode'] === 'ACCESS') {
                if (SwitchPort::where('access_vlan', (int) $unique_switchport['access_vlan'])->doesntExist()) {
                    SwitchPort::create([
                        ...$unique_switchport,
                        'native_vlan' => null,
                        'trunk_vlan_all' => null,
                    ]);
                }
            } else {
                if ((bool) $unique_switchport['trunk_vlan_all'] === true) {
                    if (SwitchPort::where('native_vlan', (int) $unique_switchport['native_vlan'])->doesntExist()) {
                        SwitchPort::create([
                            ...$unique_switchport,
                            'access_vlan' => null,
                        ]);
                    }
                }
                else {
                    if(SwitchPort::where('native_vlan', $unique_switchport['native_vlan'])
                    ->where('trunk_vlan_ranges', $unique_switchport['trunk_vlan_ranges'])
                    ->doesntExist()) {
                        SwitchPort::create($unique_switchport);
                    }
                }
            }
        }
    }

    /**
     * Save STP configurations in the stp_profiles table
     */
    public static function saveStp($stp_profiles)
    {
        foreach ($stp_profiles as $stp_profile) {
            if (StpProfile::where('admin_edge_port', $stp_profile['admin_edge_port'])
                ->where('admin_edge_port_trunk', $stp_profile['admin_edge_port_trunk'])
                ->where('bpdu_guard', $stp_profile['bpdu_guard'])
                ->where('loop_guard', $stp_profile['loop_guard'])
                ->doesntExist()) {
                StpProfile::create($stp_profile);
            }
        }
    }

    public static function saveLacp($lacp_profiles)
    {
        foreach ($lacp_profiles as $lacp_profile) {
            if (LacpProfile::where('mode', $lacp_profile['mode'])
                ->where('trunk_type', $lacp_profile['trunk_type'])
                ->where('port_list', $lacp_profile['port_list'])
                ->where('rate', $lacp_profile['rate'])
                ->doesntExist()) {
                LacpProfile::create($lacp_profile);
            }
        }
    }

    public static function getSitesWithDeviceSerials(array $deviceArray)
    {
        $devices_with_sites = array_filter($deviceArray, fn ($device) => array_key_exists('site', $device) && $device['site'] !== '');
        $sites = array_unique(array_column($devices_with_sites, 'site'));
        $sites_with_devices = array_map(fn ($site) => [
            'name' => $site,
            'devices' => array_map(fn($device) => $device['serial'], array_filter($devices_with_sites, fn ($device) => $device['site'] === $site)),
        ], $sites);
        return $sites_with_devices;
    }

    public static function saveSitesWithDevices(array $sites_with_devices)
    {
        $saved_sites = [];
        foreach ($sites_with_devices as $site_with_devices) {
            $site = Site::firstOrCreate(['name' => $site_with_devices['name']]);
            $saved_sites[] = $site;
            array_map(fn($device) => Device::where('serial', $device)->get()->first()->update(['site_id' => $site->id]), $site_with_devices['devices']);
        }
        return $saved_sites;
    }

    /**
     * Display the specified resource.
     */
    public function show(Device $device)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Device $device)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Device $device)
    {
        $data = [];
        if ($request->has('name')) {
            array_merge($data, ['name' => $request->validate(['name' => 'string|min:3|max:255'])]);
        }
        if ($request->has('serial')) {
            array_merge($data, ['serial' => $request->validate(['serial' => 'string|min:12'])]);
        }
        if ($request->has('device_function')) {
            array_merge($data, ['device_function' => $request->validate(['device_function' => Rule::in(DeviceFunction::cases())])]);
        }
        if ($request->has('deployment_id')) {
            $client = $device->client;
            $deployment = $client->deployments()->find($request->deployment_id);
            if (! $deployment) {
                return back()->withErrors('Deployment does not belong to current client.', 'deployment_id');
            }
            array_merge($data, ['deployment_id' => $deployment->id]);
        }
        $device->update($data);

        return back()->with('success', 'Device updated successfully');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Device $device)
    {
        $device->delete();
        return back()->with('success', 'Device deleted successfully');
    }
}
