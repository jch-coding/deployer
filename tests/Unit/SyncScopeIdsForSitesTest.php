<?php

use App\Helper\CentralAPIHelper;
use App\Models\Client;
use App\Models\Site;

function syncScopeSitesResponse(array $payload, bool $ok = true): object
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

it('returns zero updated for an empty site collection', function () {
    $client = mock(Client::class)->makePartial();
    $helper = new CentralAPIHelper($client);

    expect($helper->syncScopeIdsForSites(collect()))->toBe([
        'updated' => 0,
        'error' => null,
    ]);
});

it('returns error when bearer token authentication fails', function () {
    $site = Site::factory()->create(['name' => 'Site A', 'scope_id' => null]);

    $client = mock(Client::class)->makePartial();
    $client->shouldReceive('handleBearerTokenAuth')->once()->andReturnFalse();

    $helper = new CentralAPIHelper($client);

    expect($helper->syncScopeIdsForSites(collect([$site])))->toBe([
        'updated' => 0,
        'error' => 'Could not authenticate with Central to load site scope IDs.',
    ]);

    expect($site->fresh()->scope_id)->toBeNull();
});

it('paginates get_sites and updates matching local site scope ids', function () {
    $siteA = Site::factory()->create(['name' => 'Site A', 'scope_id' => null]);
    $siteB = Site::factory()->create(['name' => 'Site B', 'scope_id' => null]);

    $client = mock(Client::class)->makePartial();
    $client->shouldReceive('handleBearerTokenAuth')->once()->andReturnTrue();

    $helper = mock(CentralAPIHelper::class, [$client])->makePartial();
    $helper->shouldReceive('get_sites')->once()->with(['offset' => 0, 'limit' => 100])->andReturn(
        syncScopeSitesResponse([
            'items' => [
                ['scopeName' => 'Site A', 'scopeId' => 'scope-a'],
                ['scopeName' => 'Site B', 'scopeId' => 'scope-b'],
            ],
        ])
    );

    expect($helper->syncScopeIdsForSites(collect([$siteA, $siteB])))->toBe([
        'updated' => 2,
        'error' => null,
    ]);

    expect($siteA->fresh()->scope_id)->toBe('scope-a')
        ->and($siteB->fresh()->scope_id)->toBe('scope-b');
});

it('returns error when get_sites responds with http failure', function () {
    $site = Site::factory()->create(['name' => 'Site A', 'scope_id' => null]);

    $client = mock(Client::class)->makePartial();
    $client->shouldReceive('handleBearerTokenAuth')->once()->andReturnTrue();

    $helper = mock(CentralAPIHelper::class, [$client])->makePartial();
    $helper->shouldReceive('get_sites')->once()->with(['offset' => 0, 'limit' => 100])->andReturn(
        syncScopeSitesResponse(['message' => 'Central error'], false)
    );

    expect($helper->syncScopeIdsForSites(collect([$site])))->toBe([
        'updated' => 0,
        'error' => 'Could not load site scope IDs from Central.',
    ]);
});

it('returns error listing sites still missing scope id after paginated lookup', function () {
    $sitePresent = Site::factory()->create(['name' => 'Site Present', 'scope_id' => null]);
    $siteMissing = Site::factory()->create(['name' => 'Site Missing', 'scope_id' => null]);

    $client = mock(Client::class)->makePartial();
    $client->shouldReceive('handleBearerTokenAuth')->once()->andReturnTrue();

    $helper = mock(CentralAPIHelper::class, [$client])->makePartial();
    $helper->shouldReceive('get_sites')->once()->with(['offset' => 0, 'limit' => 100])->andReturn(
        syncScopeSitesResponse([
            'items' => [
                ['scopeName' => 'Site Present', 'scopeId' => 'scope-present'],
            ],
        ])
    );

    expect($helper->syncScopeIdsForSites(collect([$sitePresent, $siteMissing])))->toBe([
        'updated' => 1,
        'error' => 'Could not resolve scope ID for sites: Site Missing.',
    ]);

    expect($sitePresent->fresh()->scope_id)->toBe('scope-present')
        ->and($siteMissing->fresh()->scope_id)->toBeNull();
});
