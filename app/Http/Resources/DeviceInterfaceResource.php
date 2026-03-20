<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceInterfaceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->interface,
            'switch_port' => $this->whenLoaded('switch_port', fn() => SwitchPortResource::make($this->switch_port)),
            'stp_profile' => $this->whenLoaded('stp_profile', fn() => StpProfileResource::make($this->stp_profile)),
            'ip_address' => $this->ip_address,
            'sw_profile' => $this->sw_profile,
        ];
    }
}
