<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Device extends Model
{
    /** @use HasFactory<\Database\Factories\DeviceFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'device_id',
        'client_id',
        'deployment_id',
        'serial',
        'device_function'
    ];

    public function client() : BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function tasks() : BelongsToMany
    {
        return $this->belongsToMany(Task::class);
    }

    public function deployment() : BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }
}
