<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class DeviceTask extends Pivot
{
    protected $table = 'device_task';

    protected $casts = [
        'greenlake_step_statuses' => 'array',
    ];
}
