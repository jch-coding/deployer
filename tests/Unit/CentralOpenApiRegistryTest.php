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
        ->and(collect($apPortProfiles['parameters'])->pluck('name'))->toContain('view-type', 'scope-id')
        ->and($apPortProfiles['requires_body'])->toBeFalse();

    $tags = collect($registry->tags())->pluck('name');

    expect($tags)->toContain('Ap Port Profile', 'Interface Ethernet', 'Sw Port Profile');
});

test('registry loads write endpoints for named resources', function () {
    $registry = app(CentralOpenApiRegistry::class);

    expect($registry->hasOperation('createPortchannel'))->toBeTrue()
        ->and($registry->hasOperation('updatePortchannel'))->toBeTrue()
        ->and($registry->hasOperation('deletePortchannel'))->toBeTrue()
        ->and($registry->hasOperation('createStack'))->toBeTrue()
        ->and($registry->hasOperation('updateVsx'))->toBeTrue()
        ->and($registry->hasOperation('deleteVsx'))->toBeTrue();

    $createPortchannel = $registry->operation('createPortchannel');

    expect($createPortchannel['method'])->toBe('POST')
        ->and($createPortchannel['path'])->toBe('/network-config/v1alpha1/portchannels/{name}')
        ->and($createPortchannel['requires_body'])->toBeTrue()
        ->and(collect($createPortchannel['parameters'])->pluck('name'))->toContain('name');

    $deletePortchannel = $registry->operation('deletePortchannel');

    expect($deletePortchannel['method'])->toBe('DELETE')
        ->and($deletePortchannel['requires_body'])->toBeFalse();
});

test('registry loads singleton patch endpoints', function () {
    $registry = app(CentralOpenApiRegistry::class);

    $updateLacp = $registry->operation('updateLacp');

    expect($updateLacp['method'])->toBe('PATCH')
        ->and($updateLacp['path'])->toBe('/network-config/v1alpha1/lacp')
        ->and($updateLacp['requires_body'])->toBeTrue();
});

test('registry loads wireless get endpoints', function () {
    $registry = app(CentralOpenApiRegistry::class);

    expect($registry->hasOperation('readRadios'))->toBeTrue()
        ->and($registry->hasOperation('readRadiosProfileById'))->toBeTrue()
        ->and($registry->hasOperation('readWlanSsids'))->toBeTrue()
        ->and($registry->hasOperation('readWlanSsidsWlanSsidById'))->toBeTrue()
        ->and($registry->hasOperation('createWlanSsidsWlanSsidById'))->toBeTrue()
        ->and($registry->hasOperation('updateRadiosProfileById'))->toBeTrue()
        ->and($registry->hasOperation('deleteRadiosProfileById'))->toBeTrue();

    $wlanSsids = $registry->operation('readWlanSsids');

    expect($wlanSsids['method'])->toBe('GET')
        ->and($wlanSsids['path'])->toBe('/network-config/v1alpha1/wlan-ssids')
        ->and($wlanSsids['tags'])->toContain('Wlan Ssid')
        ->and($wlanSsids['reference_url'])->toBe('https://developer.arubanetworks.com/new-central-config/reference/readwlanssids')
        ->and(collect($wlanSsids['parameters'])->pluck('name'))->toContain('view-type', 'scope-id')
        ->and($wlanSsids['requires_body'])->toBeFalse();

    $createWlan = $registry->operation('createWlanSsidsWlanSsidById');

    expect($createWlan['method'])->toBe('POST')
        ->and($createWlan['path'])->toBe('/network-config/v1alpha1/wlan-ssids/{ssid}')
        ->and($createWlan['requires_body'])->toBeTrue()
        ->and(collect($createWlan['parameters'])->pluck('name'))->toContain('ssid');

    $tags = collect($registry->tags())->pluck('name');

    expect($tags)->toContain('Radio', 'Wlan Ssid');
});

test('registry loads roles and policy endpoints', function () {
    $registry = app(CentralOpenApiRegistry::class);

    expect($registry->hasOperation('readRoles'))->toBeTrue()
        ->and($registry->hasOperation('readRolesRoleByID'))->toBeTrue()
        ->and($registry->hasOperation('readPolicies'))->toBeTrue()
        ->and($registry->hasOperation('readRoleAcls'))->toBeTrue()
        ->and($registry->hasOperation('readObjectGroups'))->toBeTrue()
        ->and($registry->hasOperation('readPolicyGroups'))->toBeTrue()
        ->and($registry->hasOperation('createRolesRoleByID'))->toBeTrue()
        ->and($registry->hasOperation('updatePoliciesPolicyByID'))->toBeTrue()
        ->and($registry->hasOperation('deleteRoleAclsAclByID'))->toBeTrue();

    $roles = $registry->operation('readRoles');

    expect($roles['method'])->toBe('GET')
        ->and($roles['path'])->toBe('/network-config/v1alpha1/roles')
        ->and($roles['tags'])->toContain('Role')
        ->and($roles['reference_url'])->toBe('https://developer.arubanetworks.com/new-central-config/reference/readroles')
        ->and(collect($roles['parameters'])->pluck('name'))->toContain('view-type', 'scope-id')
        ->and($roles['requires_body'])->toBeFalse();

    $createRole = $registry->operation('createRolesRoleByID');

    expect($createRole['method'])->toBe('POST')
        ->and($createRole['path'])->toBe('/network-config/v1alpha1/roles/{name}')
        ->and($createRole['requires_body'])->toBeTrue()
        ->and(collect($createRole['parameters'])->pluck('name'))->toContain('name');

    $tags = collect($registry->tags())->pluck('name');

    expect($tags)->toContain('Role', 'Policy', 'Role Acl', 'Object Group', 'Policy Group');
});

test('registry loads auth server endpoints', function () {
    $registry = app(CentralOpenApiRegistry::class);

    expect($registry->hasOperation('readAuthServers'))->toBeTrue()
        ->and($registry->hasOperation('readAuthServersAuthServerByID'))->toBeTrue()
        ->and($registry->hasOperation('createAuthServersAuthServerByID'))->toBeTrue()
        ->and($registry->hasOperation('updateAuthServersAuthServerByID'))->toBeTrue()
        ->and($registry->hasOperation('deleteAuthServersAuthServerByID'))->toBeTrue();

    $authServers = $registry->operation('readAuthServers');

    expect($authServers['method'])->toBe('GET')
        ->and($authServers['path'])->toBe('/network-config/v1alpha1/auth-servers')
        ->and($authServers['tags'])->toContain('Auth Server')
        ->and($authServers['reference_url'])->toBe('https://developer.arubanetworks.com/new-central-config/reference/readauthservers')
        ->and(collect($authServers['parameters'])->pluck('name'))->toContain('view-type', 'scope-id')
        ->and($authServers['requires_body'])->toBeFalse();

    $createAuthServer = $registry->operation('createAuthServersAuthServerByID');

    expect($createAuthServer['method'])->toBe('POST')
        ->and($createAuthServer['path'])->toBe('/network-config/v1alpha1/auth-servers/{name}')
        ->and($createAuthServer['requires_body'])->toBeTrue()
        ->and(collect($createAuthServer['parameters'])->pluck('name'))->toContain('name', 'object-type', 'scope-id', 'device-function');

    $updateAuthServer = $registry->operation('updateAuthServersAuthServerByID');

    expect($updateAuthServer['method'])->toBe('PATCH')
        ->and($updateAuthServer['requires_body'])->toBeTrue();

    $deleteAuthServer = $registry->operation('deleteAuthServersAuthServerByID');

    expect($deleteAuthServer['method'])->toBe('DELETE')
        ->and($deleteAuthServer['requires_body'])->toBeFalse();

    $tags = collect($registry->tags())->pluck('name');

    expect($tags)->toContain('Auth Server');
});

test('registry loads alias endpoints', function () {
    $registry = app(CentralOpenApiRegistry::class);

    expect($registry->hasOperation('readAliases'))->toBeTrue()
        ->and($registry->hasOperation('readAliasesAliasByID'))->toBeTrue()
        ->and($registry->hasOperation('createAliasesAliasByID'))->toBeTrue()
        ->and($registry->hasOperation('updateAliasesAliasByID'))->toBeTrue()
        ->and($registry->hasOperation('deleteAliasesAliasByID'))->toBeTrue();

    $aliases = $registry->operation('readAliases');

    expect($aliases['method'])->toBe('GET')
        ->and($aliases['path'])->toBe('/network-config/v1alpha1/aliases')
        ->and($aliases['tags'])->toContain('Alias')
        ->and($aliases['reference_url'])->toBe('https://developer.arubanetworks.com/new-central-config/reference/readaliases')
        ->and(collect($aliases['parameters'])->pluck('name'))->toContain('view-type', 'scope-id')
        ->and($aliases['requires_body'])->toBeFalse();

    $createAlias = $registry->operation('createAliasesAliasByID');

    expect($createAlias['method'])->toBe('POST')
        ->and($createAlias['path'])->toBe('/network-config/v1alpha1/aliases/{name}')
        ->and($createAlias['requires_body'])->toBeTrue()
        ->and(collect($createAlias['parameters'])->pluck('name'))->toContain('name', 'object-type', 'scope-id', 'device-function');

    $updateAlias = $registry->operation('updateAliasesAliasByID');

    expect($updateAlias['method'])->toBe('PATCH')
        ->and($updateAlias['requires_body'])->toBeTrue();

    $deleteAlias = $registry->operation('deleteAliasesAliasByID');

    expect($deleteAlias['method'])->toBe('DELETE')
        ->and($deleteAlias['requires_body'])->toBeFalse();

    $tags = collect($registry->tags())->pluck('name');

    expect($tags)->toContain('Alias');
});
