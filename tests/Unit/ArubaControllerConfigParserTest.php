<?php

use App\Services\ArubaControllerConfigParser;

it('maps vlan names by dropping first three characters and prefixing WCD_', function () {
    expect(ArubaControllerConfigParser::mapVlanName('DAYKIT'))->toBe('WCD_KIT')
        ->and(ArubaControllerConfigParser::mapVlanName('DAYAGV'))->toBe('WCD_AGV')
        ->and(ArubaControllerConfigParser::mapVlanName('DAYWCD'))->toBe('WCD_WLAN')
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

it('builds wlan profile body for DAYKIT with mapped vlan', function () {
    $content = file_get_contents(base_path('tests/fixtures/daytona_config.txt'));
    $parser = new ArubaControllerConfigParser;
    $profiles = $parser->parse($content)[0]['wlan_profiles'];

    $daykit = collect($profiles)->firstWhere('ssid_profile_name', 'DAYKIT');

    expect($daykit)->not->toBeNull()
        ->and($daykit['ssid_profile_name'])->toBe('DAYKIT')
        ->and($daykit['raw_vlan'])->toBe('DAYKIT')
        ->and($daykit['vlan_name'])->toBe('WCD_KIT')
        ->and($daykit['body']['essid'])->toBe(['name' => 'DAYKIT'])
        ->and($daykit['body']['personal-security']['wpa-passphrase'])->toBe('xzsawqerdfcvnbhgyt')
        ->and($daykit['body']['vlan-name'])->toBe('WCD_KIT')
        ->and($daykit['body']['ssid'])->toBe('DAYKIT')
        ->and($daykit['body']['high-throughput'])->toBe(['enable' => true, 'very-high-throughput' => true])
        ->and($daykit['body']['high-efficiency'])->toBe(['enable' => true])
        ->and($daykit['body'])->not->toHaveKey('internal-auth-server')
        ->and($daykit['body'])->not->toHaveKey('g-legacy-rates')
        ->and($daykit['body']['a-legacy-rates']['basic-rates'])->toBe(['RATE_12MB', 'RATE_24MB'])
        ->and($daykit['body']['a-legacy-rates']['tx-rates'])->toBe(['RATE_12MB', 'RATE_18MB', 'RATE_24MB', 'RATE_36MB', 'RATE_48MB', 'RATE_54MB']);
});

it('builds wlan profile body for DAYRF with both band legacy rates', function () {
    $content = file_get_contents(base_path('tests/fixtures/daytona_config.txt'));
    $parser = new ArubaControllerConfigParser;
    $profiles = $parser->parse($content)[0]['wlan_profiles'];

    $dayrf = collect($profiles)->firstWhere('ssid_profile_name', 'DAYRF');

    expect($dayrf)->not->toBeNull()
        ->and($dayrf['body'])->toHaveKey('g-legacy-rates')
        ->and($dayrf['body'])->toHaveKey('a-legacy-rates')
        ->and($dayrf['body']['g-legacy-rates']['basic-rates'])->toBe(['RATE_12MB', 'RATE_24MB'])
        ->and($dayrf['body']['g-legacy-rates']['tx-rates'])->toBe(['RATE_12MB', 'RATE_18MB', 'RATE_24MB', 'RATE_36MB', 'RATE_48MB', 'RATE_54MB']);
});

it('omits legacy rates from WCD_PI profile when config has no rates', function () {
    $content = file_get_contents(base_path('tests/fixtures/daytona_config.txt'));
    $parser = new ArubaControllerConfigParser;
    $profiles = $parser->parse($content)[0]['wlan_profiles'];

    $wcdPi = collect($profiles)->firstWhere('ssid_profile_name', 'WCD_PI');

    expect($wcdPi)->not->toBeNull()
        ->and($wcdPi['body'])->not->toHaveKey('g-legacy-rates')
        ->and($wcdPi['body'])->not->toHaveKey('a-legacy-rates');
});

it('maps DAYWCD vlan to WCD_WLAN for DAYWCD profile', function () {
    $content = file_get_contents(base_path('tests/fixtures/daytona_config.txt'));
    $parser = new ArubaControllerConfigParser;
    $profiles = $parser->parse($content)[0]['wlan_profiles'];

    $daywcd = collect($profiles)->firstWhere('ssid_profile_name', 'DAYWCD');

    expect($daywcd)->not->toBeNull()
        ->and($daywcd['raw_vlan'])->toBe('DAYWCD')
        ->and($daywcd['vlan_name'])->toBe('WCD_WLAN')
        ->and($daywcd['body']['vlan-name'])->toBe('WCD_WLAN');
});

it('keeps WCD_PI vlan unchanged for WCD_PI profile', function () {
    $content = file_get_contents(base_path('tests/fixtures/daytona_config.txt'));
    $parser = new ArubaControllerConfigParser;
    $profiles = $parser->parse($content)[0]['wlan_profiles'];

    $wcdPi = collect($profiles)->firstWhere('ssid_profile_name', 'WCD_PI');

    expect($wcdPi)->not->toBeNull()
        ->and($wcdPi['raw_vlan'])->toBe('WCD_PI')
        ->and($wcdPi['vlan_name'])->toBe('WCD_PI')
        ->and($wcdPi['body']['vlan-name'])->toBe('WCD_PI');
});

it('includes partial wlan profiles with warnings', function () {
    $content = file_get_contents(base_path('tests/fixtures/daytona_config.txt'));
    $parser = new ArubaControllerConfigParser;
    $profiles = $parser->parse($content)[0]['wlan_profiles'];

    $tjs = collect($profiles)->firstWhere('ssid_profile_name', 'TJs');

    expect($tjs)->not->toBeNull()
        ->and($tjs['warnings'])->toContain('Missing wpa-passphrase');
});
