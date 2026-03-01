<?php

namespace App\Http\Controllers;

use App\Events\DeviceConfigFailedEvent;
use App\Events\DeviceGetScopeIdEvent;
use App\Events\DeviceSystemInfoUpdateEvent;
use App\Models\Device;
use Illuminate\Http\Request;
use App\Events\DeviceConfigEvent;
use Illuminate\Support\Facades\Http;
use App\Helper\CentralAPIHelper;

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
}
