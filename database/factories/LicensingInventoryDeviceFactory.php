<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LicensingInventoryDevice>
 */
class LicensingInventoryDeviceFactory extends Factory
{
    public function definition(): array
    {
        $serial = 'SN-'.fake()->unique()->numerify('######');

        return [
            'client_id' => Client::factory(),
            'serial' => $serial,
            'model' => 'AP-515',
            'mac' => fake()->macAddress(),
            'device_type' => 'IAP',
            'name' => $serial,
            'licensed' => true,
            'assigned_services' => ['advanced_ap'],
            'subscription_key' => 'KEY-001',
            'deployer_device_id' => null,
        ];
    }
}
