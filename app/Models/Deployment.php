<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Deployment extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'client_id'];

    public function devices() : HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function client() : BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function tasks() : HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function getTasks()
    {
        $devices = $this->devices;
        return $devices->flatMap(fn($device) => $device->tasks->map(fn($task) => $task))->unique('id');
    }
}
