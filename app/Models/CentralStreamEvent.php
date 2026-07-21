<?php

namespace App\Models;

use App\Models\Concerns\PrunesClientEvents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CentralStreamEvent extends Model
{
    use PrunesClientEvents;

    public $timestamps = false;

    protected $fillable = [
        'client_id',
        'subject',
        'customer_id',
        'timestamp',
        'decoded',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'decoded' => 'array',
            'timestamp' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return array{id: int, subject: string|null, customer_id: string|null, timestamp: int|null, decoded: array<string, mixed>, created_at: string|null, human_created_at: string|null}
     */
    public function toMonitorRow(): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'customer_id' => $this->customer_id,
            'timestamp' => $this->timestamp,
            'decoded' => $this->decoded ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
            'human_created_at' => $this->created_at?->diffForHumans(),
        ];
    }
}
