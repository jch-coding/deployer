<?php

namespace App\Broadcasting;

use App\Models\Device;
use App\Models\User;

class DeviceChannel
{
    /**
     * Create a new channel instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Authenticate the user's access to the channel.
     */
    public function join(User $user, string $name): array|bool
    {
        return $user->devices()->where('name', $name)->exists();
    }
}
