<?php

namespace App\Models;

use App\BaseURL;
use App\ClassicBaseUrl;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
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

    public function centralScopeCaches(): HasMany
    {
        return $this->hasMany(CentralScopeCache::class);
    }

    public function clientSubscriptions(): HasMany
    {
        return $this->hasMany(ClientSubscription::class);
    }

    public function licensingInventoryDevices(): HasMany
    {
        return $this->hasMany(LicensingInventoryDevice::class);
    }

    protected function casts(): array
    {
        return [
            'licensing_enabled_services' => 'array',
            'licensing_synced_at' => 'datetime',
            'expires_at' => 'datetime',
            'classic_base_url' => ClassicBaseUrl::class,
            'current' => 'boolean',
            'client_secret' => 'encrypted',
            'bearer_token' => 'encrypted',
            'classic_client_secret' => 'encrypted',
            'classic_password' => 'encrypted',
            'classic_access_token' => 'encrypted',
            'classic_refresh_token' => 'encrypted',
            'classic_expires_in' => 'datetime',
            'classic_webhook_secret' => 'encrypted',
            'classic_streaming_key' => 'encrypted',
        ];
    }

    public function classicWebhookUrl(): string
    {
        return url('/webhooks/central/'.$this->getKey());
    }

    public function hasClassicStreamingCredentials(): bool
    {
        return filled($this->classic_streaming_hostname)
            && filled($this->classic_streaming_key)
            && filled($this->classic_streaming_username);
    }

    protected function baseURL(): Attribute
    {
        return Attribute::make(
            get: function (string|BaseURL $value): string {
                $region = $value instanceof BaseURL ? $value->value : $value;

                return "https://{$region}.api.central.arubanetworks.com/";
            },
        );
    }

    public function handleBearerTokenAuth(bool $force = false)
    {
        $needsToken = $force
            || blank($this->bearer_token)
            || $this->expires_at === null
            || $this->expires_at < now();

        if (! $needsToken) {
            return true;
        }

        try {
            $response = Http::asForm()->post($this->auth_url, [
                'grant_type' => 'client_credentials',
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
            ]);
        } catch (RequestException|ConnectionException) {
            return false;
        }

        if ($response->ok()) {
            $this->bearer_token = $response->json('access_token');
            $this->expires_at = now()->addHour();
            $this->save();
        }

        return $response->ok() && ! blank($this->bearer_token);
    }

    public function handleClassicBearerToken(bool $force = false)
    {
        try {
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
        } catch (RequestException|ConnectionException) {
            return false;
        }
    }

    public function hasClassicCentralCredentials(): bool
    {
        return $this->classic_client_id !== null && $this->classic_client_secret !== null && $this->classic_username !== null && $this->classic_password !== null;
    }

    public function classicBaseUrlString(): string
    {
        $base = $this->classic_base_url;

        return $base instanceof ClassicBaseUrl ? $base->value : (string) $base;
    }

    public function updateClassicRefreshToken(string $refreshToken): bool
    {
        return $this->updateClassicCentralTokens($refreshToken, null);
    }

    public function updateClassicCentralTokens(?string $refreshToken, ?string $accessToken): bool
    {
        if ($refreshToken === null && $accessToken === null) {
            return false;
        }

        if ($refreshToken !== null) {
            $this->classic_refresh_token = $refreshToken;
            $this->classic_access_token = null;
        }

        if ($accessToken !== null) {
            $this->classic_access_token = $accessToken;
        }

        if ($this->classic_refresh_token === null) {
            return false;
        }

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
        ])->post($this->classicBaseUrlString().'oauth2/token/');

        return $response;
    }

    public function authenticateClassicCentral()
    {
        $response = Http::withQueryParameters([
            'client_id' => $this->classic_client_id,
        ])->post($this->classicBaseUrlString().'oauth2/authorize/central/api/login', [
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
        ])->post($this->classicBaseUrlString().'oauth2/authorize/central/api/', ['customer_id' => $this->customer_id]);

        return $response;
    }

    public function acquireTokens(string $auth_code)
    {
        $response = Http::post($this->classicBaseUrlString().'oauth2/token/', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->classic_client_id,
            'client_secret' => $this->classic_client_secret,
            'code' => $auth_code,
        ]);

        return $response;
    }
}
