<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StpProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'admin_edge_port' => $this->admin_edge_port,
            'admin_edge_port_trunk' => $this->admin_edge_port_trunk,
            'bpdu_guard' => $this->bpdu_guard,
            'loop_guard' => $this->loop_guard
        ];
    }
}
