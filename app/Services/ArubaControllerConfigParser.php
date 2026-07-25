<?php

namespace App\Services;

class ArubaControllerConfigParser
{
    private const AP_ROW_PATTERN = '/^(\S+)\s+.*?([0-9a-f]{2}(?::[0-9a-f]{2}){5})\s+(\S+)/i';

    private const LLDP_ROW_PATTERN = '/^(\S+)\s+\S+\s+\d+\s+(\S+)\s+(\S+)\s+/';

    /** @var array<int, string> */
    private const PERSONAL_OPMODE_TOKENS = [
        'opensystem',
        'wpa-psk-aes',
        'wpa-psk-tkip',
        'wpa2-psk-aes',
        'wpa3-sae-aes',
    ];

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
                $partnerWlanProfiles = $this->parseWlanProfiles($block['content']);
                $parsedControllers[$pairedIndex]['wlan_profiles'] = $this->mergeWlanProfiles(
                    $parsedControllers[$pairedIndex]['wlan_profiles'],
                    $partnerWlanProfiles,
                );
                $parsedControllers[$pairedIndex]['auth_servers'] = $this->mergeAuthServers(
                    $parsedControllers[$pairedIndex]['auth_servers'],
                    $this->parseAuthServers($block['content']),
                );
                $parsedControllers[$pairedIndex]['server_groups'] = $this->mergeServerGroups(
                    $parsedControllers[$pairedIndex]['server_groups'],
                    $this->parseServerGroups($block['content'], $partnerWlanProfiles),
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

        if (str_starts_with($rawVlan, 'WCD_')) {
            return $rawVlan;
        }

        $remainder = str_replace('FZN', '', substr($rawVlan, 3));

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
     *     auth_servers: array<int, array{
     *         name: string,
     *         host: string|null,
     *         has_coa: bool,
     *         body: array<string, mixed>,
     *         warnings: array<int, string>
     *     }>,
     *     server_groups: array<int, array{
     *         name: string,
     *         servers: array<int, array{server-name: string, position: int}>,
     *         body: array<string, mixed>,
     *         associated_essids: array<int, string>,
     *         warnings: array<int, string>
     *     }>,
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
        $wlanProfiles = $this->deduplicateWlanProfiles($this->parseWlanProfiles($content));

        return [
            'controller_name' => $controllerName,
            'devices' => $this->parseApDatabase($content),
            'lldp_neighbors' => $this->parseLldpNeighbors($content),
            'auth_servers' => $this->deduplicateAuthServers($this->parseAuthServers($content)),
            'server_groups' => $this->deduplicateServerGroups($this->parseServerGroups($content, $wlanProfiles)),
            'wlan_profiles' => $wlanProfiles,
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
     * @param  array<int, array{
     *     ssid_profile_name: string,
     *     raw_vlan: string|null,
     *     vlan_name: string|null,
     *     body: array<string, mixed>,
     *     warnings: array<int, string>
     * }>  $primaryProfiles
     * @param  array<int, array{
     *     ssid_profile_name: string,
     *     raw_vlan: string|null,
     *     vlan_name: string|null,
     *     body: array<string, mixed>,
     *     warnings: array<int, string>
     * }>  $partnerProfiles
     * @return array<int, array{
     *     ssid_profile_name: string,
     *     raw_vlan: string|null,
     *     vlan_name: string|null,
     *     body: array<string, mixed>,
     *     warnings: array<int, string>
     * }>
     */
    private function mergeWlanProfiles(array $primaryProfiles, array $partnerProfiles): array
    {
        return $this->deduplicateWlanProfiles(array_merge($primaryProfiles, $partnerProfiles));
    }

    /**
     * @param  array<int, array{
     *     name: string,
     *     host: string|null,
     *     has_coa: bool,
     *     body: array<string, mixed>,
     *     warnings: array<int, string>
     * }>  $primaryServers
     * @param  array<int, array{
     *     name: string,
     *     host: string|null,
     *     has_coa: bool,
     *     body: array<string, mixed>,
     *     warnings: array<int, string>
     * }>  $partnerServers
     * @return array<int, array{
     *     name: string,
     *     host: string|null,
     *     has_coa: bool,
     *     body: array<string, mixed>,
     *     warnings: array<int, string>
     * }>
     */
    private function mergeAuthServers(array $primaryServers, array $partnerServers): array
    {
        return $this->deduplicateAuthServers(array_merge($primaryServers, $partnerServers));
    }

    /**
     * @param  array<int, array{
     *     name: string,
     *     servers: array<int, array{server-name: string, position: int}>,
     *     body: array<string, mixed>,
     *     associated_essids: array<int, string>,
     *     warnings: array<int, string>
     * }>  $primaryGroups
     * @param  array<int, array{
     *     name: string,
     *     servers: array<int, array{server-name: string, position: int}>,
     *     body: array<string, mixed>,
     *     associated_essids: array<int, string>,
     *     warnings: array<int, string>
     * }>  $partnerGroups
     * @return array<int, array{
     *     name: string,
     *     servers: array<int, array{server-name: string, position: int}>,
     *     body: array<string, mixed>,
     *     associated_essids: array<int, string>,
     *     warnings: array<int, string>
     * }>
     */
    private function mergeServerGroups(array $primaryGroups, array $partnerGroups): array
    {
        return $this->deduplicateServerGroups(array_merge($primaryGroups, $partnerGroups));
    }

    /**
     * @param  array<int, array{
     *     name: string,
     *     servers: array<int, array{server-name: string, position: int}>,
     *     body: array<string, mixed>,
     *     associated_essids: array<int, string>,
     *     warnings: array<int, string>
     * }>  $groups
     * @return array<int, array{
     *     name: string,
     *     servers: array<int, array{server-name: string, position: int}>,
     *     body: array<string, mixed>,
     *     associated_essids: array<int, string>,
     *     warnings: array<int, string>
     * }>
     */
    private function deduplicateServerGroups(array $groups): array
    {
        $byName = [];

        foreach ($groups as $group) {
            $name = $group['name'];

            if (! isset($byName[$name])) {
                $byName[$name] = $group;

                continue;
            }

            $byName[$name] = $this->preferServerGroup($byName[$name], $group);
        }

        $deduplicated = array_values($byName);
        usort(
            $deduplicated,
            fn (array $a, array $b): int => strcmp($a['name'], $b['name']),
        );

        return $deduplicated;
    }

    /**
     * @param  array{
     *     name: string,
     *     servers: array<int, array{server-name: string, position: int}>,
     *     body: array<string, mixed>,
     *     associated_essids: array<int, string>,
     *     warnings: array<int, string>
     * }  $first
     * @param  array{
     *     name: string,
     *     servers: array<int, array{server-name: string, position: int}>,
     *     body: array<string, mixed>,
     *     associated_essids: array<int, string>,
     *     warnings: array<int, string>
     * }  $second
     * @return array{
     *     name: string,
     *     servers: array<int, array{server-name: string, position: int}>,
     *     body: array<string, mixed>,
     *     associated_essids: array<int, string>,
     *     warnings: array<int, string>
     * }
     */
    private function preferServerGroup(array $first, array $second): array
    {
        $preferred = count($second['servers']) > count($first['servers']) ? $second : $first;
        $other = $preferred === $first ? $second : $first;

        $essids = array_values(array_unique(array_merge(
            $preferred['associated_essids'],
            $other['associated_essids'],
        )));
        sort($essids);
        $preferred['associated_essids'] = $essids;

        return $preferred;
    }

    /**
     * @param  array<int, array{
     *     name: string,
     *     host: string|null,
     *     has_coa: bool,
     *     body: array<string, mixed>,
     *     warnings: array<int, string>
     * }>  $servers
     * @return array<int, array{
     *     name: string,
     *     host: string|null,
     *     has_coa: bool,
     *     body: array<string, mixed>,
     *     warnings: array<int, string>
     * }>
     */
    private function deduplicateAuthServers(array $servers): array
    {
        $byName = [];

        foreach ($servers as $server) {
            $name = $server['name'];

            if (! isset($byName[$name])) {
                $byName[$name] = $server;

                continue;
            }

            $byName[$name] = $this->preferAuthServer($byName[$name], $server);
        }

        $deduplicated = array_values($byName);
        usort(
            $deduplicated,
            fn (array $a, array $b): int => strcmp($a['name'], $b['name']),
        );

        return $deduplicated;
    }

    /**
     * @param  array{
     *     name: string,
     *     host: string|null,
     *     has_coa: bool,
     *     body: array<string, mixed>,
     *     warnings: array<int, string>
     * }  $first
     * @param  array{
     *     name: string,
     *     host: string|null,
     *     has_coa: bool,
     *     body: array<string, mixed>,
     *     warnings: array<int, string>
     * }  $second
     * @return array{
     *     name: string,
     *     host: string|null,
     *     has_coa: bool,
     *     body: array<string, mixed>,
     *     warnings: array<int, string>
     * }
     */
    private function preferAuthServer(array $first, array $second): array
    {
        $firstScore = $this->authServerCompletenessScore($first);
        $secondScore = $this->authServerCompletenessScore($second);

        if ($secondScore > $firstScore) {
            return $second;
        }

        return $first;
    }

    /**
     * @param  array{
     *     name: string,
     *     host: string|null,
     *     has_coa: bool,
     *     body: array<string, mixed>,
     *     warnings: array<int, string>
     * }  $server
     */
    private function authServerCompletenessScore(array $server): int
    {
        $score = 0;

        if (($server['host'] ?? null) !== null && $server['host'] !== '') {
            $score += 2;
        }

        $secret = $server['body']['shared-secret-config']['plaintext-value'] ?? null;
        if ($secret !== null && $secret !== '') {
            $score += 2;
        }

        if ($server['has_coa'] ?? false) {
            $score += 1;
        }

        $score -= count($server['warnings'] ?? []);

        return $score;
    }

    /**
     * @param  array<int, array{
     *     ssid_profile_name: string,
     *     raw_vlan: string|null,
     *     vlan_name: string|null,
     *     body: array<string, mixed>,
     *     warnings: array<int, string>
     * }>  $profiles
     * @return array<int, array{
     *     ssid_profile_name: string,
     *     raw_vlan: string|null,
     *     vlan_name: string|null,
     *     body: array<string, mixed>,
     *     warnings: array<int, string>
     * }>
     */
    private function deduplicateWlanProfiles(array $profiles): array
    {
        $bySsid = [];

        foreach ($profiles as $profile) {
            $ssid = $profile['ssid_profile_name'];

            if (! isset($bySsid[$ssid])) {
                $bySsid[$ssid] = $profile;

                continue;
            }

            $bySsid[$ssid] = $this->preferWlanProfile($bySsid[$ssid], $profile);
        }

        $deduplicated = array_values($bySsid);
        usort(
            $deduplicated,
            fn (array $a, array $b): int => strcmp($a['ssid_profile_name'], $b['ssid_profile_name']),
        );

        return $deduplicated;
    }

    /**
     * @param  array{
     *     ssid_profile_name: string,
     *     raw_vlan: string|null,
     *     vlan_name: string|null,
     *     body: array<string, mixed>,
     *     warnings: array<int, string>
     * }  $first
     * @param  array{
     *     ssid_profile_name: string,
     *     raw_vlan: string|null,
     *     vlan_name: string|null,
     *     body: array<string, mixed>,
     *     warnings: array<int, string>
     * }  $second
     * @return array{
     *     ssid_profile_name: string,
     *     raw_vlan: string|null,
     *     vlan_name: string|null,
     *     body: array<string, mixed>,
     *     warnings: array<int, string>
     * }
     */
    private function preferWlanProfile(array $first, array $second): array
    {
        $firstMissingVlan = in_array('Missing vlan from virtual-ap', $first['warnings'], true);
        $secondMissingVlan = in_array('Missing vlan from virtual-ap', $second['warnings'], true);

        if ($firstMissingVlan && ! $secondMissingVlan) {
            return $second;
        }

        if ($secondMissingVlan && ! $firstMissingVlan) {
            return $first;
        }

        return $first;
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
     *     name: string,
     *     host: string|null,
     *     has_coa: bool,
     *     body: array<string, mixed>,
     *     warnings: array<int, string>
     * }>
     */
    private function parseAuthServers(string $content): array
    {
        $section = $this->extractSection($content, '/#show running-config/i', null);

        if ($section === null) {
            return [];
        }

        $radiusServers = $this->parseRadiusAuthServerBlocks($section);
        $rfc3576Servers = $this->parseRfc3576ServerBlocks($section);
        $servers = [];

        foreach ($radiusServers as $name => $data) {
            $host = $data['host'];
            $key = $data['key'];
            $hasCoa = $this->hasMatchingRfc3576Server($host, $key, $rfc3576Servers);
            $warnings = $this->buildAuthServerWarnings($host, $key);

            $servers[] = [
                'name' => $name,
                'host' => $host,
                'has_coa' => $hasCoa,
                'body' => $this->buildAuthServerBody($name, $host, $key, $hasCoa),
                'warnings' => $warnings,
            ];
        }

        return $servers;
    }

    /**
     * @return array<string, array{host: string|null, key: string|null}>
     */
    private function parseRadiusAuthServerBlocks(string $section): array
    {
        $servers = [];
        $currentName = null;
        $currentLines = [];

        foreach (explode("\n", $section) as $line) {
            $trimmed = rtrim($line);

            if (preg_match('/^aaa authentication-server radius "([^"]+)"/', $trimmed, $match)) {
                if ($currentName !== null) {
                    $servers[$currentName] = $this->parseRadiusAuthServerLines($currentLines);
                }

                $currentName = $match[1];
                $currentLines = [];

                continue;
            }

            if ($trimmed === '!' && $currentName !== null) {
                $servers[$currentName] = $this->parseRadiusAuthServerLines($currentLines);
                $currentName = null;
                $currentLines = [];

                continue;
            }

            if ($currentName !== null) {
                $currentLines[] = $trimmed;
            }
        }

        if ($currentName !== null) {
            $servers[$currentName] = $this->parseRadiusAuthServerLines($currentLines);
        }

        return $servers;
    }

    /**
     * @param  array<int, string>  $lines
     * @return array{host: string|null, key: string|null}
     */
    private function parseRadiusAuthServerLines(array $lines): array
    {
        $data = [
            'host' => null,
            'key' => null,
        ];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^host "([^"]*)"/', $line, $match)) {
                $data['host'] = $match[1];
            } elseif (preg_match('/^key "([^"]*)"/', $line, $match)) {
                $data['key'] = $match[1];
            }
        }

        return $data;
    }

    /**
     * @return array<string, array{key: string|null}>
     */
    private function parseRfc3576ServerBlocks(string $section): array
    {
        $servers = [];
        $currentIp = null;
        $currentLines = [];

        foreach (explode("\n", $section) as $line) {
            $trimmed = rtrim($line);

            if (preg_match('/^aaa rfc-3576-server "([^"]+)"/', $trimmed, $match)) {
                if ($currentIp !== null) {
                    $servers[$currentIp] = $this->parseRfc3576ServerLines($currentLines);
                }

                $currentIp = $match[1];
                $currentLines = [];

                continue;
            }

            if ($trimmed === '!' && $currentIp !== null) {
                $servers[$currentIp] = $this->parseRfc3576ServerLines($currentLines);
                $currentIp = null;
                $currentLines = [];

                continue;
            }

            if ($currentIp !== null) {
                $currentLines[] = $trimmed;
            }
        }

        if ($currentIp !== null) {
            $servers[$currentIp] = $this->parseRfc3576ServerLines($currentLines);
        }

        return $servers;
    }

    /**
     * @param  array<int, string>  $lines
     * @return array{key: string|null}
     */
    private function parseRfc3576ServerLines(array $lines): array
    {
        $key = null;

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^key "([^"]*)"/', $line, $match)) {
                $key = $match[1];
            }
        }

        return ['key' => $key];
    }

    /**
     * @param  array<string, array{key: string|null}>  $rfc3576Servers
     */
    private function hasMatchingRfc3576Server(?string $host, ?string $key, array $rfc3576Servers): bool
    {
        if ($host === null || $host === '' || $key === null || $key === '') {
            return false;
        }

        $rfcServer = $rfc3576Servers[$host] ?? null;

        if ($rfcServer === null) {
            return false;
        }

        return ($rfcServer['key'] ?? null) === $key;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAuthServerBody(string $name, ?string $host, ?string $key, bool $hasCoa): array
    {
        $body = [
            'auth-server-address' => $host,
            'enable' => true,
            'name' => $name,
            'shared-secret-config' => [
                'plaintext-value' => $key,
                'secret-type' => 'PLAIN_TEXT',
            ],
            'type' => 'RADIUS',
        ];

        if ($hasCoa) {
            $body['dynamic-authorization-enable'] = true;
            $body['radius-server-mode'] = 'AUTH_AND_COA';
        }

        return $body;
    }

    /**
     * @return array<int, string>
     */
    private function buildAuthServerWarnings(?string $host, ?string $key): array
    {
        $warnings = [];

        if ($host === null || $host === '') {
            $warnings[] = 'Missing host';
        }

        if ($key === null || $key === '') {
            $warnings[] = 'Missing key';
        }

        return $warnings;
    }

    /**
     * @param  array<int, array{
     *     ssid_profile_name: string,
     *     raw_vlan: string|null,
     *     vlan_name: string|null,
     *     body: array<string, mixed>,
     *     warnings: array<int, string>
     * }>  $wlanProfiles
     * @return array<int, array{
     *     name: string,
     *     servers: array<int, array{server-name: string, position: int}>,
     *     body: array<string, mixed>,
     *     associated_essids: array<int, string>,
     *     warnings: array<int, string>
     * }>
     */
    private function parseServerGroups(string $content, array $wlanProfiles): array
    {
        $section = $this->extractSection($content, '/#show running-config/i', null);

        if ($section === null) {
            return [];
        }

        $essidsByGroup = [];

        foreach ($wlanProfiles as $profile) {
            $essid = $profile['ssid_profile_name'];
            $authGroup = $profile['body']['auth-server-group'] ?? null;
            $acctGroup = $profile['body']['acct-server-group'] ?? null;

            if (is_string($authGroup) && $authGroup !== '') {
                $essidsByGroup[$authGroup][$essid] = true;
            }

            if (is_string($acctGroup) && $acctGroup !== '') {
                $essidsByGroup[$acctGroup][$essid] = true;
            }
        }

        $groups = [];

        foreach ($this->parseServerGroupBlocks($section) as $name => $servers) {
            $associated = array_keys($essidsByGroup[$name] ?? []);
            sort($associated);

            $warnings = [];
            if ($servers === []) {
                $warnings[] = 'No auth-server entries';
            }

            $groups[] = [
                'name' => $name,
                'servers' => $servers,
                'body' => [
                    'name' => $name,
                    'type' => 'RADIUS',
                    'servers' => $servers,
                ],
                'associated_essids' => $associated,
                'warnings' => $warnings,
            ];
        }

        return $groups;
    }

    /**
     * @return array<string, array<int, array{server-name: string, position: int}>>
     */
    private function parseServerGroupBlocks(string $section): array
    {
        $groups = [];
        $currentName = null;
        $currentLines = [];

        foreach (explode("\n", $section) as $line) {
            $trimmed = rtrim($line);

            if (preg_match('/^aaa server-group "([^"]+)"/', $trimmed, $match)) {
                if ($currentName !== null) {
                    $groups[$currentName] = $this->parseServerGroupLines($currentLines);
                }

                $currentName = $match[1];
                $currentLines = [];

                continue;
            }

            if ($trimmed === '!' && $currentName !== null) {
                $groups[$currentName] = $this->parseServerGroupLines($currentLines);
                $currentName = null;
                $currentLines = [];

                continue;
            }

            if ($currentName !== null) {
                $currentLines[] = $trimmed;
            }
        }

        if ($currentName !== null) {
            $groups[$currentName] = $this->parseServerGroupLines($currentLines);
        }

        return $groups;
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, array{server-name: string, position: int}>
     */
    private function parseServerGroupLines(array $lines): array
    {
        $servers = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^auth-server "?([^"\s]+)"? position (\d+)/', $line, $match)) {
                $servers[] = [
                    'server-name' => $match[1],
                    'position' => (int) $match[2],
                ];
            }
        }

        usort(
            $servers,
            fn (array $a, array $b): int => $a['position'] <=> $b['position'],
        );

        return $servers;
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
        $virtualApBySsidProfile = $this->parseVirtualApMap($section);
        $aaaProfiles = $this->parseAaaProfileBlocks($section);

        $profiles = [];

        foreach ($ssidProfiles as $profileName => $ssidData) {
            $virtualApData = $virtualApBySsidProfile[$profileName] ?? null;
            $rawVlan = $virtualApData['vlan'] ?? null;
            $allowedBand = $virtualApData['allowed_band'] ?? null;
            $aaaProfileName = $virtualApData['aaa_profile'] ?? null;
            $deployName = ($ssidData['essid'] !== null && $ssidData['essid'] !== '')
                ? $ssidData['essid']
                : $profileName;
            $security = $this->resolveSsidSecurity($ssidData, $aaaProfileName, $aaaProfiles);
            $vlanName = $rawVlan !== null ? self::mapVlanName($rawVlan) : null;
            $warnings = $this->buildProfileWarnings(
                $ssidData,
                $rawVlan,
                $vlanName,
                $security,
                $aaaProfileName,
            );
            $profiles[] = [
                'ssid_profile_name' => $deployName,
                'raw_vlan' => $rawVlan,
                'vlan_name' => $vlanName,
                'body' => $this->buildWlanSsidProfileBody(
                    $deployName,
                    $ssidData,
                    $vlanName,
                    $allowedBand,
                    $security,
                ),
                'warnings' => $warnings,
            ];
        }

        return $profiles;
    }

    /**
     * @return array<string, array{
     *     essid: string|null,
     *     wpa_passphrase: string|null,
     *     opmode_tokens: array<int, string>,
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
     *     opmode_tokens: array<int, string>,
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
            'opmode_tokens' => [],
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
            } elseif (preg_match('/^opmode (.+)$/', $line, $match)) {
                $data['opmode_tokens'] = preg_split('/\s+/', trim($match[1])) ?: [];
                $data['opmode_tokens'] = array_values(array_filter(
                    $data['opmode_tokens'],
                    fn (string $token): bool => $token !== '',
                ));
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
     * @return array<string, array{vlan: ?string, allowed_band: ?string, aaa_profile: ?string}>
     */
    private function parseVirtualApMap(string $section): array
    {
        $map = [];
        $currentSsidProfile = null;
        $currentVlan = null;
        $currentAllowedBand = null;
        $currentAaaProfile = null;

        $flush = function () use (&$map, &$currentSsidProfile, &$currentVlan, &$currentAllowedBand, &$currentAaaProfile): void {
            if ($currentSsidProfile === null) {
                return;
            }

            if ($currentVlan === null && $currentAaaProfile === null && $currentAllowedBand === null) {
                return;
            }

            $map[$currentSsidProfile] = [
                'vlan' => $currentVlan,
                'allowed_band' => $currentAllowedBand,
                'aaa_profile' => $currentAaaProfile,
            ];
        };

        foreach (explode("\n", $section) as $line) {
            $trimmed = rtrim($line);

            if (preg_match('/^wlan virtual-ap "/', $trimmed)) {
                $flush();
                $currentSsidProfile = null;
                $currentVlan = null;
                $currentAllowedBand = null;
                $currentAaaProfile = null;

                continue;
            }

            if ($trimmed === '!' && $currentSsidProfile !== null) {
                $flush();
                $currentSsidProfile = null;
                $currentVlan = null;
                $currentAllowedBand = null;
                $currentAaaProfile = null;

                continue;
            }

            if (preg_match('/^\s+ssid-profile "([^"]+)"/', $trimmed, $match)) {
                $currentSsidProfile = $match[1];
            } elseif (preg_match('/^\s+vlan (\S+)/', $trimmed, $match)) {
                $currentVlan = $match[1];
            } elseif (preg_match('/^\s+allowed-band (\S+)/', $trimmed, $match)) {
                $currentAllowedBand = $match[1];
            } elseif (preg_match('/^\s+aaa-profile "([^"]+)"/', $trimmed, $match)) {
                $currentAaaProfile = $match[1];
            }
        }

        $flush();

        return $map;
    }

    /**
     * @return array<string, array{dot1x_server_group: ?string, radius_accounting: ?string}>
     */
    private function parseAaaProfileBlocks(string $section): array
    {
        $profiles = [];
        $currentName = null;
        $currentLines = [];

        foreach (explode("\n", $section) as $line) {
            $trimmed = rtrim($line);

            if (preg_match('/^aaa profile "([^"]+)"/', $trimmed, $match)) {
                if ($currentName !== null) {
                    $profiles[$currentName] = $this->parseAaaProfileLines($currentLines);
                }

                $currentName = $match[1];
                $currentLines = [];

                continue;
            }

            if ($trimmed === '!' && $currentName !== null) {
                $profiles[$currentName] = $this->parseAaaProfileLines($currentLines);
                $currentName = null;
                $currentLines = [];

                continue;
            }

            if ($currentName !== null) {
                $currentLines[] = $trimmed;
            }
        }

        if ($currentName !== null) {
            $profiles[$currentName] = $this->parseAaaProfileLines($currentLines);
        }

        return $profiles;
    }

    /**
     * @param  array<int, string>  $lines
     * @return array{dot1x_server_group: ?string, radius_accounting: ?string}
     */
    private function parseAaaProfileLines(array $lines): array
    {
        $data = [
            'dot1x_server_group' => null,
            'radius_accounting' => null,
        ];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^dot1x-server-group "([^"]+)"/', $line, $match)) {
                $data['dot1x_server_group'] = $match[1];
            } elseif (preg_match('/^radius-accounting "([^"]+)"/', $line, $match)) {
                $data['radius_accounting'] = $match[1];
            }
        }

        return $data;
    }

    /**
     * @param  array{
     *     essid: string|null,
     *     wpa_passphrase: string|null,
     *     opmode_tokens: array<int, string>,
     *     a_basic_rates: array<int, string>,
     *     a_tx_rates: array<int, string>,
     *     g_basic_rates: array<int, string>,
     *     g_tx_rates: array<int, string>,
     *     advertise_ap_name: bool
     * }  $ssidData
     * @param  array<string, array{dot1x_server_group: ?string, radius_accounting: ?string}>  $aaaProfiles
     * @return array{
     *     mode: 'personal'|'open'|'enterprise',
     *     opmode: string|null,
     *     auth_server_group: string|null,
     *     acct_server_group: string|null,
     *     has_radius_accounting: bool,
     *     unmapped_opmode: bool
     * }
     */
    private function resolveSsidSecurity(array $ssidData, ?string $aaaProfileName, array $aaaProfiles): array
    {
        $tokens = $ssidData['opmode_tokens'];
        $isPersonalPath = $tokens === [] || $this->isPersonalOpmodePath($tokens);
        $mappedOpmode = $this->mapOpmodeToCentral($tokens);
        $unmapped = $tokens !== [] && $mappedOpmode === null;

        if ($isPersonalPath) {
            $isOpen = $tokens === ['opensystem'];

            return [
                'mode' => $isOpen ? 'open' : 'personal',
                'opmode' => $mappedOpmode ?? 'WPA2_PERSONAL',
                'auth_server_group' => null,
                'acct_server_group' => null,
                'has_radius_accounting' => false,
                'unmapped_opmode' => $unmapped,
            ];
        }

        $aaa = $aaaProfileName !== null ? ($aaaProfiles[$aaaProfileName] ?? null) : null;

        return [
            'mode' => 'enterprise',
            'opmode' => $mappedOpmode,
            'auth_server_group' => $aaa['dot1x_server_group'] ?? null,
            'acct_server_group' => $aaa['radius_accounting'] ?? null,
            'has_radius_accounting' => ($aaa['radius_accounting'] ?? null) !== null,
            'unmapped_opmode' => $unmapped,
        ];
    }

    /**
     * @param  array<int, string>  $tokens
     */
    private function isPersonalOpmodePath(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (! in_array($token, self::PERSONAL_OPMODE_TOKENS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $tokens
     */
    private function mapOpmodeToCentral(array $tokens): ?string
    {
        if ($tokens === []) {
            return 'WPA2_PERSONAL';
        }

        $sorted = $tokens;
        sort($sorted);

        if ($sorted === ['wpa-aes', 'wpa-tkip', 'wpa2-aes']) {
            return 'BOTH_WPA_WPA2_DOT1X';
        }

        if (count($tokens) === 1) {
            return match ($tokens[0]) {
                'opensystem' => 'OPEN',
                'wpa-psk-aes', 'wpa-psk-tkip', 'wpa2-psk-aes' => 'WPA2_PERSONAL',
                'wpa3-sae-aes' => 'WPA3_SAE',
                'wpa2-aes' => 'WPA2_ENTERPRISE',
                'wpa3-aes-ccm-128' => 'WPA3_AES_CCM_128',
                'wpa3-aes-gcm-256' => 'WPA3_ENTERPRISE_GCM_256',
                default => null,
            };
        }

        if ($this->isPersonalOpmodePath($tokens)) {
            if (in_array('wpa3-sae-aes', $tokens, true)) {
                return 'WPA3_SAE';
            }

            if (in_array('opensystem', $tokens, true) && count($tokens) === 1) {
                return 'OPEN';
            }

            return 'WPA2_PERSONAL';
        }

        return null;
    }

    /**
     * @param  array{
     *     essid: string|null,
     *     wpa_passphrase: string|null,
     *     opmode_tokens: array<int, string>,
     *     a_basic_rates: array<int, string>,
     *     a_tx_rates: array<int, string>,
     *     g_basic_rates: array<int, string>,
     *     g_tx_rates: array<int, string>,
     *     advertise_ap_name: bool
     * }  $ssidData
     * @param  array{
     *     mode: 'personal'|'open'|'enterprise',
     *     opmode: string|null,
     *     auth_server_group: string|null,
     *     acct_server_group: string|null,
     *     has_radius_accounting: bool,
     *     unmapped_opmode: bool
     * }  $security
     * @return array<string, mixed>
     */
    private function buildWlanSsidProfileBody(
        string $ssidName,
        array $ssidData,
        ?string $vlanName,
        ?string $allowedBand,
        array $security,
    ): array {
        $body = [
            'ssid' => $ssidName,
            'enable' => true,
            'forward-mode' => 'FORWARD_MODE_BRIDGE',
            'dmo' => [
                'enable' => false,
                'channel-utilization-threshold' => 90,
                'clients-threshold' => 6,
            ],
            'broadcast-filter-ipv4' => 'BCAST_FILTER_ARP',
            'local-proxy-ns' => false,
            'optimize-mcast-rate' => false,
            'ssid-utf8' => true,
            'essid' => ['name' => $ssidData['essid']],
            'advertise-apname' => true,
            'disable-on-6ghz-mesh' => false,
            'dot11k' => false,
            'dot11r' => false,
            'dtim-period' => 1,
            'ftm-responder' => false,
            'hide-ssid' => false,
            'explicit-ageout-client' => false,
            'inactivity-timeout' => 1000,
            'max-clients-threshold' => 128,
            'rf-band' => '24GHZ_5GHZ',
            'high-throughput' => ['enable' => true, 'very-high-throughput' => true],
            'g-legacy-rates' => [
                'basic-rates' => ['RATE_12MB', 'RATE_24MB'],
                'tx-rates' => ['RATE_12MB', 'RATE_18MB', 'RATE_24MB', 'RATE_36MB', 'RATE_48MB', 'RATE_54MB'],
            ],
            'a-legacy-rates' => [
                'basic-rates' => ['RATE_12MB', 'RATE_24MB'],
                'tx-rates' => ['RATE_24MB', 'RATE_36MB', 'RATE_48MB', 'RATE_54MB'],
            ],
            'high-efficiency' => ['enable' => true],
            'extremely-high-throughput' => ['enable' => false, 'mlo' => false],
            'wmm-cfg' => ['uapsd' => false],
            'advertise-timing' => false,
            'mac-authentication' => false,
            'type' => 'EMPLOYEE',
            'use-ip-for-calling-station-id' => false,
            'server-load-balancing' => false,
            'called-station-id' => [
                'type' => 'MAC_ADDRESS',
                'include-ssid' => false,
            ],
            'cloud-auth' => false,
            'denylist' => false,
            'enforce-dhcp' => false,
            'pan' => false,
            'vlan-selector' => 'NAMED_VLAN',
            'vlan-name' => $vlanName,
            'client-isolation' => false,
        ];

        if ($security['opmode'] !== null) {
            $body['opmode'] = $security['opmode'];
        } elseif ($security['mode'] !== 'enterprise') {
            $body['opmode'] = 'WPA2_PERSONAL';
        }

        if ($security['mode'] === 'personal') {
            $body['personal-security'] = [
                'passphrase-format' => 'STRING',
                'wpa-passphrase' => $ssidData['wpa_passphrase'],
            ];
        } elseif ($security['mode'] === 'enterprise') {
            $body['dot1x'] = true;

            if ($security['auth_server_group'] !== null) {
                $body['auth-server-group'] = $security['auth_server_group'];
            }

            if ($security['has_radius_accounting'] && $security['acct_server_group'] !== null) {
                $body['acct-server-group'] = $security['acct_server_group'];
                $body['radius-accounting'] = true;
            }
        }

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
     *     opmode_tokens: array<int, string>,
     *     a_basic_rates: array<int, string>,
     *     a_tx_rates: array<int, string>,
     *     g_basic_rates: array<int, string>,
     *     g_tx_rates: array<int, string>,
     *     advertise_ap_name: bool
     * }  $ssidData
     * @param  array{
     *     mode: 'personal'|'open'|'enterprise',
     *     opmode: string|null,
     *     auth_server_group: string|null,
     *     acct_server_group: string|null,
     *     has_radius_accounting: bool,
     *     unmapped_opmode: bool
     * }  $security
     * @return array<int, string>
     */
    private function buildProfileWarnings(
        array $ssidData,
        ?string $rawVlan,
        ?string $vlanName,
        array $security,
        ?string $aaaProfileName = null,
    ): array {
        $warnings = [];

        if ($ssidData['essid'] === null || $ssidData['essid'] === '') {
            $warnings[] = 'Missing essid';
        }

        if ($security['mode'] === 'personal'
            && ($ssidData['wpa_passphrase'] === null || $ssidData['wpa_passphrase'] === '')
        ) {
            $warnings[] = 'Missing wpa-passphrase';
        }

        if ($security['unmapped_opmode']) {
            $warnings[] = 'Unmapped opmode: '.implode(' ', $ssidData['opmode_tokens']);
        }

        if ($security['mode'] === 'enterprise') {
            if ($aaaProfileName === null) {
                $warnings[] = 'Missing aaa-profile from virtual-ap';
            } elseif ($security['auth_server_group'] === null) {
                $warnings[] = 'Missing dot1x-server-group from aaa profile';
            }
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
