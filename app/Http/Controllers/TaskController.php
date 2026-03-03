<?php

namespace App\Http\Controllers;

use App\Events\DeviceConfigFailedEvent;
use App\Events\DeviceGetScopeIdEvent;
use App\Events\DeviceSystemInfoUpdateEvent;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\Task;
use App\TaskType;
use Illuminate\Http\Request;
use App\Events\DeviceConfigEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use App\Helper\CentralAPIHelper;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function config_system_info(Device $device)
    {
        $central_api_helper = new CentralAPIHelper($device->client);
        if(!$device->scope_id) {
            DeviceGetScopeIdEvent::dispatch($device);
            $scope_id_response = $central_api_helper->getScopeIdFromCentral($device);
            if(!count($scope_id_response) > 0) {
                return response()->json(['error' => ' failed to get scope-id from Central']);
            }
            $device->update(['scope_id' => $scope_id_response[0]['scopeId']]);
        }

        $this->devices()->attach($device);
        $attached_device = $this->devices()->find($device);
        $attached_device->pivot->update(['status' => 'IN_PROGRESS']);

        $response = $central_api_helper->updateSystemInfo($device);

        if(!$response->ok()) {
            DeviceConfigFailedEvent::dispatch($device);
            return response()->json(['error' => 'code:' . $response->status() .' failed to configure system info from central.']);
        }

        DeviceSystemInfoUpdateEvent::dispatch($device);

        $attached_device->pivot->update(['status' => 'COMPLETED']);

        return $response;
    }

    public static function orderInterfaces(Collection $interfaces)
    {
        $portchannels = $interfaces->filter(fn($interface) => str_contains($interface['interface'], 'lag'));
        $ethernets = $interfaces->filter(fn($interface) => !str_contains($interface['interface'], 'lag'));
        $ordered_interfaces = $portchannels->merge($ethernets);
        return $ordered_interfaces;
    }

    public function store(Request $request, Deployment $deployment)
    {
        $validated = $request->validate([
            'task_type' => ['required', Rule::enum(TaskType::class) ],
            'devices' => 'required|array',
        ]);

        $device_collection = Collection::make($validated['devices']);
        $task = Task::create([
            'task_type' => $validated['task'],
            'name' => 'task_for_' . $deployment->name . now(),
            'deployment_id' => $deployment->id
        ]);

        $task->devices()->attach($device_collection);
        return response()->json(['message' => 'Task created successfully']);
    }
}
