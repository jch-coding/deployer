<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicensingInventoryDevice extends Model
{
    /** @use HasFactory<\Database\Factories\LicensingInventoryDeviceFactory> */
    use HasFactory;

    protected $fillable = [
        'client_id',
        'serial',
        'greenlake_device_id',
        'model',
        'mac',
        'device_type',
        'name',
        'licensed',
        'assigned_services',
        'subscription_key',
        'deployer_device_id',
    ];

    protected function casts(): array
    {
        return [
            'licensed' => 'boolean',
            'assigned_services' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function deployerDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'deployer_device_id');
    }
}
