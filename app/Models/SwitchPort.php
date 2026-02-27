<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SwitchPort extends Model
{
    /** @use HasFactory<\Database\Factories\SwitchPortFactory> */
    use HasFactory;

    protected $fillable = [
        'access_vlan',
        'interface_mode',
        'is_profile',
        'native_vlan',
        'trunk_vlan_all',
        'trunk_vlan_ranges'
    ];

    protected $casts = [
        'trunk_vlan_all' => 'boolean',
        'is_profile' => 'boolean',
    ];
    public function interfaces() : BelongsToMany
    {
        return $this->BelongsToMany(DeviceInterface::class);
    }
}
