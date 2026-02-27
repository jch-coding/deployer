<?php

namespace Database\Factories;

use App\DeviceFunction;
use App\Models\Client;
use App\Models\Deployment;
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
        $client = Client::factory()->create();
        $deployment = Deployment::factory()->for($client)->create();
        return [
            'name' => fake()->name(),
            'scope_id' => fake()->uuid(),
            'serial' => fake()->uuid(),
            'client_id' => $client,
            'deployment_id' => $deployment,
            'device_function' => fake()->randomElement(DeviceFunction::cases())->name,
        ];
    }
}
