<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    public function interfaces() : HasMany
    {
        return $this->HasMany(DeviceInterface::class);
    }

    protected function trunkVlanRanges() : Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value === null || $value === '') {
                    return null;
                }

                return explode('&', $value);
            },
        );
    }
}
