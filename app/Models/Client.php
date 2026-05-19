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

    protected $auth_url = 'https://sso.common.cloud.hpe.com/as/token.oauth2';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    protected function casts(): array
    {
        return [
            'current' => 'boolean',
            'client_secret' => 'encrypted',
            'bearer_token' => 'encrypted',
            'classic_client_secret' => 'encrypted',
            'classic_password' => 'encrypted',
            'classic_access_token' => 'encrypted',
            'classic_refresh_token' => 'encrypted',
            'classic_expires_in' => 'datetime',
        ];
    }

    protected function baseURL(): Attribute
    {
        return Attribute::make(
            get: fn (string $value) => "https://{$value}.api.central.arubanetworks.com/",
        );
    }

    public function handleBearerTokenAuth(bool $force = false)
    {
        if ($force || $this->expires_at < now()) {
            $response = Http::asForm()->post($this->auth_url, [
                'grant_type' => 'client_credentials',
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
            ]);

            if ($response->ok()) {
                $this->bearer_token = $response->json('access_token');
                $this->expires_at = now()->addHour();
                $this->save();
            }

            return $response->ok();
        }

        return true;
    }

    public function handleClassicBearerToken(bool $force = false)
    {
        if (! $this->hasClassicCentralCredentials()) {
            return false;
        } elseif ($this->classic_refresh_token !== null && now() < $this->classic_expires_in) {
            return true;
        } elseif (($force && $this->classic_refresh_token !== null) || $this->classic_refresh_token !== null && now() > $this->classic_expires_in) {
            $response = $this->refreshClassicCentralBearerToken();
            if (! $response->ok()) {
                return false;
            } else {
                $this->classic_access_token = $response->json('access_token');
                $this->classic_refresh_token = $response->json('refresh_token');
                $this->classic_expires_in = now()->addSeconds($response->json('expires_in'));
                $this->save();

                return true;
            }
        } else {
            $response = $this->authenticateClassicCentral();
            if (! $response->ok()) {
                return false;
            } else {
                $set_cookie = $response->headers()['Set-Cookie'];
                $extracted_csrftoken_and_session = $this->extractCSRFTokenAndSession($set_cookie);
                $response = $this->generateClassicAuthorizationCode($extracted_csrftoken_and_session['csrftoken'], $extracted_csrftoken_and_session['session']);
                if (! $response->ok()) {
                    return false;
                } else {
                    $authorization_code = $response->json()['auth_code'];
                    $response = $this->acquireTokens($authorization_code);
                    if (! $response->ok()) {
                        return false;
                    } else {
                        $this->classic_access_token = $response->json('access_token');
                        $this->classic_refresh_token = $response->json('refresh_token');
                        $this->classic_expires_in = now()->addSeconds($response->json('expires_in'));
                        $this->save();

                        return true;
                    }
                }
            }
        }
    }

    public function hasClassicCentralCredentials(): bool
    {
        return $this->classic_client_id !== null && $this->classic_client_secret !== null && $this->classic_username !== null && $this->classic_password !== null;
    }

    public function updateClassicRefreshToken(string $refreshToken): bool
    {
        $this->classic_refresh_token = $refreshToken;
        $this->classic_access_token = null;
        $this->classic_expires_in = now()->subSecond();
        $this->save();

        return $this->handleClassicBearerToken(true);
    }

    public function refreshClassicCentralBearerToken()
    {
        $response = Http::withQueryParameters([
            'grant_type' => 'refresh_token',
            'client_id' => $this->classic_client_id,
            'client_secret' => $this->classic_client_secret,
            'refresh_token' => $this->classic_refresh_token,
        ])->post($this->classic_base_url.'oauth2/token/');

        return $response;
    }

    public function authenticateClassicCentral()
    {
        $response = Http::withQueryParameters([
            'client_id' => $this->classic_client_id,
        ])->post($this->classic_base_url.'oauth2/authorize/central/api/login', [
            'username' => $this->classic_username,
            'password' => $this->classic_password,
        ]);

        return $response;
    }

    public function extractCSRFTokenAndSession(array $set_cookie)
    {
        $cookie_contents = array_map(fn ($cookie) => explode(';', $cookie)[0], $set_cookie);
        $content_only = array_map(fn ($cookie) => explode('=', $cookie)[1], $cookie_contents);
        $csrftoken = $content_only[0];
        $session = $content_only[1];

        return ['csrftoken' => $csrftoken, 'session' => $session];
    }

    public function generateClassicAuthorizationCode(string $csrftoken, string $session)
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-CSRF-Token' => $csrftoken,
            'Cookie' => 'session='.$session,
        ])->withQueryParameters([
            'client_id' => $this->classic_client_id,
            'response_type' => 'code',
            'scope' => 'all',
        ])->post($this->classic_base_url.'oauth2/authorize/central/api/', ['customer_id' => $this->customer_id]);

        return $response;
    }

    public function acquireTokens(string $auth_code)
    {
        $response = Http::post($this->classic_base_url.'oauth2/token/', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->classic_client_id,
            'client_secret' => $this->classic_client_secret,
            'code' => $auth_code,
        ]);

        return $response;
    }
}
