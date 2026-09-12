<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceInterfaceSnapshot extends Model
{
    /** @use HasFactory<\Database\Factories\DeviceInterfaceSnapshotFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'client_id',
        'serial',
        'device_name',
        'device_type',
        'device_function',
        'interfaces',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'interfaces' => 'array',
            'captured_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
