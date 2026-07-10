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
        ->and($dayrf['body'])->not->toHaveKey('rf-band')
        ->and($dayrf['body']['advertise-apname'])->toBeTrue()
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
        ->and($results[0]['wlan_profiles'])->toHaveCount(1)
        ->and($results[0]['wlan_profiles'][0]['ssid_profile_name'])->toBe('FIRST')
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

it('builds wlan profile body for WCD_AGV with rf-band from allowed-band a', function () {
    $content = file_get_contents(base_path('tests/fixtures/daytona_config.txt'));
    $parser = new ArubaControllerConfigParser;
    $profiles = $parser->parse($content)[0]['wlan_profiles'];

    $wcdAgv = collect($profiles)->firstWhere('ssid_profile_name', 'WCD_AGV');

    expect($wcdAgv)->not->toBeNull()
        ->and($wcdAgv['body']['rf-band'])->toBe('5GHZ')
        ->and($wcdAgv['body'])->not->toHaveKey('advertise-apname');
});

it('omits rf-band and advertise-apname when not present in config', function () {
    $content = file_get_contents(base_path('tests/fixtures/daytona_config.txt'));
    $parser = new ArubaControllerConfigParser;
    $profiles = $parser->parse($content)[0]['wlan_profiles'];

    $daytj = collect($profiles)->firstWhere('ssid_profile_name', 'DAYTJ');
    $daywcd = collect($profiles)->firstWhere('ssid_profile_name', 'DAYWCD');

    expect($daytj)->not->toBeNull()
        ->and($daytj['body'])->not->toHaveKey('rf-band')
        ->and($daytj['body'])->not->toHaveKey('advertise-apname')
        ->and($daywcd)->not->toBeNull()
        ->and($daywcd['body'])->not->toHaveKey('rf-band')
        ->and($daywcd['body'])->not->toHaveKey('advertise-apname');
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

it('omits rf-band for unknown allowed-band values', function () {
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
        ->and($profile['body'])->not->toHaveKey('rf-band');
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
