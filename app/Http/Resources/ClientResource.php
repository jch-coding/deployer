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
            'deployments_count' => $this->deployments_count,
            'devices_count' => $this->devices_count,
            'user' => $this->whenLoaded('user', fn () => UserResource::make($this->user)),
        ];
    }
}
