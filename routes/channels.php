<?php

use App\Models\Device;
use Illuminate\Support\Facades\Broadcast;

function isDeviceClientUserClient($user, $name)
{
    return $user->clients->contains(Device::where('name', $name)->first()->client_id);
}

Broadcast::channel('devices.{name}', function ($user, $name) {
    return isDeviceClientUserClient($user, $name);
});

Broadcast::channel('devices.scope_id.{name}', function ($user, $name) {
    return isDeviceClientUserClient($user, $name);
});

Broadcast::channel('devices.sys_info_update.{name}', function ($user, $name) {
    return isDeviceClientUserClient($user, $name);
});

Broadcast::channel('devices.config_failed.{name}', function ($user, $name) {
    return isDeviceClientUserClient($user, $name);
});
