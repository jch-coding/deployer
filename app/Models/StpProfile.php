<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StpProfile extends Model
{
    /** @use HasFactory<\Database\Factories\StpProfileFactory> */
    use HasFactory;

    protected $fillable = [
        'admin_edge_port',
        'admin_edge_port_trunk',
        'bpdu_guard',
        'loop_guard'
    ];

    protected $casts = [
        'admin_edge_port' => 'boolean',
        'admin_edge_port_trunk' => 'boolean',
        'bpdu_guard' => 'boolean',
        'loop_guard' => 'boolean',
    ];

    public function interfaces() : HasMany
    {
        return $this->HasMany(DeviceInterface::class);
    }
}
