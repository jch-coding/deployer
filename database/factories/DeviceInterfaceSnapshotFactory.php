<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DeviceInterfaceSnapshot>
 */
class DeviceInterfaceSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'client_id' => fn (array $attributes) => Client::factory()->create([
                'user_id' => $attributes['user_id'],
            ])->id,
            'serial' => strtoupper(fake()->bothify('SN######')),
            'device_name' => fake()->word().'-Switch',
            'device_type' => 'SWITCH',
            'device_function' => 'ACCESS_SWITCH',
            'interfaces' => [
                [
                    'name' => '1/1/1',
                    'status' => 'Connected',
                    'operStatus' => 'Up',
                    'neighbour' => 'AP-1',
                    'neighbourSerial' => 'AP12345',
                    'vlanMode' => 'ACCESS',
                    'allowedVlanIds' => [10],
                    'nativeVlan' => '10',
                    'poeClass' => '4',
                    'neighbourFamily' => 'AP',
                    'neighbourFunction' => 'CAMPUS',
                    'neighbourType' => 'ACCESS_POINT',
                    'transceiverType' => '',
                ],
            ],
            'captured_at' => now(),
        ];
    }
}
