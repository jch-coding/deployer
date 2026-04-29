<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    /** @use HasFactory<\Database\Factories\DeviceFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'device_id',
        'client_id',
        'user_id',
        'site_id',
        'deployment_id',
        'serial',
        'device_function',
        'scope_id',
        'stack_id',
    ];

    public function client() : BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function user() : BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tasks() : BelongsToMany
    {
        return $this->belongsToMany(Task::class)->withPivot('status')->withTimestamps();
    }

    public function deployment() : BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    public function site() : BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function interfaces() : HasMany
    {
        return $this->hasMany(DeviceInterface::class);
    }
}
