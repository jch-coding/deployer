<?php

use App\ClassicBaseUrl;
use App\Helper\CentralAPIHelper;
use App\Models\Client;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

function makeClassicSitesHelper(bool $tokenOk = true): CentralAPIHelper
{
    $client = mock(Client::class)->makePartial();
    $client->classic_base_url = ClassicBaseUrl::US1->value;
    $client->classic_access_token = 'test-access-token';
    $client->shouldReceive('handleClassicBearerToken')->andReturn($tokenOk);

    return new CentralAPIHelper($client);
}

function sitesFromClassicGetSites(CentralAPIHelper $helper): array
{
    $result = $helper->classic_get_sites();
    expect($result)->toBeInstanceOf(Response::class)
        ->and($result->ok())->toBeTrue();

    return $result->json('sites') ?? [];
}

function sitesFromCollectAll(CentralAPIHelper $helper): array
{
    $result = $helper->classic_collect_all_sites();
    expect($result)->not->toHaveKey('error');

    return $result['sites'];
}

function sitesKeyedById(array $sites): array
{
    return collect($sites)->keyBy('site_id')->all();
}

/**
 * @param  array<int, array<string, mixed>>  $unpaginatedSites
 * @param  array<int, array{sites: array<int, array<string, mixed>>}>  $paginatedByOffset
 */
function fakeClassicSitesEndpoint(array $unpaginatedSites, array $paginatedByOffset = []): void
{
    Http::fake(function (Request $request) use ($unpaginatedSites, $paginatedByOffset) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        if (isset($query['limit'])) {
            $offset = (int) ($query['offset'] ?? 0);
            $payload = $paginatedByOffset[$offset] ?? ['sites' => []];

            return Http::response($payload, 200);
        }

        return Http::response(['sites' => $unpaginatedSites], 200);
    });
}

function makeSiteRecords(int $count, int $startId = 1): array
{
    $sites = [];
    for ($i = 0; $i < $count; $i++) {
        $id = $startId + $i;
        $sites[] = [
            'site_id' => $id,
            'site_name' => "Site{$id}",
        ];
    }

    return $sites;
}

it('returns token error when classic bearer token cannot be obtained', function () {
    $helper = makeClassicSitesHelper(tokenOk: false);

    expect($helper->classic_collect_all_sites())->toBe([
        'error' => 'failed to get access token from central.',
    ]);

    Http::assertNothingSent();
});

it('returns error when central responds with http failure on paginated request', function () {
    Http::fake([
        '*central/v2/sites*' => Http::response(['detail' => 'nope'], 500),
    ]);

    $helper = makeClassicSitesHelper();

    expect($helper->classic_collect_all_sites())->toBe([
        'error' => 'Could not load sites from Central.',
    ]);
});

it('collect_all_sites matches classic_get_sites for a single-page inventory', function () {
    $sites = [
        ['site_id' => 1, 'site_name' => 'Alpha', 'timezone' => 'US/Eastern', 'devices' => []],
        ['site_id' => 2, 'site_name' => 'Bravo', 'timezone' => 'US/Pacific', 'devices' => ['SN1']],
        ['site_id' => 3, 'site_name' => 'Charlie', 'timezone' => 'UTC', 'devices' => []],
    ];

    fakeClassicSitesEndpoint($sites, [
        0 => ['sites' => $sites],
        100 => ['sites' => []],
    ]);

    $helper = makeClassicSitesHelper();

    expect(sitesKeyedById(sitesFromCollectAll($helper)))->toEqual(sitesKeyedById(sitesFromClassicGetSites($helper)));
});

it('collect_all_sites matches classic_get_sites for a multi-page inventory', function () {
    $allSites = makeSiteRecords(150);
    $pageOne = array_slice($allSites, 0, 100);
    $pageTwo = array_slice($allSites, 100);

    fakeClassicSitesEndpoint($allSites, [
        0 => ['sites' => $pageOne],
        100 => ['sites' => $pageTwo],
        200 => ['sites' => []],
    ]);

    $helper = makeClassicSitesHelper();

    $collected = sitesFromCollectAll($helper);
    expect($collected)->toHaveCount(150);
    expect(sitesKeyedById($collected))->toEqual(sitesKeyedById(sitesFromClassicGetSites($helper)));

    Http::assertSent(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return isset($query['limit']) && (int) $query['limit'] === 100 && (int) $query['offset'] === 0;
    });
    Http::assertSent(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return isset($query['limit']) && (int) $query['limit'] === 100 && (int) $query['offset'] === 100;
    });
    Http::assertSent(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return isset($query['limit']) && (int) $query['limit'] === 100 && (int) $query['offset'] === 200;
    });
});

it('keeps the last occurrence when the same site_id appears on multiple pages', function () {
    $unpaginated = [
        ['site_id' => 10, 'site_name' => 'FinalName', 'revision' => 2],
    ];

    fakeClassicSitesEndpoint($unpaginated, [
        0 => ['sites' => [['site_id' => 10, 'site_name' => 'StaleName', 'revision' => 1]]],
        100 => ['sites' => [['site_id' => 10, 'site_name' => 'FinalName', 'revision' => 2]]],
        200 => ['sites' => []],
    ]);

    $helper = makeClassicSitesHelper();
    $collected = sitesFromCollectAll($helper);

    expect($collected)->toHaveCount(1)
        ->and($collected[0])->toEqual([
            'site_id' => 10,
            'site_name' => 'FinalName',
            'revision' => 2,
        ]);
});

it('continues pagination when a page has only entries without site_id', function () {
    $siteA = ['site_id' => 1, 'site_name' => 'Alpha'];
    $siteB = ['site_id' => 2, 'site_name' => 'Bravo'];
    $siteC = ['site_id' => 3, 'site_name' => 'Charlie'];
    $unpaginated = [$siteA, $siteB, $siteC];

    fakeClassicSitesEndpoint($unpaginated, [
        0 => ['sites' => [['site_name' => 'noise']]],
        100 => ['sites' => [$siteA, $siteB, $siteC]],
        200 => ['sites' => []],
    ]);

    $helper = makeClassicSitesHelper();

    expect(sitesKeyedById(sitesFromCollectAll($helper)))->toEqual(sitesKeyedById(sitesFromClassicGetSites($helper)));
});

it('continues pagination when a page only contains duplicate site_ids', function () {
    $siteA = ['site_id' => 1, 'site_name' => 'Alpha'];
    $siteB = ['site_id' => 2, 'site_name' => 'Bravo'];
    $siteC = ['site_id' => 3, 'site_name' => 'Charlie'];
    $unpaginated = [$siteA, $siteB, $siteC];

    fakeClassicSitesEndpoint($unpaginated, [
        0 => ['sites' => [$siteA, $siteB]],
        100 => ['sites' => [$siteA]],
        200 => ['sites' => [$siteC]],
        300 => ['sites' => []],
    ]);

    $helper = makeClassicSitesHelper();

    expect(sitesKeyedById(sitesFromCollectAll($helper)))->toEqual(sitesKeyedById($unpaginated));
});
