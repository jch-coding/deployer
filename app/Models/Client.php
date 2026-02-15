<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Client extends Model
{
    /** @use HasFactory<\Database\Factories\ClientFactory> */
    use HasFactory;

    protected $fillable = ['name', 'client_id', 'client_secret', 'customer_id', 'current'];

    public function user() : BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts() : array
    {
        return [
            'client_secret' => 'encrypted',
            'current' => 'boolean',
        ];
    }
    protected function baseURL() : Attribute
    {
        return Attribute::make(
            get: fn (string $value) => "https://{$value}.api.central.arubanetworks.com/",
        );
    }
}
