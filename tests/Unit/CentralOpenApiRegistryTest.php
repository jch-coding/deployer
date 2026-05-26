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
