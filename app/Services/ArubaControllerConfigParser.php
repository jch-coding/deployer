<?php

namespace App\Services;

class ArubaControllerConfigParser
{
    private const AP_ROW_PATTERN = '/^(\S+)\s+.*?([0-9a-f]{2}(?::[0-9a-f]{2}){5})\s+(\S+)/i';

    private const LLDP_ROW_PATTERN = '/^(\S+)\s+\S+\s+\d+\s+(\S+)\s+(\S+)\s+/';

    public function parse(string $content): array
    {
        $content = str_replace("\r\n", "\n", $content);
        $controllerBlocks = $this->splitControllerBlocks($content);

        if ($controllerBlocks === []) {
            return [];
        }

        $parsedControllers = [];

        foreach ($controllerBlocks as $block) {
            $parsedNames = array_column($parsedControllers, 'controller_name');
            $pairedName = $this->findParsedPair($block['name'], $parsedNames);

            if ($pairedName !== null) {
                $pairedIndex = array_search($pairedName, $parsedNames, true);
                $parsedControllers[$pairedIndex]['lldp_neighbors'] = $this->mergeLldpNeighbors(
                    $parsedControllers[$pairedIndex]['lldp_neighbors'],
                    $this->parseLldpNeighbors($block['content']),
                );

                continue;
            }

            $parsedControllers[] = $this->parseControllerBlock($block['name'], $block['content']);
        }

        return $parsedControllers;
    }

    public static function mapVlanName(string $rawVlan): string
    {
        if ($rawVlan === 'WCD_PI') {
            return 'WCD_PI';
        }

        $remainder = substr($rawVlan, 3);

        if ($remainder === 'WCD') {
            return 'WCD_WLAN';
        }

        return 'WCD_'.$remainder;
    }

    private function areControllerPair(string $first, string $second): bool
    {
        if (strcasecmp($first, $second) === 0) {
            return false;
        }

        if (strlen($first) !== strlen($second) || strlen($first) < 2) {
            return false;
        }

        return strcasecmp(substr($first, 0, -1), substr($second, 0, -1)) === 0;
    }

    /**
     * @param  array<int, string>  $parsedNames
     */
    private function findParsedPair(string $name, array $parsedNames): ?string
    {
        foreach ($parsedNames as $parsedName) {
            if ($this->areControllerPair($name, $parsedName)) {
                return $parsedName;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{name: string, content: string}>
     */
    private function splitControllerBlocks(string $content): array
    {
        if (! preg_match_all('/\(([^)]+)\)\s*#show ap database long/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $blocks = [];
        $count = count($matches[0]);

        for ($i = 0; $i < $count; $i++) {
            $start = $matches[0][$i][1];
            $end = ($i + 1 < $count) ? $matches[0][$i + 1][1] : strlen($content);
            $blocks[] = [
                'name' => trim($matches[1][$i][0]),
                'content' => substr($content, $start, $end - $start),
            ];
        }

        return $blocks;
    }

    /**
     * @return array{
     *     controller_name: string,
     *     devices: array<int, array{name: string, serial: string, mac: string}>,
     *     lldp_neighbors: array<int, array{switch: string, ports: array<int, string>}>,
     *     wlan_profiles: array<int, array{
     *         ssid_profile_name: string,
     *         raw_vlan: string|null,
     *         vlan_name: string|null,
     *         body: array<string, mixed>,
     *         warnings: array<int, string>
     *     }>
     * }
     */
    private function parseControllerBlock(string $controllerName, string $content): array
    {
        return [
            'controller_name' => $controllerName,
            'devices' => $this->parseApDatabase($content),
            'lldp_neighbors' => $this->parseLldpNeighbors($content),
            'wlan_profiles' => $this->parseWlanProfiles($content),
        ];
    }

    /**
     * @return array<int, array{name: string, serial: string, mac: string}>
     */
    private function parseApDatabase(string $content): array
    {
        $section = $this->extractSection($content, '/#show ap database long/i', '/(?:#show ap lldp neighbors|Total APs:\d+)/i');

        if ($section === null) {
            $section = $content;
        }

        $devices = [];
        $seenSerials = [];

        foreach (explode("\n", $section) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, 'Flags:') || str_starts_with($line, '----') || str_contains($line, 'AP Database')) {
                continue;
            }

            if (preg_match('/^Name\s+Group/i', $line)) {
                continue;
            }

            if (preg_match(self::AP_ROW_PATTERN, $line, $match)) {
                $serial = $match[3];

                if (isset($seenSerials[$serial])) {
                    continue;
                }

                $seenSerials[$serial] = true;
                $devices[] = [
                    'name' => $match[1],
                    'mac' => strtolower($match[2]),
                    'serial' => $serial,
                ];
            }
        }

        return $devices;
    }

    /**
     * @return array<int, array{switch: string, ports: array<int, string>}>
     */
    private function parseLldpNeighbors(string $content): array
    {
        $section = $this->extractSection($content, '/#show ap lldp neighbors/i', '/(?:#show running-config|\([^)]+\) #)/i');

        if ($section === null) {
            return [];
        }

        $bySwitch = [];

        foreach (explode("\n", $section) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '----') || str_contains($line, 'LLDP Neighbors') || str_contains($line, 'Capability codes')) {
                continue;
            }

            if (preg_match('/^AP\s+Interface/i', $line)) {
                continue;
            }

            if (preg_match(self::LLDP_ROW_PATTERN, $line, $match)) {
                $switch = $match[2];
                $port = $match[3];

                if (! isset($bySwitch[$switch])) {
                    $bySwitch[$switch] = [];
                }

                $bySwitch[$switch][$port] = true;
            }
        }

        $neighbors = [];

        foreach ($bySwitch as $switch => $ports) {
            $portList = array_keys($ports);
            sort($portList);
            $neighbors[] = [
                'switch' => $switch,
                'ports' => $portList,
            ];
        }

        usort($neighbors, fn (array $a, array $b): int => strcmp($a['switch'], $b['switch']));

        return $neighbors;
    }

    /**
     * @param  array<int, array<int, array{switch: string, ports: array<int, string>}>>  $neighborLists
     * @return array<int, array{switch: string, ports: array<int, string>}>
     */
    private function mergeLldpNeighbors(array ...$neighborLists): array
    {
        $bySwitch = [];

        foreach ($neighborLists as $neighbors) {
            foreach ($neighbors as $neighbor) {
                foreach ($neighbor['ports'] as $port) {
                    $bySwitch[$neighbor['switch']][$port] = true;
                }
            }
        }

        $merged = [];

        foreach ($bySwitch as $switch => $ports) {
            $portList = array_keys($ports);
            sort($portList);
            $merged[] = [
                'switch' => $switch,
                'ports' => $portList,
            ];
        }

        usort($merged, fn (array $a, array $b): int => strcmp($a['switch'], $b['switch']));

        return $merged;
    }

    /**
     * @return array<int, array{
     *     ssid_profile_name: string,
     *     raw_vlan: string|null,
     *     vlan_name: string|null,
     *     body: array<string, mixed>,
     *     warnings: array<int, string>
     * }>
     */
    private function parseWlanProfiles(string $content): array
    {
        $section = $this->extractSection($content, '/#show running-config/i', null);

        if ($section === null) {
            return [];
        }

        $ssidProfiles = $this->parseSsidProfileBlocks($section);
        $virtualApBySsidProfile = $this->parseVirtualApVlanMap($section);

        $profiles = [];

        foreach ($ssidProfiles as $profileName => $ssidData) {
            $virtualApData = $virtualApBySsidProfile[$profileName] ?? null;
            $rawVlan = $virtualApData['vlan'] ?? null;
            $allowedBand = $virtualApData['allowed_band'] ?? null;
            $vlanName = $rawVlan !== null ? self::mapVlanName($rawVlan) : null;
            $warnings = $this->buildProfileWarnings($ssidData, $rawVlan, $vlanName);
            $deployName = ($ssidData['essid'] !== null && $ssidData['essid'] !== '')
                ? $ssidData['essid']
                : $profileName;
            $profiles[] = [
                'ssid_profile_name' => $deployName,
                'raw_vlan' => $rawVlan,
                'vlan_name' => $vlanName,
                'body' => $this->buildWlanSsidProfileBody($deployName, $ssidData, $vlanName, $allowedBand),
                'warnings' => $warnings,
            ];
        }

        return $profiles;
    }

    /**
     * @return array<string, array{
     *     essid: string|null,
     *     wpa_passphrase: string|null,
     *     a_basic_rates: array<int, string>,
     *     a_tx_rates: array<int, string>,
     *     g_basic_rates: array<int, string>,
     *     g_tx_rates: array<int, string>,
     *     advertise_ap_name: bool
     * }>
     */
    private function parseSsidProfileBlocks(string $section): array
    {
        $profiles = [];
        $currentName = null;
        $currentLines = [];

        foreach (explode("\n", $section) as $line) {
            $trimmed = rtrim($line);

            if (preg_match('/^wlan ssid-profile "([^"]+)"/', $trimmed, $match)) {
                if ($currentName !== null) {
                    $profiles[$currentName] = $this->parseSsidProfileLines($currentLines);
                }

                $currentName = $match[1];
                $currentLines = [];

                continue;
            }

            if ($trimmed === '!' && $currentName !== null) {
                $profiles[$currentName] = $this->parseSsidProfileLines($currentLines);
                $currentName = null;
                $currentLines = [];

                continue;
            }

            if ($currentName !== null) {
                $currentLines[] = $trimmed;
            }
        }

        if ($currentName !== null) {
            $profiles[$currentName] = $this->parseSsidProfileLines($currentLines);
        }

        return $profiles;
    }

    /**
     * @param  array<int, string>  $lines
     * @return array{
     *     essid: string|null,
     *     wpa_passphrase: string|null,
     *     a_basic_rates: array<int, string>,
     *     a_tx_rates: array<int, string>,
     *     g_basic_rates: array<int, string>,
     *     g_tx_rates: array<int, string>,
     *     advertise_ap_name: bool
     * }
     */
    private function parseSsidProfileLines(array $lines): array
    {
        $data = [
            'essid' => null,
            'wpa_passphrase' => null,
            'a_basic_rates' => [],
            'a_tx_rates' => [],
            'g_basic_rates' => [],
            'g_tx_rates' => [],
            'advertise_ap_name' => false,
        ];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^essid "([^"]*)"/', $line, $match)) {
                $data['essid'] = $match[1];
            } elseif (preg_match('/^wpa-passphrase "([^"]*)"/', $line, $match)) {
                $data['wpa_passphrase'] = $match[1];
            } elseif (preg_match('/^a-basic-rates (.+)$/', $line, $match)) {
                $data['a_basic_rates'] = $this->parseRates($match[1]);
            } elseif (preg_match('/^a-tx-rates (.+)$/', $line, $match)) {
                $data['a_tx_rates'] = $this->parseRates($match[1]);
            } elseif (preg_match('/^g-basic-rates (.+)$/', $line, $match)) {
                $data['g_basic_rates'] = $this->parseRates($match[1]);
            } elseif (preg_match('/^g-tx-rates (.+)$/', $line, $match)) {
                $data['g_tx_rates'] = $this->parseRates($match[1]);
            } elseif ($line === 'advertise-ap-name') {
                $data['advertise_ap_name'] = true;
            }
        }

        return $data;
    }

    /**
     * @return array<string, array{vlan: ?string, allowed_band: ?string}>
     */
    private function parseVirtualApVlanMap(string $section): array
    {
        $map = [];
        $currentSsidProfile = null;
        $currentVlan = null;
        $currentAllowedBand = null;

        foreach (explode("\n", $section) as $line) {
            $trimmed = rtrim($line);

            if (preg_match('/^wlan virtual-ap "/', $trimmed)) {
                if ($currentSsidProfile !== null && $currentVlan !== null) {
                    $map[$currentSsidProfile] = [
                        'vlan' => $currentVlan,
                        'allowed_band' => $currentAllowedBand,
                    ];
                }

                $currentSsidProfile = null;
                $currentVlan = null;
                $currentAllowedBand = null;

                continue;
            }

            if ($trimmed === '!' && $currentSsidProfile !== null) {
                if ($currentVlan !== null) {
                    $map[$currentSsidProfile] = [
                        'vlan' => $currentVlan,
                        'allowed_band' => $currentAllowedBand,
                    ];
                }

                $currentSsidProfile = null;
                $currentVlan = null;
                $currentAllowedBand = null;

                continue;
            }

            if (preg_match('/^\s+ssid-profile "([^"]+)"/', $trimmed, $match)) {
                $currentSsidProfile = $match[1];
            } elseif (preg_match('/^\s+vlan (\S+)/', $trimmed, $match)) {
                $currentVlan = $match[1];
            } elseif (preg_match('/^\s+allowed-band (\S+)/', $trimmed, $match)) {
                $currentAllowedBand = $match[1];
            }
        }

        return $map;
    }

    /**
     * @param  array{
     *     essid: string|null,
     *     wpa_passphrase: string|null,
     *     a_basic_rates: array<int, string>,
     *     a_tx_rates: array<int, string>,
     *     g_basic_rates: array<int, string>,
     *     g_tx_rates: array<int, string>,
     *     advertise_ap_name: bool
     * }  $ssidData
     * @return array<string, mixed>
     */
    private function buildWlanSsidProfileBody(string $ssidName, array $ssidData, ?string $vlanName, ?string $allowedBand = null): array
    {
        $body = [
            'essid' => ['name' => $ssidData['essid']],
            'opmode' => 'WPA2_PERSONAL',
            'personal-security' => [
                'passphrase-format' => 'STRING',
                'wpa-passphrase' => $ssidData['wpa_passphrase'],
            ],
            'type' => 'EMPLOYEE',
            'high-throughput' => ['enable' => true, 'very-high-throughput' => true],
            'high-efficiency' => ['enable' => true],
            'vlan-name' => $vlanName,
            'vlan-selector' => 'NAMED_VLAN',
            'enable' => true,
            'ssid' => $ssidName,
        ];

        $gLegacyRates = $this->buildLegacyRates($ssidData['g_basic_rates'], $ssidData['g_tx_rates']);
        if ($gLegacyRates !== null) {
            $body['g-legacy-rates'] = $gLegacyRates;
        }

        $aLegacyRates = $this->buildLegacyRates($ssidData['a_basic_rates'], $ssidData['a_tx_rates']);
        if ($aLegacyRates !== null) {
            $body['a-legacy-rates'] = $aLegacyRates;
        }

        $rfBand = self::mapAllowedBandToRfBand($allowedBand);
        if ($rfBand !== null) {
            $body['rf-band'] = $rfBand;
        }

        if ($ssidData['advertise_ap_name']) {
            $body['advertise-apname'] = true;
        }

        return $body;
    }

    private static function mapAllowedBandToRfBand(?string $band): ?string
    {
        return match ($band) {
            'a' => '5GHZ',
            'g' => '24GHZ',
            default => null,
        };
    }

    /**
     * @param  array<int, string>  $basicRates
     * @param  array<int, string>  $txRates
     * @return array<string, array<int, string>>|null
     */
    private function buildLegacyRates(array $basicRates, array $txRates): ?array
    {
        $legacyRates = [];

        $basic = $this->ratesToApiFormat($basicRates);
        if ($basic !== []) {
            $legacyRates['basic-rates'] = $basic;
        }

        $tx = $this->ratesToApiFormat($txRates);
        if ($tx !== []) {
            $legacyRates['tx-rates'] = $tx;
        }

        return $legacyRates === [] ? null : $legacyRates;
    }

    /**
     * @param  array{
     *     essid: string|null,
     *     wpa_passphrase: string|null,
     *     a_basic_rates: array<int, string>,
     *     a_tx_rates: array<int, string>,
     *     g_basic_rates: array<int, string>,
     *     g_tx_rates: array<int, string>,
     *     advertise_ap_name: bool
     * }  $ssidData
     * @return array<int, string>
     */
    private function buildProfileWarnings(array $ssidData, ?string $rawVlan, ?string $vlanName): array
    {
        $warnings = [];

        if ($ssidData['essid'] === null || $ssidData['essid'] === '') {
            $warnings[] = 'Missing essid';
        }

        if ($ssidData['wpa_passphrase'] === null || $ssidData['wpa_passphrase'] === '') {
            $warnings[] = 'Missing wpa-passphrase';
        }

        if ($rawVlan === null) {
            $warnings[] = 'Missing vlan from virtual-ap';
        } elseif ($vlanName === null) {
            $warnings[] = 'Unable to map vlan name';
        }

        return $warnings;
    }

    /**
     * @return array<int, string>
     */
    private function parseRates(string $value): array
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];

        return array_values(array_filter($parts, fn (string $part): bool => $part !== ''));
    }

    /**
     * @param  array<int, string>  $rates
     * @return array<int, string>
     */
    private function ratesToApiFormat(array $rates): array
    {
        return array_map(
            fn (string $rate): string => 'RATE_'.$rate.'MB',
            $rates,
        );
    }

    private function extractSection(string $content, string $startPattern, ?string $endPattern): ?string
    {
        if (! preg_match($startPattern, $content, $startMatch, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start = $startMatch[0][1];

        if ($endPattern === null) {
            return substr($content, $start);
        }

        $remainder = substr($content, $start + strlen($startMatch[0][0]));

        if (preg_match($endPattern, $remainder, $endMatch, PREG_OFFSET_CAPTURE)) {
            return substr($content, $start, strlen($startMatch[0][0]) + $endMatch[0][1]);
        }

        return substr($content, $start);
    }
}
