<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SwitchPortResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'access_vlan' => $this->access_vlan,
            'interface_mode' => $this->interface_mode,
            'native_vlan' => $this->native_vlan,
            'trunk_vlan_all' => $this->trunk_vlan_all,
            'trunk_vlan_ranges' => $this->trunk_vlan_ranges,
        ];
    }
}
