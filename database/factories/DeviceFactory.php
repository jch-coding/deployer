<?php

namespace Database\Factories;

use App\DeviceFunction;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Device>
 */
class DeviceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'scope_id' => fake()->uuid(),
            'serial' => fake()->uuid(),
            'client_id' => Client::factory(),
            'device_function' => fake()->randomElement(DeviceFunction::cases())->name,
        ];
    }
}
