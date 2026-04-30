<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LacpProfile extends Model
{
    /** @use HasFactory<\Database\Factories\LACPProfileFactory> */
    use HasFactory;

    public function deviceInterfaces() : HasMany
    {
        return $this->hasMany(DeviceInterface::class);
    }

    protected function portList() : Attribute
    {
        return Attribute::make(
            get: fn($value) => collect(explode('&', $value))->map(fn ($port) => explode('-', $port))->flatten()->toArray()
        );
    }
}
