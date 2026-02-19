<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Task extends Model
{
    /** @use HasFactory<\Database\Factories\TaskFactory> */
    use HasFactory;

    public function users() : BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function devices() : BelongsToMany
    {
        return $this->belongsToMany(Device::class);
    }
}
