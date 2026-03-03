<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
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
            'interfaces' => $this->whenLoaded('interfaces', fn() => DeviceInterfaceResource::collection($this->interfaces)),
            'device_function' => $this->device_function,
            'serial' => $this->serial,
            'scope_id' => $this->scope_id,
        ];
    }
}
