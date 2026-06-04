<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ClientSubscription>
 */
class ClientSubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'subscription_key' => fake()->uuid(),
            'subscription_sku' => 'Q9Y65AAE',
            'license_type' => 'Advanced AP',
            'start_date' => 1780425040000,
            'end_date' => 1811961040000,
            'status' => 'OK',
            'subscription_type' => 'subscription',
            'available' => 10,
            'acpapp_name' => '',
        ];
    }
}
