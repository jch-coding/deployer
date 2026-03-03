<?php

namespace App\Http\Controllers;

use App\DeviceFunction;
use App\Helper\CSVHelper;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\DeviceInterface;
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
                Rule::in(DeviceFunction::cases())
            ]
        ]);

        if($request->user()->currentClient()->id !== $deployment->client_id)
            return redirect()->route('deployments.show', $deployment)->with('error', 'Device cannot be created for this deployment');

        if(Device::where('serial', $data['serial'])->exists()) {
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
        if(!$request->hasFile('devices'))
            return back()->withErrors('No file uploaded');

        $file = $request->file('devices');
        $csvData = CSVHelper::processCSVFile($file->getPathname());
        $devices = CSVHelper::createDeviceArrays($csvData);

        if(count($devices) === 0)
            return back()->withErrors('No devices found in CSV file');

        $headers = $csvData[0];
        if(!in_array('name', $headers) || !in_array('serial', $headers) || !in_array('device_function', $headers))
            return back()->withErrors('CSV file does not contain required headers. Must include name, serial and device_function');

        $withDeployment = array_map(
            fn($arr) => [
                'name' => $arr['name'],
                'serial' => $arr['serial'],
                'device_function' => $arr['device_function'],
                'deployment_id' => $deployment->id
            ],
            $devices
        );

        $savedDevices = $request->user()->currentClient()->devices()->upsert($withDeployment, ['serial'], ['name', 'device_function', 'deployment_id']);

        $errors = [];
        $unsaved_devices = [];
        $unsaved_interfaces = [];

        if($savedDevices !== count($devices)) {
            array_push($errors, [ 'unsaved_devices_error' => 'Only '.($savedDevices) .' of '.count($devices).' devices were saved']);
            $unsaved_devices = array_filter($devices, fn($device) => Device::where('serial', $device['serial'])->doesntExist());
        }

        if(in_array('interface', $headers)) {

            $interfaces = static::getInterfaces($devices);

            $savedInterfaces = static::saveInterfaces($interfaces);

            if($savedInterfaces !== count($interfaces)) {
                array_push($errors, ['unsaved_interfaces_error' => 'Only '.($savedInterfaces) .' of '.count($interfaces).' interfaces were saved']);
                $unsaved_interfaces = array_filter($interfaces,
                    fn($interface) => DeviceInterface::where('interface', $interface['interface'])
                    ->where('device_id', $interface['device_id'])
                    ->doesntExist());
            }
        }

        return redirect()->route('deployments.show', $deployment)
            ->withErrors($errors)
            ->with([
                'unsaved_devices' => $unsaved_devices,
                'unsaved_interfaces' => $unsaved_interfaces,
            ]);
    }

    public static function getInterfaces($devices)
    {
        $unique_devices = array_unique(array_column($devices, 'serial'));
        $normalized_devices = array_map(fn($device) => array_map(fn($v) => $v === '' ? null : $v, $device), $devices);

        $unique_switchports = [];
        foreach($normalized_devices as $device) {
            $current_switchport = [
                'interface_mode' => $device['interface_mode'],
                'access_vlan' => $device['access_vlan'],
                'native_vlan' => $device['native_vlan'],
                'trunk_vlan_all' => $device['trunk_vlan_all'],
            ];
            if(!in_array($current_switchport, $unique_switchports)) {
                array_push($unique_switchports, $current_switchport);
            }
        }

        $unique_stp = [];
        foreach($normalized_devices as $device) {
            $current_stp = [
                'admin_edge_port' => $device['admin_edge_port'],
                'admin_edge_port_trunk' => $device['admin_edge_port_trunk'],
                'bpdu_guard' => $device['bpdu_guard'],
                'loop_guard' => $device['loop_guard'],
            ];
            if(!in_array($current_stp, $unique_stp)) {
                array_push($unique_stp, $current_stp);
            }
        }

        $devices_grouped_config = array_map(
            fn($serial) => array_filter($normalized_devices, fn($device) => $device['serial'] === $serial),
            $unique_devices
        );

        return [
            'unique_switchports' => $unique_switchports,
            'unique_stp' => $unique_stp,
            'devices_grouped_config' => $devices_grouped_config,
        ];
    }

    /**
     * Save the interfaces to the device_interfaces, swich_ports and lacp_profiles tables
     */
    public static function saveInterfaces($interfaces)
    {
        // start with the switchport configurations, if any
        if (count($interfaces['unique_switchports']) > 0) {
            static::saveSwitchPorts($interfaces['unique_switchports']);
        }

        if(count($interfaces['unique_stp']) > 0) {
            static::saveStp($interfaces['unique_stp']);
        }

        $saved_interfaces = 0;
        foreach ($interfaces['devices_grouped_config'] as $device_interfaces) {
            foreach ($device_interfaces as $device_interface) {
                $switchport = SwitchPort::where('interface_mode', $device_interface['interface_mode'])
                    ->where('access_vlan', $device_interface['access_vlan'])
                    ->where('native_vlan', $device_interface['native_vlan'])
                    ->where('trunk_vlan_all', $device_interface['trunk_vlan_all'])
                    ->first();

                $stp_profile = StpProfile::where('admin_edge_port', $device_interface['admin_edge_port'])
                    ->where('admin_edge_port_trunk', $device_interface['admin_edge_port_trunk'])
                    ->where('bpdu_guard', $device_interface['bpdu_guard'])
                    ->where('loop_guard', $device_interface['loop_guard'])
                    ->first();

                $device = Device::where('serial', $device_interface['serial'])->first();
                $device_interface_config = [
                    'device_id' => $device->id,
                    'interface' => $device_interface['interface'],
                    'switch_port_id' => $switchport->id,
                    'stp_profile_id' => $stp_profile->id,
                ];

                DeviceInterface::create($device_interface_config);
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
            if($unique_switchport['interface_mode'] === 'ACCESS') {
                if(SwitchPort::where('access_vlan', (int)$unique_switchport['access_vlan'])->doesntExist()) {
                    SwitchPort::create([
                        ...$unique_switchport,
                        'native_vlan' => null,
                        'trunk_vlan_all' => null,
                    ]);
                }
            } else {
                if((boolean)$unique_switchport['trunk_vlan_all'] === true) {
                    if(SwitchPort::where('native_vlan', (int)$unique_switchport['native_vlan'])->doesntExist()) {
                        SwitchPort::create([
                            ...$unique_switchport,
                            'access_vlan' => null,
                        ]);
                    }
                }
//                else {
//                    if(SwitchPort::where('native_vlan', $unique_switchport['native_vlan'])
//                        ->where('trunk_vlan_ranges', $unique_switchport['trunk_vlan_ranges'])
//                        ->doesntExist()) {
//                        SwitchPort::create($unique_switchport);
//                    }
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
            if (!$deployment) {
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
        //
    }
}
