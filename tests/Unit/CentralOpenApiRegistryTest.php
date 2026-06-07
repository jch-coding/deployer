<?php

use App\Services\CentralOpenApiRegistry;

beforeEach(function () {
    app(CentralOpenApiRegistry::class)->clearCache();
});

test('registry loads configuration health operations', function () {
    $registry = app(CentralOpenApiRegistry::class);

    expect($registry->hasOperation('getActiveIssues'))->toBeTrue()
        ->and($registry->hasOperation('getConfigHealthDevices'))->toBeTrue();

    $operation = $registry->operation('getActiveIssues');

    expect($operation['method'])->toBe('GET')
        ->and($operation['path'])->toBe('/network-config/v1alpha1/config-health/active-issue')
        ->and($operation['parameters'][0]['name'])->toBe('serial');
});

test('registry throws for unknown operation', function () {
    app(CentralOpenApiRegistry::class)->operation('notARealOperation');
})->throws(InvalidArgumentException::class);

test('registry groups tags', function () {
    $tags = app(CentralOpenApiRegistry::class)->tags();

    expect($tags)->not->toBeEmpty()
        ->and(collect($tags)->pluck('name'))->toContain('Configuration Health');
});

test('registry loads high availability get endpoints', function () {
    $registry = app(CentralOpenApiRegistry::class);

    expect($registry->hasOperation('readStacks'))->toBeTrue()
        ->and($registry->hasOperation('readVsxProfiles'))->toBeTrue()
        ->and($registry->hasOperation('readVsfTemplates'))->toBeTrue()
        ->and($registry->hasOperation('readGatewayClusters'))->toBeTrue();

    $stack = $registry->operation('readStacks');

    expect($stack['method'])->toBe('GET')
        ->and($stack['path'])->toBe('/network-config/v1alpha1/stacks')
        ->and(collect($stack['parameters'])->pluck('name'))->toContain('view-type', 'scope-id');
});

test('registry loads interfaces get endpoints', function () {
    $registry = app(CentralOpenApiRegistry::class);

    expect($registry->hasOperation('readApPortProfiles'))->toBeTrue()
        ->and($registry->hasOperation('readEthernetInterfaces'))->toBeTrue()
        ->and($registry->hasOperation('readSwPortProfiles'))->toBeTrue()
        ->and($registry->hasOperation('readVlanInterfaces'))->toBeTrue()
        ->and($registry->hasOperation('readPortchannels'))->toBeTrue();

    $apPortProfiles = $registry->operation('readApPortProfiles');

    expect($apPortProfiles['method'])->toBe('GET')
        ->and($apPortProfiles['path'])->toBe('/network-config/v1alpha1/ap-port-profiles')
        ->and($apPortProfiles['tags'])->toContain('Ap Port Profile')
        ->and($apPortProfiles['reference_url'])->toBe('https://developer.arubanetworks.com/new-central-config/reference/readapportprofiles')
        ->and(collect($apPortProfiles['parameters'])->pluck('name'))->toContain('view-type', 'scope-id');

    $tags = collect($registry->tags())->pluck('name');

    expect($tags)->toContain('Ap Port Profile', 'Interface Ethernet', 'Sw Port Profile');
});
