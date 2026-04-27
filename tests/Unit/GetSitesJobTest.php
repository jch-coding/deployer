<?php

use App\Helper\CentralAPIHelper;
use App\Jobs\GetSitesJob;
use App\Models\Client;
use App\Models\Site;
use Illuminate\Support\Facades\Log;

function centralSitesResponse(array $payload, bool $ok = true): object
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

it('fetches paginated sites and updates matching local site scope ids', function () {
    $siteA = Site::factory()->create(['name' => 'Site A', 'scope_id' => null]);
    $siteB = Site::factory()->create(['name' => 'Site B', 'scope_id' => null]);

    $client = mock(Client::class)->makePartial();
    $client->shouldReceive('handleBearerTokenAuth')->once()->andReturnTrue();

    $helper = mock(CentralAPIHelper::class, [$client])->makePartial();
    $helper->shouldReceive('get_sites')->once()->with(['offset' => 0, 'limit' => 100])->andReturn(
        centralSitesResponse([
            'items' => [
                ['scopeName' => 'Site A', 'scopeId' => 'scope-a'],
                ['scopeName' => 'Site B', 'scopeId' => 'scope-b'],
            ],
        ])
    );

    $job = new GetSitesJob($helper, [
        ['name' => 'Site A', 'devices' => []],
        ['name' => 'Site B', 'devices' => []],
    ]);
    $job->handle();

    expect($siteA->fresh()->scope_id)->toBe('scope-a')
        ->and($siteB->fresh()->scope_id)->toBe('scope-b');
});

it('continues pagination until an empty page is returned', function () {
    Site::factory()->create(['name' => 'Site Z', 'scope_id' => null]);

    $client = mock(Client::class)->makePartial();
    $client->shouldReceive('handleBearerTokenAuth')->once()->andReturnTrue();

    $helper = mock(CentralAPIHelper::class, [$client])->makePartial();
    $helper->shouldReceive('get_sites')->once()->with(['offset' => 0, 'limit' => 100])->andReturn(
        centralSitesResponse([
            'items' => array_map(
                fn ($i) => ['scopeName' => 'Filler-'.$i, 'scopeId' => 'scope-'.$i],
                range(1, 100)
            ),
        ])
    );
    $helper->shouldReceive('get_sites')->once()->with(['offset' => 100, 'limit' => 100])->andReturn(
        centralSitesResponse(['items' => [['scopeName' => 'Site Z', 'scopeId' => 'scope-z']]])
    );

    $job = new GetSitesJob($helper, [
        ['name' => 'Site Z', 'devices' => []],
    ]);
    $job->handle();

    expect(Site::query()->where('name', 'Site Z')->value('scope_id'))->toBe('scope-z');
});

it('throws when a paginated get_sites call fails', function () {
    $client = mock(Client::class)->makePartial();
    $client->shouldReceive('handleBearerTokenAuth')->once()->andReturnTrue();

    $helper = mock(CentralAPIHelper::class, [$client])->makePartial();
    $helper->shouldReceive('get_sites')->once()->with(['offset' => 0, 'limit' => 100])->andReturn(
        centralSitesResponse([
            'items' => array_map(
                fn ($i) => ['scopeName' => 'PageOne-'.$i, 'scopeId' => 'scope-'.$i],
                range(1, 100)
            ),
        ])
    );
    $helper->shouldReceive('get_sites')->once()->with(['offset' => 100, 'limit' => 100])->andReturn(
        centralSitesResponse(['message' => 'Central error'], false)
    );

    Log::shouldReceive('error')->once()->with('Failed to get sites from Central');

    $job = new GetSitesJob($helper, [
        ['name' => 'Missing Site', 'devices' => []],
    ]);

    $job->handle();
})->throws(Exception::class, 'Failed to get sites from Central');

it('logs when requested sites are not found across paginated results', function () {
    Site::factory()->create(['name' => 'Site Present', 'scope_id' => null]);
    Site::factory()->create(['name' => 'Site Missing', 'scope_id' => null]);

    $client = mock(Client::class)->makePartial();
    $client->shouldReceive('handleBearerTokenAuth')->once()->andReturnTrue();

    $helper = mock(CentralAPIHelper::class, [$client])->makePartial();
    $helper->shouldReceive('get_sites')->once()->with(['offset' => 0, 'limit' => 100])->andReturn(
        centralSitesResponse([
            'items' => [
                ['scopeName' => 'Site Present', 'scopeId' => 'scope-present'],
            ],
        ])
    );

    Log::shouldReceive('error')->once()->with('Not all sites are configured in Central');

    $job = new GetSitesJob($helper, [
        ['name' => 'Site Present', 'devices' => []],
        ['name' => 'Site Missing', 'devices' => []],
    ]);
    $job->handle();

    expect(Site::query()->where('name', 'Site Present')->value('scope_id'))->toBe('scope-present')
        ->and(Site::query()->where('name', 'Site Missing')->value('scope_id'))->toBeNull();
});
