<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DeviceInterface extends Model
{
    /** @use HasFactory<\Database\Factories\DeviceInterfaceFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'enable',
        'jumbo_frames',
        'routing',
        'vrf_forwarding',
        'device_id',
        'lacp_profile_id',
        'switchport_profile_id'
    ];

    protected $casts = [
        'enable' => 'boolean',
    ];

    public function device() : BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function switchports() : BelongsToMany
    {
        return $this->belongsToMany(SwitchPort::class);
    }

    public function lacpProfile() : BelongsTo
    {
        return $this->belongsTo(LacpProfile::class);
    }
}
