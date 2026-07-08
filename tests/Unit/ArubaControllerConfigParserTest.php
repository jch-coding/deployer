<?php

use App\Services\ArubaControllerConfigParser;

it('maps vlan names by dropping first three characters and prefixing WCD_', function () {
    expect(ArubaControllerConfigParser::mapVlanName('DAYKIT'))->toBe('WCD_KIT')
        ->and(ArubaControllerConfigParser::mapVlanName('DAYAGV'))->toBe('WCD_AGV')
        ->and(ArubaControllerConfigParser::mapVlanName('WCD_PI'))->toBe('WCD_PI');
});

it('parses daytona config fixture with expected ap count', function () {
    $content = file_get_contents(base_path('tests/fixtures/daytona_config.txt'));
    $parser = new ArubaControllerConfigParser;
    $results = $parser->parse($content);

    expect($results)->toHaveCount(1)
        ->and($results[0]['controller_name'])->toBe('DAY-HUB-WLC1')
        ->and($results[0]['devices'])->toHaveCount(106);
});

it('parses ap device fields from daytona fixture', function () {
    $content = file_get_contents(base_path('tests/fixtures/daytona_config.txt'));
    $parser = new ArubaControllerConfigParser;
    $devices = $parser->parse($content)[0]['devices'];

    $first = collect($devices)->firstWhere('name', 'DAY-H-IDF02-021');

    expect($first)->toMatchArray([
        'name' => 'DAY-H-IDF02-021',
        'mac' => '50:e4:e0:c3:bb:6a',
        'serial' => 'PHS2KD006J',
    ]);
});

it('aggregates lldp neighbors by switch', function () {
    $content = file_get_contents(base_path('tests/fixtures/daytona_config.txt'));
    $parser = new ArubaControllerConfigParser;
    $neighbors = $parser->parse($content)[0]['lldp_neighbors'];

    expect($neighbors)->not->toBeEmpty();

    $idf6 = collect($neighbors)->firstWhere('switch', 'DAY-IDF6-SW1.traderjoes.com');

    expect($idf6)->not->toBeNull()
        ->and($idf6['ports'])->toContain('Te1/0/42')
        ->and($idf6['ports'])->toContain('Te1/0/41');
});

it('builds wlan profile body for DAYKIT_ssid_prof with mapped vlan', function () {
    $content = file_get_contents(base_path('tests/fixtures/daytona_config.txt'));
    $parser = new ArubaControllerConfigParser;
    $profiles = $parser->parse($content)[0]['wlan_profiles'];

    $daykit = collect($profiles)->firstWhere('ssid_profile_name', 'DAYKIT_ssid_prof');

    expect($daykit)->not->toBeNull()
        ->and($daykit['raw_vlan'])->toBe('DAYKIT')
        ->and($daykit['vlan_name'])->toBe('WCD_KIT')
        ->and($daykit['body']['essid'])->toBe(['name' => 'DAYKIT'])
        ->and($daykit['body']['personal-security']['wpa-passphrase'])->toBe('xzsawqerdfcvnbhgyt')
        ->and($daykit['body']['vlan-name'])->toBe('WCD_KIT')
        ->and($daykit['body']['ssid'])->toBe('DAYKIT_ssid_prof')
        ->and($daykit['body']['a-legacy-rates']['basic-rates'])->toBe(['RATE_12MB', 'RATE_24MB'])
        ->and($daykit['body']['a-legacy-rates']['tx-rates'])->toBe(['RATE_12MB', 'RATE_18MB', 'RATE_24MB', 'RATE_36MB', 'RATE_48MB', 'RATE_54MB']);
});

it('keeps WCD_PI vlan unchanged for WCD_PI_ssid_prof', function () {
    $content = file_get_contents(base_path('tests/fixtures/daytona_config.txt'));
    $parser = new ArubaControllerConfigParser;
    $profiles = $parser->parse($content)[0]['wlan_profiles'];

    $wcdPi = collect($profiles)->firstWhere('ssid_profile_name', 'WCD_PI_ssid_prof');

    expect($wcdPi)->not->toBeNull()
        ->and($wcdPi['raw_vlan'])->toBe('WCD_PI')
        ->and($wcdPi['vlan_name'])->toBe('WCD_PI')
        ->and($wcdPi['body']['vlan-name'])->toBe('WCD_PI');
});

it('includes partial wlan profiles with warnings', function () {
    $content = file_get_contents(base_path('tests/fixtures/daytona_config.txt'));
    $parser = new ArubaControllerConfigParser;
    $profiles = $parser->parse($content)[0]['wlan_profiles'];

    $tjs = collect($profiles)->firstWhere('ssid_profile_name', 'TJs-SSID-profile');

    expect($tjs)->not->toBeNull()
        ->and($tjs['warnings'])->toContain('Missing wpa-passphrase');
});
