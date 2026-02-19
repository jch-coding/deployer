<?php

namespace Database\Factories;

use App\BaseURL;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Client>
 */
class ClientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'client_id' => fake()->uuid(),
            'client_secret' => fake()->password(),
            'customer_id' => fake()->uuid(),
            'bearer_token' => fake()->uuid(),
            'user_id' => User::factory(),
            'current' => false,
            'base_url' => fake()->randomElement(BaseURL::cases()),
        ];
    }
}
