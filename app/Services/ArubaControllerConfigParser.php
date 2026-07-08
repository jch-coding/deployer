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

        return array_map(
            fn (array $block): array => $this->parseControllerBlock($block['name'], $block['content']),
            $controllerBlocks,
        );
    }

    public static function mapVlanName(string $rawVlan): string
    {
        if ($rawVlan === 'WCD_PI') {
            return 'WCD_PI';
        }

        return 'WCD_'.substr($rawVlan, 3);
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
        $vlanBySsidProfile = $this->parseVirtualApVlanMap($section);

        $profiles = [];

        foreach ($ssidProfiles as $profileName => $ssidData) {
            $rawVlan = $vlanBySsidProfile[$profileName] ?? null;
            $vlanName = $rawVlan !== null ? self::mapVlanName($rawVlan) : null;
            $warnings = $this->buildProfileWarnings($ssidData, $rawVlan, $vlanName);
            $profiles[] = [
                'ssid_profile_name' => $profileName,
                'raw_vlan' => $rawVlan,
                'vlan_name' => $vlanName,
                'body' => $this->buildWlanSsidProfileBody($profileName, $ssidData, $vlanName),
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
     *     g_tx_rates: array<int, string>
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
     *     g_tx_rates: array<int, string>
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
            }
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    private function parseVirtualApVlanMap(string $section): array
    {
        $map = [];
        $currentSsidProfile = null;
        $currentVlan = null;

        foreach (explode("\n", $section) as $line) {
            $trimmed = rtrim($line);

            if (preg_match('/^wlan virtual-ap "/', $trimmed)) {
                if ($currentSsidProfile !== null && $currentVlan !== null) {
                    $map[$currentSsidProfile] = $currentVlan;
                }

                $currentSsidProfile = null;
                $currentVlan = null;

                continue;
            }

            if ($trimmed === '!' && $currentSsidProfile !== null) {
                if ($currentVlan !== null) {
                    $map[$currentSsidProfile] = $currentVlan;
                }

                $currentSsidProfile = null;
                $currentVlan = null;

                continue;
            }

            if (preg_match('/^\s+ssid-profile "([^"]+)"/', $trimmed, $match)) {
                $currentSsidProfile = $match[1];
            } elseif (preg_match('/^\s+vlan (\S+)/', $trimmed, $match)) {
                $currentVlan = $match[1];
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
     *     g_tx_rates: array<int, string>
     * }  $ssidData
     * @return array<string, mixed>
     */
    private function buildWlanSsidProfileBody(string $profileName, array $ssidData, ?string $vlanName): array
    {
        $body = [
            'essid' => ['name' => $ssidData['essid']],
            'g-legacy-rates' => [
                'basic-rates' => $this->ratesToApiFormat($ssidData['g_basic_rates']),
                'tx-rates' => $this->ratesToApiFormat($ssidData['g_tx_rates']),
            ],
            'opmode' => 'WPA2_PERSONAL',
            'personal-security' => [
                'passphrase-format' => 'STRING',
                'wpa-passphrase' => $ssidData['wpa_passphrase'],
            ],
            'type' => 'EMPLOYEE',
            'internal-auth-server' => 'INTERNAL_SERVER',
            'vlan-name' => $vlanName,
            'vlan-selector' => 'NAMED_VLAN',
            'enable' => true,
            'ssid' => $profileName,
            'a-legacy-rates' => [
                'basic-rates' => $this->ratesToApiFormat($ssidData['a_basic_rates']),
                'tx-rates' => $this->ratesToApiFormat($ssidData['a_tx_rates']),
            ],
        ];

        return $body;
    }

    /**
     * @param  array{
     *     essid: string|null,
     *     wpa_passphrase: string|null,
     *     a_basic_rates: array<int, string>,
     *     a_tx_rates: array<int, string>,
     *     g_basic_rates: array<int, string>,
     *     g_tx_rates: array<int, string>
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
