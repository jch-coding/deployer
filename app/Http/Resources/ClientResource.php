<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'id' => $this->id,
            'client_id' => $this->client_id,
            'customer_id' => $this->customer_id,
            'current' => $this->current,
            'base_url' => $this->base_url,
            'classic_base_url' => $this->classic_base_url,
            'classic_client_id' => $this->classic_client_id,
            'has_classic_refresh_token' => $this->classic_refresh_token !== null,
            'has_classic_access_token' => $this->classic_access_token !== null,
            'classic_expires_in' => $this->classic_expires_in?->toIso8601String(),
            'classic_webhook_url' => $this->classicWebhookUrl(),
            'has_classic_webhook_secret' => filled($this->classic_webhook_secret),
            'classic_webhook_wid' => $this->classic_webhook_wid,
            'classic_streaming_hostname' => $this->classic_streaming_hostname,
            'classic_streaming_username' => $this->classic_streaming_username,
            'has_classic_streaming_key' => filled($this->classic_streaming_key),
            'has_classic_streaming_credentials' => $this->hasClassicStreamingCredentials(),
            'deployments_count' => $this->deployments_count,
            'devices_count' => $this->devices_count,
            'user' => $this->whenLoaded('user', fn () => UserResource::make($this->user)),
        ];
    }
}
