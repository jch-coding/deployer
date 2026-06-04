<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientSubscription extends Model
{
    /** @use HasFactory<\Database\Factories\ClientSubscriptionFactory> */
    use HasFactory;

    protected $fillable = [
        'client_id',
        'subscription_key',
        'greenlake_subscription_id',
        'subscription_sku',
        'license_type',
        'start_date',
        'end_date',
        'status',
        'subscription_type',
        'available',
        'quantity',
        'acpapp_name',
        'tags',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'integer',
            'end_date' => 'integer',
            'available' => 'integer',
            'quantity' => 'integer',
            'tags' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toNormalizedArray(): array
    {
        return [
            'subscription_key' => $this->subscription_key,
            'greenlake_subscription_id' => $this->greenlake_subscription_id,
            'subscription_sku' => $this->subscription_sku,
            'license_type' => $this->license_type,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'status' => $this->status,
            'subscription_type' => $this->subscription_type,
            'available' => $this->available,
            'quantity' => $this->quantity,
            'acpapp_name' => $this->acpapp_name,
            'tags' => $this->tags ?? [],
        ];
    }
}
