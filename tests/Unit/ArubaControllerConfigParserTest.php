<?php

use App\Services\ArubaControllerConfigParser;

it('maps vlan names by dropping first three characters and prefixing WCD_', function () {
    expect(ArubaControllerConfigParser::mapVlanName('DAYKIT'))->toBe('WCD_KIT')
        ->and(ArubaControllerConfigParser::mapVlanName('DAYAGV'))->toBe('WCD_AGV')
        ->and(ArubaControllerConfigParser::mapVlanName('DAYWCD'))->toBe('WCD_WLAN')
        ->and(ArubaControllerConfigParser::mapVlanName('MINFZNWCD'))->toBe('WCD_WLAN')
        ->and(ArubaControllerConfigParser::mapVlanName('MINFZNTM'))->toBe('WCD_TM')
        ->and(ArubaControllerConfigParser::mapVlanName('WCD_PI'))->toBe('WCD_PI')
        ->and(ArubaControllerConfigParser::mapVlanName('WCD_WLAN'))->toBe('WCD_WLAN')
        ->and(ArubaControllerConfigParser::mapVlanName('WCD_KIT'))->toBe('WCD_KIT');
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

it('parses radius auth servers and pairs rfc-3576 for CoA', function () {
    $content = file_get_contents(base_path('tests/fixtures/daytona_config.txt'));
    $parser = new ArubaControllerConfigParser;
    $servers = $parser->parse($content)[0]['auth_servers'];

    $ecppm = collect($servers)->firstWhere('name', 'ECPPM');
    $wcppm = collect($servers)->firstWhere('name', 'WCPPM');
    $dalnet = collect($servers)->firstWhere('name', 'dalnet52.traderjoes.com');

    expect($ecppm)->not->toBeNull()
        ->and($ecppm['host'])->toBe('10.232.188.4')
        ->and($ecppm['has_coa'])->toBeTrue()
        ->and($ecppm['body'])->toMatchArray([
            'auth-server-address' => '10.232.188.4',
            'enable' => true,
            'name' => 'ECPPM',
            'shared-secret-config' => [
                'plaintext-value' => 'r3@LcH0c0L@t315tH3B35t',
                'secret-type' => 'PLAIN_TEXT',
            ],
            'type' => 'RADIUS',
            'dynamic-authorization-enable' => true,
            'radius-server-mode' => 'AUTH_AND_COA',
        ])
        ->and($wcppm)->not->toBeNull()
        ->and($wcppm['has_coa'])->toBeTrue()
        ->and($wcppm['body']['radius-server-mode'])->toBe('AUTH_AND_COA')
        ->and($dalnet)->not->toBeNull()
        ->and($dalnet['has_coa'])->toBeFalse()
        ->and($dalnet['warnings'])->toContain('Missing key')
        ->and($dalnet['body'])->not->toHaveKey('dynamic-authorization-enable');
});

it('merges and deduplicates auth servers for paired controllers', function () {
    $content = <<<'CONFIG'
(DAY-HUB-WLC1) #show ap database long
AP Database
-----------
Name             Group        AP Type  IP Address    Status             Flags  Switch IP   Standby IP  Wired MAC Address  Serial #    Port  FQLN  Outer IP  User
----             -----        -------  ----------    ------             -----  ---------   ----------  -----------------  --------    ----  ----  --------  ----
AP-FIRST-001     default      514      10.1.1.1      Up 1d:0h:0m:0s     2      10.1.1.2    10.1.1.3    00:11:22:33:44:55  SERFIRST1   N/A   N/A   N/A

(DAY-HUB-WLC1) #show running-config
aaa authentication-server radius "ECPPM"
    host "10.232.188.4"
    key "shared-secret"
!
aaa rfc-3576-server "10.232.188.4"
    key "shared-secret"
!

(DAY-HUB-WLC2) #show ap database long
AP Database
-----------
Name             Group        AP Type  IP Address    Status             Flags  Switch IP   Standby IP  Wired MAC Address  Serial #    Port  FQLN  Outer IP  User
----             -----        -------  ----------    ------             -----  ---------   ----------  -----------------  --------    ----  ----  --------  ----
AP-SECOND-001    default      514      10.2.2.1      Up 1d:0h:0m:0s     2      10.2.2.2    10.2.2.3    aa:bb:cc:dd:ee:ff  SERSECOND1  N/A   N/A   N/A

(DAY-HUB-WLC2) #show running-config
aaa authentication-server radius "ECPPM"
    host "10.232.188.4"
    key "shared-secret"
!
aaa authentication-server radius "WCPPM"
    host "10.236.188.4"
    key "other-secret"
!
aaa rfc-3576-server "10.232.188.4"
    key "shared-secret"
!
CONFIG;

    $parser = new ArubaControllerConfigParser;
    $servers = $parser->parse($content)[0]['auth_servers'];

    expect($servers)->toHaveCount(2)
        ->and(collect($servers)->pluck('name')->all())->toBe(['ECPPM', 'WCPPM'])
        ->and(collect($servers)->firstWhere('name', 'ECPPM')['has_coa'])->toBeTrue()
        ->and(collect($servers)->firstWhere('name', 'WCPPM')['has_coa'])->toBeFalse();
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
        ->and($daykit['body']['forward-mode'])->toBe('FORWARD_MODE_BRIDGE')
        ->and($daykit['body']['broadcast-filter-ipv4'])->toBe('BCAST_FILTER_ARP')
        ->and($daykit['body']['extremely-high-throughput'])->toBe(['enable' => false, 'mlo' => false])
        ->and($daykit['body']['client-isolation'])->toBeFalse()
        ->and($daykit['body'])->not->toHaveKey('internal-auth-server')
        ->and($daykit['body']['g-legacy-rates'])->toBe([
            'basic-rates' => ['RATE_12MB', 'RATE_24MB'],
            'tx-rates' => ['RATE_12MB', 'RATE_18MB', 'RATE_24MB', 'RATE_36MB', 'RATE_48MB', 'RATE_54MB'],
        ])
        ->and($daykit['body']['rf-band'])->toBe('5GHZ')
        ->and($daykit['body']['advertise-apname'])->toBeTrue()
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
        ->and($dayrf['body']['rf-band'])->toBe('24GHZ_5GHZ')
        ->and($dayrf['body']['advertise-apname'])->toBeTrue()
        ->and($dayrf['body']['g-legacy-rates']['basic-rates'])->toBe(['RATE_12MB', 'RATE_24MB'])
        ->and($dayrf['body']['g-legacy-rates']['tx-rates'])->toBe(['RATE_12MB', 'RATE_18MB', 'RATE_24MB', 'RATE_36MB', 'RATE_48MB', 'RATE_54MB']);
});

it('applies default legacy rates to WCD_PI profile when config has no rates', function () {
    $content = file_get_contents(base_path('tests/fixtures/daytona_config.txt'));
    $parser = new ArubaControllerConfigParser;
    $profiles = $parser->parse($content)[0]['wlan_profiles'];

    $wcdPi = collect($profiles)->firstWhere('ssid_profile_name', 'WCD_PI');

    expect($wcdPi)->not->toBeNull()
        ->and($wcdPi['body']['g-legacy-rates'])->toBe([
            'basic-rates' => ['RATE_12MB', 'RATE_24MB'],
            'tx-rates' => ['RATE_12MB', 'RATE_18MB', 'RATE_24MB', 'RATE_36MB', 'RATE_48MB', 'RATE_54MB'],
        ])
        ->and($wcdPi['body']['a-legacy-rates'])->toBe([
            'basic-rates' => ['RATE_12MB', 'RATE_24MB'],
            'tx-rates' => ['RATE_24MB', 'RATE_36MB', 'RATE_48MB', 'RATE_54MB'],
        ]);
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

it('builds enterprise wlan profile for TJs without personal-security', function () {
    $content = file_get_contents(base_path('tests/fixtures/daytona_config.txt'));
    $parser = new ArubaControllerConfigParser;
    $profiles = $parser->parse($content)[0]['wlan_profiles'];

    $tjs = collect($profiles)->firstWhere('ssid_profile_name', 'TJs');

    expect($tjs)->not->toBeNull()
        ->and($tjs['body']['opmode'])->toBe('WPA3_AES_CCM_128')
        ->and($tjs['body']['dot1x'])->toBeTrue()
        ->and($tjs['body']['auth-server-group'])->toBe('CPPM-West-preferred-svr-group')
        ->and($tjs['body'])->not->toHaveKey('personal-security')
        ->and($tjs['body'])->not->toHaveKey('acct-server-group')
        ->and($tjs['body'])->not->toHaveKey('radius-accounting')
        ->and($tjs['warnings'])->not->toContain('Missing wpa-passphrase')
        ->and($tjs['warnings'])->toContain('Missing vlan from virtual-ap');
});

it('parses server groups and associates enterprise essids', function () {
    $content = file_get_contents(base_path('tests/fixtures/daytona_config.txt'));
    $parser = new ArubaControllerConfigParser;
    $groups = $parser->parse($content)[0]['server_groups'];

    $west = collect($groups)->firstWhere('name', 'CPPM-West-preferred-svr-group');

    expect($west)->not->toBeNull()
        ->and($west['servers'])->toBe([
            ['server-name' => 'WCPPM', 'position' => 1],
            ['server-name' => 'ECPPM', 'position' => 2],
        ])
        ->and($west['body'])->toMatchArray([
            'name' => 'CPPM-West-preferred-svr-group',
            'type' => 'RADIUS',
            'servers' => [
                ['server-name' => 'WCPPM', 'position' => 1],
                ['server-name' => 'ECPPM', 'position' => 2],
            ],
        ])
        ->and($west['associated_essids'])->toBe(['TJs']);
});

it('maps personal opmode wpa2-psk-aes to WPA2_PERSONAL', function () {
    $content = file_get_contents(base_path('tests/fixtures/daytona_config.txt'));
    $parser = new ArubaControllerConfigParser;
    $profiles = $parser->parse($content)[0]['wlan_profiles'];

    $daykit = collect($profiles)->firstWhere('ssid_profile_name', 'DAYKIT');

    expect($daykit['body']['opmode'])->toBe('WPA2_PERSONAL')
        ->and($daykit['body'])->toHaveKey('personal-security');
});

it('maps opensystem to OPEN without personal-security', function () {
    $content = <<<'CONFIG'
(WLC-OPEN) #show ap database long
AP Database
-----------
Name             Group        AP Type  IP Address    Status             Flags  Switch IP   Standby IP  Wired MAC Address  Serial #    Port  FQLN  Outer IP  User
----             -----        -------  ----------    ------             -----  ---------   ----------  -----------------  --------    ----  ----  --------  ----
AP-OPEN-001      default      514      10.1.1.1      Up 1d:0h:0m:0s     2      10.1.1.2    10.1.1.3    00:11:22:33:44:55  SEROPEN001  N/A   N/A   N/A

(WLC-OPEN) #show running-config
wlan ssid-profile "OPEN_ssid_prof"
    essid "GuestOpen"
    opmode opensystem
!
wlan virtual-ap "OPEN"
    vlan DAYKIT
    ssid-profile "OPEN_ssid_prof"
!
CONFIG;

    $parser = new ArubaControllerConfigParser;
    $profile = $parser->parse($content)[0]['wlan_profiles'][0];

    expect($profile['body']['opmode'])->toBe('OPEN')
        ->and($profile['body'])->not->toHaveKey('personal-security')
        ->and($profile['body'])->not->toHaveKey('dot1x')
        ->and($profile['warnings'])->not->toContain('Missing wpa-passphrase');
});

it('maps multi-token enterprise opmode to BOTH_WPA_WPA2_DOT1X with accounting', function () {
    $content = <<<'CONFIG'
(WLC-ENT) #show ap database long
AP Database
-----------
Name             Group        AP Type  IP Address    Status             Flags  Switch IP   Standby IP  Wired MAC Address  Serial #    Port  FQLN  Outer IP  User
----             -----        -------  ----------    ------             -----  ---------   ----------  -----------------  --------    ----  ----  --------  ----
AP-ENT-001       default      514      10.1.1.1      Up 1d:0h:0m:0s     2      10.1.1.2    10.1.1.3    00:11:22:33:44:55  SERENT001   N/A   N/A   N/A

(WLC-ENT) #show running-config
aaa authentication-server radius "RAD1"
    host "10.0.0.1"
    key "secret"
!
aaa server-group "AuthGroup"
    auth-server RAD1 position 1
!
aaa server-group "AcctGroup"
    auth-server RAD1 position 1
!
aaa profile "ENT-aaa"
    dot1x-server-group "AuthGroup"
    radius-accounting "AcctGroup"
!
wlan ssid-profile "ENT_ssid_prof"
    essid "CorpBoth"
    opmode wpa-tkip wpa-aes wpa2-aes
!
wlan virtual-ap "ENT"
    aaa-profile "ENT-aaa"
    vlan DAYKIT
    ssid-profile "ENT_ssid_prof"
!
CONFIG;

    $parser = new ArubaControllerConfigParser;
    $result = $parser->parse($content)[0];
    $profile = collect($result['wlan_profiles'])->firstWhere('ssid_profile_name', 'CorpBoth');
    $authGroup = collect($result['server_groups'])->firstWhere('name', 'AuthGroup');
    $acctGroup = collect($result['server_groups'])->firstWhere('name', 'AcctGroup');

    expect($profile['body']['opmode'])->toBe('BOTH_WPA_WPA2_DOT1X')
        ->and($profile['body']['dot1x'])->toBeTrue()
        ->and($profile['body']['auth-server-group'])->toBe('AuthGroup')
        ->and($profile['body']['acct-server-group'])->toBe('AcctGroup')
        ->and($profile['body']['radius-accounting'])->toBeTrue()
        ->and($profile['body'])->not->toHaveKey('personal-security')
        ->and($authGroup['associated_essids'])->toBe(['CorpBoth'])
        ->and($acctGroup['associated_essids'])->toBe(['CorpBoth']);
});

it('maps wpa3-sae-aes to WPA3_SAE with personal-security', function () {
    $content = <<<'CONFIG'
(WLC-SAE) #show ap database long
AP Database
-----------
Name             Group        AP Type  IP Address    Status             Flags  Switch IP   Standby IP  Wired MAC Address  Serial #    Port  FQLN  Outer IP  User
----             -----        -------  ----------    ------             -----  ---------   ----------  -----------------  --------    ----  ----  --------  ----
AP-SAE-001       default      514      10.1.1.1      Up 1d:0h:0m:0s     2      10.1.1.2    10.1.1.3    00:11:22:33:44:55  SERSAE001   N/A   N/A   N/A

(WLC-SAE) #show running-config
wlan ssid-profile "SAE_ssid_prof"
    essid "SaeSsid"
    wpa-passphrase "sae-passphrase-123"
    opmode wpa3-sae-aes
!
wlan virtual-ap "SAE"
    vlan DAYKIT
    ssid-profile "SAE_ssid_prof"
!
CONFIG;

    $parser = new ArubaControllerConfigParser;
    $profile = $parser->parse($content)[0]['wlan_profiles'][0];

    expect($profile['body']['opmode'])->toBe('WPA3_SAE')
        ->and($profile['body']['personal-security']['wpa-passphrase'])->toBe('sae-passphrase-123');
});

it('parses multiple controller blocks with isolated data', function () {
    $content = <<<'CONFIG'
(WLC-ONE) #show ap database long
AP Database
-----------
Name             Group        AP Type  IP Address    Status             Flags  Switch IP   Standby IP  Wired MAC Address  Serial #    Port  FQLN  Outer IP  User
----             -----        -------  ----------    ------             -----  ---------   ----------  -----------------  --------    ----  ----  --------  ----
AP-ONE-001       default      514      10.1.1.1      Up 1d:0h:0m:0s     2      10.1.1.2    10.1.1.3    00:11:22:33:44:55  SERONE001   N/A   N/A   N/A

(WLC-ONE) #show ap lldp neighbors
AP LLDP Neighbors (Updated every 300 seconds)
---------------------------------------------
AP               Interface  Neighbor  Chassis Name/ID               Port ID   Port Desc  Mgmt. Address  Capabilities
--               ---------  --------  ---------------               -------   ---------  -------------  ------------
AP-ONE-001       bond0      0         SW-ONE.example.com            Te1/0/1   AP         10.1.1.10      B

(WLC-ONE) #show running-config
wlan ssid-profile "ONEKIT_ssid_prof"
    essid "ONEKIT"
    wpa-passphrase "one-passphrase-12345"
    opmode wpa2-psk-aes
!
wlan virtual-ap "ONEKIT"
    vlan ONEKIT
    ssid-profile "ONEKIT_ssid_prof"

(WLC-TWO) #show ap database long
AP Database
-----------
Name             Group        AP Type  IP Address    Status             Flags  Switch IP   Standby IP  Wired MAC Address  Serial #    Port  FQLN  Outer IP  User
----             -----        -------  ----------    ------             -----  ---------   ----------  -----------------  --------    ----  ----  --------  ----
AP-TWO-001       default      514      10.2.2.1      Up 1d:0h:0m:0s     2      10.2.2.2    10.2.2.3    aa:bb:cc:dd:ee:ff  SERTWO001   N/A   N/A   N/A

(WLC-TWO) #show ap lldp neighbors
AP LLDP Neighbors (Updated every 300 seconds)
---------------------------------------------
AP               Interface  Neighbor  Chassis Name/ID               Port ID   Port Desc  Mgmt. Address  Capabilities
--               ---------  --------  ---------------               -------   ---------  -------------  ------------
AP-TWO-001       bond0      0         SW-TWO.example.com            Te2/0/2   AP         10.2.2.10      B

(WLC-TWO) #show running-config
wlan ssid-profile "TWOKIT_ssid_prof"
    essid "TWOKIT"
    wpa-passphrase "two-passphrase-12345"
    opmode wpa2-psk-aes
!
wlan virtual-ap "TWOKIT"
    vlan TWOKIT
    ssid-profile "TWOKIT_ssid_prof"
CONFIG;

    $parser = new ArubaControllerConfigParser;
    $results = $parser->parse($content);

    expect($results)->toHaveCount(2)
        ->and($results[0]['controller_name'])->toBe('WLC-ONE')
        ->and($results[1]['controller_name'])->toBe('WLC-TWO')
        ->and($results[0]['devices'])->toHaveCount(1)
        ->and($results[1]['devices'])->toHaveCount(1)
        ->and($results[0]['devices'][0]['name'])->toBe('AP-ONE-001')
        ->and($results[1]['devices'][0]['name'])->toBe('AP-TWO-001')
        ->and($results[0]['lldp_neighbors'][0]['switch'])->toBe('SW-ONE.example.com')
        ->and($results[1]['lldp_neighbors'][0]['switch'])->toBe('SW-TWO.example.com')
        ->and($results[0]['wlan_profiles'][0]['ssid_profile_name'])->toBe('ONEKIT')
        ->and($results[1]['wlan_profiles'][0]['ssid_profile_name'])->toBe('TWOKIT');
});

it('merges lldp neighbors for paired controllers into the first controller', function () {
    $parser = new ArubaControllerConfigParser;
    $results = $parser->parse(pairedControllerConfig('DAY-HUB-WLC1', 'DAY-HUB-WLC2'));

    expect($results)->toHaveCount(1)
        ->and($results[0]['controller_name'])->toBe('DAY-HUB-WLC1')
        ->and($results[0]['devices'])->toHaveCount(1)
        ->and($results[0]['devices'][0]['name'])->toBe('AP-FIRST-001')
        ->and(collect($results[0]['wlan_profiles'])->pluck('ssid_profile_name')->all())->toBe(['FIRST', 'SECOND'])
        ->and($results[0]['lldp_neighbors'])->toBe([
            [
                'switch' => 'SW-A.example.com',
                'ports' => ['Te1/0/1', 'Te1/0/2'],
            ],
            [
                'switch' => 'SW-B.example.com',
                'ports' => ['Te2/0/1'],
            ],
        ]);
});

it('uses the first controller as primary regardless of pair order', function () {
    $parser = new ArubaControllerConfigParser;
    $results = $parser->parse(pairedControllerConfig('DAY-HUB-WLC2', 'DAY-HUB-WLC1'));

    expect($results)->toHaveCount(1)
        ->and($results[0]['controller_name'])->toBe('DAY-HUB-WLC2')
        ->and($results[0]['devices'][0]['name'])->toBe('AP-FIRST-001')
        ->and($results[0]['wlan_profiles'][0]['ssid_profile_name'])->toBe('FIRST')
        ->and($results[0]['lldp_neighbors'])->toHaveCount(2);
});

it('matches controller pairs case insensitively without pairing the same name', function () {
    $parser = new ArubaControllerConfigParser;
    $paired = $parser->parse(pairedControllerConfig('DAY-HUB-WLC1', 'day-hub-wlc2'));
    $sameController = $parser->parse(pairedControllerConfig('DAY-HUB-WLC1', 'day-hub-wlc1'));

    expect($paired)->toHaveCount(1)
        ->and($paired[0]['controller_name'])->toBe('DAY-HUB-WLC1')
        ->and($paired[0]['lldp_neighbors'])->toHaveCount(2)
        ->and($sameController)->toHaveCount(2);
});

it('uses wlan profiles from partner when primary is missing virtual-ap vlan', function () {
    $parser = new ArubaControllerConfigParser;
    $results = $parser->parse(pairedControllerConfigWithMissingPrimaryVlan('DAY-HUB-WLC1', 'DAY-HUB-WLC2'));

    expect($results)->toHaveCount(1)
        ->and($results[0]['wlan_profiles'])->toHaveCount(1)
        ->and($results[0]['wlan_profiles'][0]['ssid_profile_name'])->toBe('DAYKIT')
        ->and($results[0]['wlan_profiles'][0]['raw_vlan'])->toBe('DAYKIT')
        ->and($results[0]['wlan_profiles'][0]['warnings'])->not->toContain('Missing vlan from virtual-ap');
});

it('keeps wlan profiles from primary when partner is missing virtual-ap vlan', function () {
    $parser = new ArubaControllerConfigParser;
    $results = $parser->parse(pairedControllerConfigWithMissingPrimaryVlan('DAY-HUB-WLC2', 'DAY-HUB-WLC1'));

    expect($results)->toHaveCount(1)
        ->and($results[0]['controller_name'])->toBe('DAY-HUB-WLC2')
        ->and($results[0]['wlan_profiles'])->toHaveCount(1)
        ->and($results[0]['wlan_profiles'][0]['ssid_profile_name'])->toBe('DAYKIT')
        ->and($results[0]['wlan_profiles'][0]['raw_vlan'])->toBe('DAYKIT')
        ->and($results[0]['wlan_profiles'][0]['warnings'])->not->toContain('Missing vlan from virtual-ap');
});

it('deduplicates identical wlan ssids when merging paired controllers', function () {
    $parser = new ArubaControllerConfigParser;
    $results = $parser->parse(pairedControllerConfigWithSharedSsid('DAY-HUB-WLC1', 'DAY-HUB-WLC2'));

    expect($results)->toHaveCount(1)
        ->and($results[0]['wlan_profiles'])->toHaveCount(1)
        ->and($results[0]['wlan_profiles'][0]['ssid_profile_name'])->toBe('DAYKIT')
        ->and($results[0]['wlan_profiles'][0]['raw_vlan'])->toBe('DAYKIT');
});

it('deduplicates wlan ssids from multiple ssid-profile blocks with the same essid', function () {
    $content = <<<'CONFIG'
(WLC-ONE) #show ap database long
AP Database
-----------
Name             Group        AP Type  IP Address    Status             Flags  Switch IP   Standby IP  Wired MAC Address  Serial #    Port  FQLN  Outer IP  User
----             -----        -------  ----------    ------             -----  ---------   ----------  -----------------  --------    ----  ----  --------  ----
AP-ONE-001       default      514      10.1.1.1      Up 1d:0h:0m:0s     2      10.1.1.2    10.1.1.3    00:11:22:33:44:55  SERONE001   N/A   N/A   N/A

(WLC-ONE) #show running-config
wlan ssid-profile "DAYKIT_legacy_prof"
    essid "DAYKIT"
    wpa-passphrase "legacy-passphrase-12345"
!
wlan ssid-profile "DAYKIT_ssid_prof"
    essid "DAYKIT"
    wpa-passphrase "daykit-passphrase-12345"
    opmode wpa2-psk-aes
!
wlan virtual-ap "DAYKIT"
    vlan DAYKIT
    ssid-profile "DAYKIT_ssid_prof"
!
CONFIG;

    $parser = new ArubaControllerConfigParser;
    $profiles = $parser->parse($content)[0]['wlan_profiles'];

    expect($profiles)->toHaveCount(1)
        ->and($profiles[0]['ssid_profile_name'])->toBe('DAYKIT')
        ->and($profiles[0]['raw_vlan'])->toBe('DAYKIT')
        ->and($profiles[0]['warnings'])->not->toContain('Missing vlan from virtual-ap');
});

it('builds wlan profile body for WCD_AGV with rf-band from allowed-band a', function () {
    $content = file_get_contents(base_path('tests/fixtures/daytona_config.txt'));
    $parser = new ArubaControllerConfigParser;
    $profiles = $parser->parse($content)[0]['wlan_profiles'];

    $wcdAgv = collect($profiles)->firstWhere('ssid_profile_name', 'WCD_AGV');

    expect($wcdAgv)->not->toBeNull()
        ->and($wcdAgv['body']['rf-band'])->toBe('5GHZ')
        ->and($wcdAgv['body']['advertise-apname'])->toBeTrue();
});

it('defaults rf-band and advertise-apname when not present in config', function () {
    $content = file_get_contents(base_path('tests/fixtures/daytona_config.txt'));
    $parser = new ArubaControllerConfigParser;
    $profiles = $parser->parse($content)[0]['wlan_profiles'];

    $daytj = collect($profiles)->firstWhere('ssid_profile_name', 'DAYTJ');
    $daywcd = collect($profiles)->firstWhere('ssid_profile_name', 'DAYWCD');

    expect($daytj)->not->toBeNull()
        ->and($daytj['body']['rf-band'])->toBe('24GHZ_5GHZ')
        ->and($daytj['body']['advertise-apname'])->toBeTrue()
        ->and($daywcd)->not->toBeNull()
        ->and($daywcd['body']['rf-band'])->toBe('24GHZ_5GHZ')
        ->and($daywcd['body']['advertise-apname'])->toBeTrue();
});

it('maps allowed-band g to rf-band 24GHZ', function () {
    $content = <<<'CONFIG'
(WLC-ONE) #show ap database long
AP Database
-----------
Name             Group        AP Type  IP Address    Status             Flags  Switch IP   Standby IP  Wired MAC Address  Serial #    Port  FQLN  Outer IP  User
----             -----        -------  ----------    ------             -----  ---------   ----------  -----------------  --------    ----  ----  --------  ----
AP-ONE-001       default      514      10.1.1.1      Up 1d:0h:0m:0s     2      10.1.1.2    10.1.1.3    00:11:22:33:44:55  SERONE001   N/A   N/A   N/A

(WLC-ONE) #show running-config
wlan ssid-profile "G24_ssid_prof"
    essid "G24"
    wpa-passphrase "g24-passphrase-12345"
!
wlan virtual-ap "G24"
    vlan DAYG24
    ssid-profile "G24_ssid_prof"
    allowed-band g
!
CONFIG;

    $parser = new ArubaControllerConfigParser;
    $profile = $parser->parse($content)[0]['wlan_profiles'][0];

    expect($profile['ssid_profile_name'])->toBe('G24')
        ->and($profile['body']['rf-band'])->toBe('24GHZ');
});

it('defaults rf-band for unknown allowed-band values', function () {
    $content = <<<'CONFIG'
(WLC-ONE) #show ap database long
AP Database
-----------
Name             Group        AP Type  IP Address    Status             Flags  Switch IP   Standby IP  Wired MAC Address  Serial #    Port  FQLN  Outer IP  User
----             -----        -------  ----------    ------             -----  ---------   ----------  -----------------  --------    ----  ----  --------  ----
AP-ONE-001       default      514      10.1.1.1      Up 1d:0h:0m:0s     2      10.1.1.2    10.1.1.3    00:11:22:33:44:55  SERONE001   N/A   N/A   N/A

(WLC-ONE) #show running-config
wlan ssid-profile "UNK_ssid_prof"
    essid "UNK"
    wpa-passphrase "unk-passphrase-12345"
!
wlan virtual-ap "UNK"
    vlan DAYUNK
    ssid-profile "UNK_ssid_prof"
    allowed-band n
!
CONFIG;

    $parser = new ArubaControllerConfigParser;
    $profile = $parser->parse($content)[0]['wlan_profiles'][0];

    expect($profile['ssid_profile_name'])->toBe('UNK')
        ->and($profile['body']['rf-band'])->toBe('24GHZ_5GHZ');
});

it('skips the first two session access-lists on a user-role and expands the rest', function () {
    $content = <<<'CONFIG'
(WLC-ROLE) #show ap database long
AP Database
-----------
Name             Group        AP Type  IP Address    Status             Flags  Switch IP   Standby IP  Wired MAC Address  Serial #    Port  FQLN  Outer IP  User
----             -----        -------  ----------    ------             -----  ---------   ----------  -----------------  --------    ----  ----  --------  ----
AP-ROLE-001      default      514      10.1.1.1      Up 1d:0h:0m:0s     2      10.1.1.2    10.1.1.3    00:11:22:33:44:55  SERROLE001  N/A   N/A   N/A

(WLC-ROLE) #show running-config
netservice svc-https tcp 443
netservice svc-dhcp udp 67 68 ALG dhcp
netservice svc-web tcp list "80 443"
netdestination printers
    invert
    host 10.1.1.10
    network 10.2.0.0 255.255.0.0
    name printers.example.com
!
ip access-list session global-sacl
!
ip access-list session apprf-wcd_printer-sacl
!
ip access-list session allowall
    any any any permit
    ipv6 any any any permit
!
ip access-list session printer-acl
    user alias printers svc-https dst-nat 8081
    network 10.48.8.0 255.255.255.0 network 10.49.10.0 255.255.255.0 tcp 3389 permit
    any any svc-dhcp permit
    any any svc-missing permit
    host 255.255.255.255 any any deny
    any alias unknown-alias any deny
    ipv6 any any svc-https permit
!
user-role WCD_Printer
    access-list session global-sacl
    access-list session apprf-wcd_printer-sacl
    access-list session allowall
    access-list session printer-acl
!
CONFIG;

    $parser = new ArubaControllerConfigParser;
    $roles = $parser->parse($content)[0]['user_roles'];
    $role = collect($roles)->firstWhere('name', 'WCD_Printer');

    expect($role)->not->toBeNull()
        ->and(array_column($role['access_lists'], 'name'))->toBe(['allowall', 'printer-acl']);

    $allowall = $role['access_lists'][0];
    expect($allowall['rules'])->toHaveCount(1)
        ->and($allowall['rules'][0])->toMatchArray([
            'source' => ['type' => 'any'],
            'destination' => ['type' => 'any'],
            'service' => ['type' => 'any'],
            'action' => 'permit',
            'other' => '',
        ]);

    $printerAcl = $role['access_lists'][1];
    expect($printerAcl['rules'])->toHaveCount(6);

    $httpsRule = $printerAcl['rules'][0];
    expect($httpsRule['source'])->toMatchArray(['type' => 'user'])
        ->and($httpsRule['destination']['type'])->toBe('alias')
        ->and($httpsRule['destination']['value'])->toBe('printers')
        ->and($httpsRule['destination']['resolved'])->toMatchArray([
            'name' => 'printers',
            'invert' => true,
            'entries' => [
                ['type' => 'host', 'value' => '10.1.1.10'],
                ['type' => 'network', 'value' => '10.2.0.0', 'subnet' => '255.255.0.0'],
                ['type' => 'name', 'value' => 'printers.example.com'],
            ],
        ])
        ->and($httpsRule['service']['type'])->toBe('svc')
        ->and($httpsRule['service']['name'])->toBe('svc-https')
        ->and($httpsRule['service']['resolved'])->toMatchArray([
            'name' => 'svc-https',
            'protocol' => 'tcp',
            'values' => ['443'],
            'alg' => null,
        ])
        ->and($httpsRule['action'])->toBe('dst-nat')
        ->and($httpsRule['other'])->toBe('8081');

    $networkRule = $printerAcl['rules'][1];
    expect($networkRule)->toMatchArray([
        'source' => [
            'type' => 'network',
            'value' => '10.48.8.0',
            'subnet' => '255.255.255.0',
        ],
        'destination' => [
            'type' => 'network',
            'value' => '10.49.10.0',
            'subnet' => '255.255.255.0',
        ],
        'service' => [
            'type' => 'tcp',
            'ports' => ['3389'],
        ],
        'action' => 'permit',
        'other' => '',
    ]);

    $dhcpRule = $printerAcl['rules'][2];
    expect($dhcpRule['service']['resolved'])->toMatchArray([
        'name' => 'svc-dhcp',
        'protocol' => 'udp',
        'values' => ['67', '68'],
        'alg' => 'dhcp',
    ]);

    $missingSvc = $printerAcl['rules'][3];
    expect($missingSvc['service']['resolved'])->toBeNull()
        ->and($printerAcl['warnings'])->toContain('Unresolved service "svc-missing"');

    $unresolvedAlias = $printerAcl['rules'][5];
    expect($unresolvedAlias['destination']['resolved'])->toBeNull()
        ->and($printerAcl['warnings'])->toContain('Unresolved alias "unknown-alias"');
});

it('resolves netservice list form and parses multi-port udp rules', function () {
    $content = <<<'CONFIG'
(WLC-SVC) #show ap database long
AP Database
-----------
Name             Group        AP Type  IP Address    Status             Flags  Switch IP   Standby IP  Wired MAC Address  Serial #    Port  FQLN  Outer IP  User
----             -----        -------  ----------    ------             -----  ---------   ----------  -----------------  --------    ----  ----  --------  ----
AP-SVC-001       default      514      10.1.1.1      Up 1d:0h:0m:0s     2      10.1.1.2    10.1.1.3    00:11:22:33:44:55  SERSVC001   N/A   N/A   N/A

(WLC-SVC) #show running-config
netservice svc-web tcp list "80 443"
ip access-list session web-acl
    any any svc-web permit queue high
    any any udp 3478 3497 permit
!
user-role guest
    access-list session global-sacl
    access-list session apprf-guest-sacl
    access-list session web-acl
!
CONFIG;

    $parser = new ArubaControllerConfigParser;
    $role = collect($parser->parse($content)[0]['user_roles'])->firstWhere('name', 'guest');
    $acl = $role['access_lists'][0];

    expect($acl['name'])->toBe('web-acl')
        ->and($acl['rules'][0]['service']['resolved'])->toMatchArray([
            'name' => 'svc-web',
            'protocol' => 'tcp',
            'values' => ['80', '443'],
            'alg' => null,
        ])
        ->and($acl['rules'][0]['other'])->toBe('queue high')
        ->and($acl['rules'][1]['service'])->toMatchArray([
            'type' => 'udp',
            'ports' => ['3478', '3497'],
        ]);
});

it('parses daytona user roles and skips global/apprf access-lists', function () {
    $content = file_get_contents(base_path('tests/fixtures/daytona_config.txt'));
    $parser = new ArubaControllerConfigParser;
    $roles = $parser->parse($content)[0]['user_roles'];

    $printer = collect($roles)->firstWhere('name', 'WCD_Printer');
    $voice = collect($roles)->firstWhere('name', 'voice');
    $denyall = collect($roles)->firstWhere('name', 'denyall');

    expect($printer)->not->toBeNull()
        ->and(array_column($printer['access_lists'], 'name'))->toBe(['allowall'])
        ->and($printer['access_lists'][0]['rules'])->toHaveCount(1)
        ->and($printer['access_lists'][0]['rules'][0]['action'])->toBe('permit');

    expect($voice)->not->toBeNull()
        ->and(array_column($voice['access_lists'], 'name'))->toContain('ra-guard')
        ->and(array_column($voice['access_lists'], 'name'))->toContain('sip-acl')
        ->and(array_column($voice['access_lists'], 'name'))->not->toContain('global-sacl')
        ->and(array_column($voice['access_lists'], 'name'))->not->toContain('apprf-voice-sacl');

    expect($denyall)->not->toBeNull()
        ->and($denyall['access_lists'])->toBe([]);

    $wificallingAcl = collect($voice['access_lists'])->firstWhere('name', 'wificalling-acl');
    expect($wificallingAcl)->not->toBeNull();

    // wificalling-acl itself is simple; check a role ACL that resolves the alias.
    $guest = collect($roles)->firstWhere('name', 'guest');
    $httpsAcl = collect($guest['access_lists'])->firstWhere('name', 'https-acl');
    expect($httpsAcl)->not->toBeNull()
        ->and($httpsAcl['rules'][0]['service']['type'])->toBe('svc')
        ->and($httpsAcl['rules'][0]['service']['resolved']['name'] ?? null)->toBe('svc-https');
});

function pairedControllerConfig(string $firstName, string $secondName): string
{
    return <<<CONFIG
({$firstName}) #show ap database long
AP Database
-----------
Name             Group        AP Type  IP Address    Status             Flags  Switch IP   Standby IP  Wired MAC Address  Serial #    Port  FQLN  Outer IP  User
----             -----        -------  ----------    ------             -----  ---------   ----------  -----------------  --------    ----  ----  --------  ----
AP-FIRST-001     default      514      10.1.1.1      Up 1d:0h:0m:0s     2      10.1.1.2    10.1.1.3    00:11:22:33:44:55  SERFIRST1   N/A   N/A   N/A

({$firstName}) #show ap lldp neighbors
AP LLDP Neighbors (Updated every 300 seconds)
---------------------------------------------
AP               Interface  Neighbor  Chassis Name/ID               Port ID   Port Desc  Mgmt. Address  Capabilities
--               ---------  --------  ---------------               -------   ---------  -------------  ------------
AP-FIRST-001     bond0      0         SW-A.example.com              Te1/0/1   AP         10.1.1.10      B

({$firstName}) #show running-config
wlan ssid-profile "FIRST_ssid_prof"
    essid "FIRST"
    wpa-passphrase "first-passphrase-12345"
    opmode wpa2-psk-aes
!
wlan virtual-ap "FIRST"
    vlan FIRST
    ssid-profile "FIRST_ssid_prof"

({$secondName}) #show ap database long
AP Database
-----------
Name             Group        AP Type  IP Address    Status             Flags  Switch IP   Standby IP  Wired MAC Address  Serial #    Port  FQLN  Outer IP  User
----             -----        -------  ----------    ------             -----  ---------   ----------  -----------------  --------    ----  ----  --------  ----
AP-SECOND-001    default      514      10.2.2.1      Up 1d:0h:0m:0s     2      10.2.2.2    10.2.2.3    aa:bb:cc:dd:ee:ff  SERSECOND1  N/A   N/A   N/A

({$secondName}) #show ap lldp neighbors
AP LLDP Neighbors (Updated every 300 seconds)
---------------------------------------------
AP               Interface  Neighbor  Chassis Name/ID               Port ID   Port Desc  Mgmt. Address  Capabilities
--               ---------  --------  ---------------               -------   ---------  -------------  ------------
AP-SECOND-001    bond0      0         SW-A.example.com              Te1/0/2   AP         10.2.2.10      B
AP-SECOND-002    bond0      0         SW-A.example.com              Te1/0/1   AP         10.2.2.10      B
AP-SECOND-003    bond0      0         SW-B.example.com              Te2/0/1   AP         10.2.2.11      B

({$secondName}) #show running-config
wlan ssid-profile "SECOND_ssid_prof"
    essid "SECOND"
    wpa-passphrase "second-passphrase-12345"
    opmode wpa2-psk-aes
!
wlan virtual-ap "SECOND"
    vlan SECOND
    ssid-profile "SECOND_ssid_prof"
CONFIG;
}

function pairedControllerConfigWithMissingPrimaryVlan(string $firstName, string $secondName): string
{
    return <<<CONFIG
({$firstName}) #show ap database long
AP Database
-----------
Name             Group        AP Type  IP Address    Status             Flags  Switch IP   Standby IP  Wired MAC Address  Serial #    Port  FQLN  Outer IP  User
----             -----        -------  ----------    ------             -----  ---------   ----------  -----------------  --------    ----  ----  --------  ----
AP-FIRST-001     default      514      10.1.1.1      Up 1d:0h:0m:0s     2      10.1.1.2    10.1.1.3    00:11:22:33:44:55  SERFIRST1   N/A   N/A   N/A

({$firstName}) #show ap lldp neighbors
AP LLDP Neighbors (Updated every 300 seconds)
---------------------------------------------
AP               Interface  Neighbor  Chassis Name/ID               Port ID   Port Desc  Mgmt. Address  Capabilities
--               ---------  --------  ---------------               -------   ---------  -------------  ------------
AP-FIRST-001     bond0      0         SW-A.example.com              Te1/0/1   AP         10.1.1.10      B

({$firstName}) #show running-config
wlan ssid-profile "DAYKIT_ssid_prof"
    essid "DAYKIT"
    wpa-passphrase "daykit-passphrase-12345"
    opmode wpa2-psk-aes
!

({$secondName}) #show ap database long
AP Database
-----------
Name             Group        AP Type  IP Address    Status             Flags  Switch IP   Standby IP  Wired MAC Address  Serial #    Port  FQLN  Outer IP  User
----             -----        -------  ----------    ------             -----  ---------   ----------  -----------------  --------    ----  ----  --------  ----
AP-SECOND-001    default      514      10.2.2.1      Up 1d:0h:0m:0s     2      10.2.2.2    10.2.2.3    aa:bb:cc:dd:ee:ff  SERSECOND1  N/A   N/A   N/A

({$secondName}) #show ap lldp neighbors
AP LLDP Neighbors (Updated every 300 seconds)
---------------------------------------------
AP               Interface  Neighbor  Chassis Name/ID               Port ID   Port Desc  Mgmt. Address  Capabilities
--               ---------  --------  ---------------               -------   ---------  -------------  ------------
AP-SECOND-001    bond0      0         SW-B.example.com              Te2/0/1   AP         10.2.2.10      B

({$secondName}) #show running-config
wlan ssid-profile "DAYKIT_ssid_prof"
    essid "DAYKIT"
    wpa-passphrase "daykit-passphrase-12345"
    opmode wpa2-psk-aes
!
wlan virtual-ap "DAYKIT"
    vlan DAYKIT
    ssid-profile "DAYKIT_ssid_prof"
!
CONFIG;
}

function pairedControllerConfigWithSharedSsid(string $firstName, string $secondName): string
{
    return <<<CONFIG
({$firstName}) #show ap database long
AP Database
-----------
Name             Group        AP Type  IP Address    Status             Flags  Switch IP   Standby IP  Wired MAC Address  Serial #    Port  FQLN  Outer IP  User
----             -----        -------  ----------    ------             -----  ---------   ----------  -----------------  --------    ----  ----  --------  ----
AP-FIRST-001     default      514      10.1.1.1      Up 1d:0h:0m:0s     2      10.1.1.2    10.1.1.3    00:11:22:33:44:55  SERFIRST1   N/A   N/A   N/A

({$firstName}) #show ap lldp neighbors
AP LLDP Neighbors (Updated every 300 seconds)
---------------------------------------------
AP               Interface  Neighbor  Chassis Name/ID               Port ID   Port Desc  Mgmt. Address  Capabilities
--               ---------  --------  ---------------               -------   ---------  -------------  ------------
AP-FIRST-001     bond0      0         SW-A.example.com              Te1/0/1   AP         10.1.1.10      B

({$firstName}) #show running-config
wlan ssid-profile "DAYKIT_ssid_prof"
    essid "DAYKIT"
    wpa-passphrase "daykit-passphrase-12345"
    opmode wpa2-psk-aes
!
wlan virtual-ap "DAYKIT"
    vlan DAYKIT
    ssid-profile "DAYKIT_ssid_prof"
!

({$secondName}) #show ap database long
AP Database
-----------
Name             Group        AP Type  IP Address    Status             Flags  Switch IP   Standby IP  Wired MAC Address  Serial #    Port  FQLN  Outer IP  User
----             -----        -------  ----------    ------             -----  ---------   ----------  -----------------  --------    ----  ----  --------  ----
AP-SECOND-001    default      514      10.2.2.1      Up 1d:0h:0m:0s     2      10.2.2.2    10.2.2.3    aa:bb:cc:dd:ee:ff  SERSECOND1  N/A   N/A   N/A

({$secondName}) #show ap lldp neighbors
AP LLDP Neighbors (Updated every 300 seconds)
---------------------------------------------
AP               Interface  Neighbor  Chassis Name/ID               Port ID   Port Desc  Mgmt. Address  Capabilities
--               ---------  --------  ---------------               -------   ---------  -------------  ------------
AP-SECOND-001    bond0      0         SW-B.example.com              Te2/0/1   AP         10.2.2.10      B

({$secondName}) #show running-config
wlan ssid-profile "DAYKIT_ssid_prof"
    essid "DAYKIT"
    wpa-passphrase "daykit-passphrase-12345"
    opmode wpa2-psk-aes
!
wlan virtual-ap "DAYKIT"
    vlan DAYKIT
    ssid-profile "DAYKIT_ssid_prof"
!
CONFIG;
}
