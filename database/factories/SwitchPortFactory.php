<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SwitchPort>
 */
class SwitchPortFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'access_vlan' => random_int(1, 4094),
            'interface_mode' => 'ACCESS',
            'is_profile' => false,
            'native_vlan' => null,
            'trunk_vlan_all' => false,
            'trunk_vlan_ranges' => null,
        ];
    }
}
