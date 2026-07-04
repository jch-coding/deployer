<?php

namespace Database\Factories;

use App\CentralScopeCacheType;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CentralScopeCache>
 */
class CentralScopeCacheFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'type' => CentralScopeCacheType::Sites,
            'items' => [],
            'refreshed_at' => now(),
            'last_error' => null,
        ];
    }

    public function sites(array $sites = []): static
    {
        return $this->state(fn () => [
            'type' => CentralScopeCacheType::Sites,
            'items' => $sites !== [] ? $sites : [
                ['scopeName' => 'Central Site', 'scopeId' => 'scope-site'],
            ],
        ]);
    }

    public function groups(array $payload = []): static
    {
        $defaultPayload = [
            'central_device_groups' => [
                ['scopeName' => 'Central Group', 'scopeId' => 'scope-group'],
            ],
            'device_group_options' => [
                ['scopeName' => 'Central Group', 'scopeId' => 'scope-group', 'isClassic' => false],
                ['scopeName' => 'Classic Only Group', 'scopeId' => '', 'isClassic' => true],
            ],
            'classic_device_groups_error' => null,
        ];

        return $this->state(fn () => [
            'type' => CentralScopeCacheType::Groups,
            'items' => $payload !== [] ? $payload : $defaultPayload,
        ]);
    }
}
