<?php

namespace App\Http\Controllers;

use App\DeviceFunction;
use App\Helper\CSVHelper;
use App\Models\Deployment;
use App\Models\Device;
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
        $path = 'storage/app/private/' . $file->store('devices');
        $csvData = CSVHelper::processCSVFile($path);
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

        $savedDevices = $request->user()->currentClient()->devices()->createMany($withDeployment);

        if(count($savedDevices) !== count($devices))
            return back()->withErrors('Some devices were not saved');

        return redirect()->route('deployments.show', $deployment)->with('success', 'Devices created successfully');
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
