<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Device extends Model
{
    /** @use HasFactory<\Database\Factories\DeviceFactory> */
    use HasFactory;

    public function client() : BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function tasks() : BelongsToMany
    {
        return $this->belongsToMany(Task::class);
    }
}
