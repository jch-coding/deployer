<?php

namespace App\Models;

use App\CentralScopeCacheType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CentralScopeCache extends Model
{
    /** @use HasFactory<\Database\Factories\CentralScopeCacheFactory> */
    use HasFactory;

    protected $fillable = [
        'client_id',
        'type',
        'items',
        'refreshed_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'type' => CentralScopeCacheType::class,
            'items' => 'array',
            'refreshed_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
