<?php

use App\Broadcasting\DeviceChannel;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;


Broadcast::channel('devices.{name}', DeviceChannel::class);

Broadcast::channel('devices.scope_id.{name}', DeviceChannel::class);

Broadcast::channel('devices.sys_info_update.{name}', DeviceChannel::class);

Broadcast::channel('devices.config_failed.{name}', DeviceChannel::class);

Broadcast::channel('devices.config_completed.{name}', DeviceChannel::class);

Broadcast::channel('devices.central_api_fail.{name}', DeviceChannel::class);

Broadcast::channel('deployments.channel.{deployment_name}', function (User $user, string $deployment_name) {
    return $user->currentClient()->deployments()->where('name', Str::replace('-',' ', $deployment_name))->Exists();
});
