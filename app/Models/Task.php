<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Task extends Model
{
    /** @use HasFactory<\Database\Factories\TaskFactory> */
    use HasFactory;
    protected $fillable = [
        'name',
        'task_type',
        'deployment_time',
        'batch_id',
    ];

    public function users() : BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function devices() : BelongsToMany
    {
        return $this->belongsToMany(Device::class)->withPivot('status')->withTimestamps();
    }

    public function deviceInterfaces() : BelongsToMany
    {
        return $this->belongsToMany(DeviceInterface::class)->withPivot('status')->withTimestamps();
    }

    public function deployment() : BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }
}
