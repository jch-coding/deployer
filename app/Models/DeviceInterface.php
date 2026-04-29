<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DeviceInterface extends Model
{
    /** @use HasFactory<\Database\Factories\DeviceInterfaceFactory> */
    use HasFactory;

    protected $fillable = [
        'interface',
        'description',
        'ip_address',
        'enable',
        'jumbo_frames',
        'routing',
        'vrf_forwarding',
        'device_id',
        'lacp_profile_id',
        'switch_port_id',
        'stp_profile_id',
        'sw_profile',
        'portchannel_lag',
    ];

    protected $casts = [
        'enable' => 'boolean',
    ];

    public function device() : BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function switch_port() : BelongsTo
    {
        return $this->belongsTo(SwitchPort::class);
    }

    public function lacp_profile() : BelongsTo
    {
        return $this->belongsTo(LacpProfile::class);
    }

    public function stp_profile() : BelongsTo
    {
        return $this->belongsTo(StpProfile::class);
    }

    public function tasks() : BelongsToMany
    {
        return $this->belongsToMany(Task::class)->withPivot('status')->withTimestamps();
    }

}
