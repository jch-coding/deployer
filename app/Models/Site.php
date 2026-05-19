<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    /** @use HasFactory<\Database\Factories\SiteFactory> */
    use HasFactory;

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public static function firstOrCreateForClient(Client|int $client, string $name): self
    {
        $clientId = $client instanceof Client ? $client->id : $client;

        return static::firstOrCreate(
            ['client_id' => $clientId, 'name' => $name],
        );
    }
}
