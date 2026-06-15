<?php

use App\Helper\CentralAPIHelper;
use App\Models\Client;
use Illuminate\Support\Facades\Http;

function scopeManagementResponse(array $payload, bool $ok = true): object
{
    return new class($payload, $ok)
    {
        public function __construct(private array $payload, private bool $okStatus) {}

        public function ok(): bool
        {
            return $this->okStatus;
        }

        public function json(?string $key = null, mixed $default = null): mixed
        {
            if ($key === null) {
                return $this->payload;
            }

            return $this->payload[$key] ?? $default;
        }
    };
}

it('collectScopeManagementSites paginates and maps scope names', function () {
    $client = mock(Client::class)->makePartial();
    $client->shouldReceive('handleBearerTokenAuth')->once()->andReturnTrue();

    $helper = mock(CentralAPIHelper::class, [$client])->makePartial();
    $helper->shouldReceive('get_sites')->once()->with(['offset' => 0, 'limit' => 100])->andReturn(
        scopeManagementResponse([
            'items' => [
                ['scopeName' => 'Site A', 'scopeId' => 'scope-a'],
                ['scopeName' => 'Site B', 'scopeId' => 'scope-b'],
            ],
        ])
    );

    expect($helper->collectScopeManagementSites())->toBe([
        'sites' => [
            ['scopeName' => 'Site A', 'scopeId' => 'scope-a'],
            ['scopeName' => 'Site B', 'scopeId' => 'scope-b'],
        ],
        'error' => null,
    ]);
});

it('collectScopeManagementDeviceGroups maps scope names from Central', function () {
    $client = mock(Client::class)->makePartial();
    $client->shouldReceive('handleBearerTokenAuth')->once()->andReturnTrue();

    $helper = mock(CentralAPIHelper::class, [$client])->makePartial();
    $helper->shouldReceive('get_device_groups')->once()->andReturn(
        scopeManagementResponse([
            'items' => [
                ['scopeName' => 'Group A', 'scopeId' => 'group-a'],
            ],
        ])
    );

    expect($helper->collectScopeManagementDeviceGroups())->toBe([
        'groups' => [
            ['scopeName' => 'Group A', 'scopeId' => 'group-a'],
        ],
        'error' => null,
    ]);
});

it('collectScopeManagementSites returns error when authentication fails', function () {
    $client = mock(Client::class)->makePartial();
    $client->shouldReceive('handleBearerTokenAuth')->once()->andReturnFalse();

    $helper = new CentralAPIHelper($client);

    expect($helper->collectScopeManagementSites())->toMatchArray([
        'sites' => [],
        'error' => 'Could not authenticate with Central to load sites.',
    ]);
});

it('collectScopeManagementSites returns error when Central responds with a client error', function () {
    $client = Client::factory()->create([
        'bearer_token' => 'test-bearer-token',
        'expires_at' => now()->addHour(),
    ]);

    Http::fake([
        '*' => Http::response([], 403),
    ]);

    $helper = new CentralAPIHelper($client);

    expect($helper->collectScopeManagementSites())->toMatchArray([
        'sites' => [],
        'error' => 'Could not load sites from Central.',
    ]);
});
