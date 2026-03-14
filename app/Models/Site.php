<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Site extends Model
{
    /** @use HasFactory<\Database\Factories\SiteFactory> */
    use HasFactory;
    protected $fillable = [
        'name',
        'scope_id',
    ];

    public function devices()
    {
        return $this->hasMany(Device::class);
    }
}
