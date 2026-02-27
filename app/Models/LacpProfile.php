<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LacpProfile extends Model
{
    /** @use HasFactory<\Database\Factories\LACPProfileFactory> */
    use HasFactory;

    protected $fillable = [
        'mode',
        'port_id',
        'timeout'
    ];

    public function deviceInterfaces() : HasMany
    {
        return $this->hasMany(DeviceInterface::class);
    }
}
