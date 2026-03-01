<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StpProfile>
 */
class StpProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'admin_edge_port' => false,
            'admin_edge_port_trunk' => false,
            'bpdu_guard' => false,
            'loop_guard' => false,
        ];
    }
}
