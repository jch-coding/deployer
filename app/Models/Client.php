<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Http;

class Client extends Model
{
    /** @use HasFactory<\Database\Factories\ClientFactory> */
    use HasFactory;

    protected $fillable = ['name', 'client_id', 'client_secret', 'customer_id', 'current', 'base_url'];

    protected $auth_url = "https://sso.common.cloud.hpe.com/as/token.oauth2";

    public function user() : BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function devices() : HasMany
    {
        return $this->hasMany(Device::class);
    }

    protected function casts() : array
    {
        return [
            'current' => 'boolean',
            'client_secret' => 'encrypted',
        ];
    }
    protected function baseURL() : Attribute
    {
        return Attribute::make(
            get: fn (string $value) => "https://{$value}.api.central.arubanetworks.com/",
        );
    }

    public function test()
    {
        echo 'test';
    }

    public function handleBearerTokenAuth()
    {
        $response = Http::asForm()->post($this->auth_url, [
            'grant_type' => 'client_credentials',
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
        ]);

        if($response->ok()) {
            $this->bearer_token = $response->json('access_token');
            $this->save();
        }

        return $response->ok();
    }
}
