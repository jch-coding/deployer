<?php

namespace App\Models;

use App\Models\Concerns\PrunesClientEvents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CentralWebhookEvent extends Model
{
    use PrunesClientEvents;

    public $timestamps = false;

    protected $fillable = [
        'client_id',
        'payload',
        'alert_type',
        'serial',
        'disposition',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return array{id: int, alert_type: string|null, serial: string|null, disposition: string, payload: array<string, mixed>, created_at: string|null, human_created_at: string|null}
     */
    public function toMonitorRow(): array
    {
        return [
            'id' => $this->id,
            'alert_type' => $this->alert_type,
            'serial' => $this->serial,
            'disposition' => $this->disposition,
            'payload' => $this->payload ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
            'human_created_at' => $this->created_at?->diffForHumans(),
        ];
    }
}
