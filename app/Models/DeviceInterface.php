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
        'interface',
        'description',
        'enable',
        'jumbo_frames',
        'routing',
        'vrf_forwarding',
        'device_id',
        'lacp_profile_id',
        'switch_port_id',
        'stp_profile_id'
    ];

    protected $casts = [
        'enable' => 'boolean',
    ];

    public function device() : BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function switchport() : BelongsTo
    {
        return $this->belongsTo(SwitchPort::class);
    }

    public function lacpProfile() : BelongsTo
    {
        return $this->belongsTo(LacpProfile::class);
    }

    public function stpProfile() : BelongsTo
    {
        return $this->belongsTo(StpProfile::class);
    }
}
