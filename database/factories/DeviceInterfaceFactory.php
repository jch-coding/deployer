<?php

namespace Database\Factories;

use App\InterfaceKind;
use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DeviceInterface>
 */
class DeviceInterfaceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'interface' => random_int(1, 9).'/1/'.random_int(1, 52),
            'interface_kind' => InterfaceKind::ETHERNET,
            'description' => fake()->sentence(),
            'enable' => true,
            'jumbo_frames' => false,
            'routing' => false,
            'vrf_forwarding' => 'default',
            'device_id' => Device::factory(),
        ];
    }
}
