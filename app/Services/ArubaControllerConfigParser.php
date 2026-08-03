<?php

namespace App\Services;

class ArubaControllerConfigParser
{
    private const AP_ROW_PATTERN = '/^(\S+)\s+(\S+)\s+.*?([0-9a-f]{2}(?::[0-9a-f]{2}){5})\s+(\S+)/i';

    private const LLDP_ROW_PATTERN = '/^(\S+)\s+\S+\s+\d+\s+(\S+)\s+(\S+)\s+/';

    private const RUNNING_CONFIG_PATTERN = '/#show running-config(?:uration)?/i';

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

        $sharedRunningConfig = $this->findSharedRunningConfig($content);
        $parsedControllers = [];

        foreach ($controllerBlocks as $block) {
            $blockContent = $this->withRunningConfig($block['content'], $sharedRunningConfig);
            $parsedNames = array_column($parsedControllers, 'controller_name');
            $pairedName = $this->findParsedPair($block['name'], $parsedNames);

            if ($pairedName !== null) {
                $pairedIndex = array_search($pairedName, $parsedNames, true);
                $partnerDevices = $this->parseApDatabase($blockContent);
                $partnerApGroups = $this->uniqueApGroups($partnerDevices);

                $parsedControllers[$pairedIndex]['lldp_neighbors'] = $this->mergeLldpNeighbors(
                    $parsedControllers[$pairedIndex]['lldp_neighbors'],
                    $this->parseLldpNeighbors($blockContent),
                );
                $partnerWlanProfiles = $this->parseWlanProfiles($blockContent, $partnerApGroups);
                $parsedControllers[$pairedIndex]['wlan_profiles'] = $this->mergeWlanProfiles(
                    $parsedControllers[$pairedIndex]['wlan_profiles'],
                    $partnerWlanProfiles,
                );
                $parsedControllers[$pairedIndex]['radio_profiles'] = $this->mergeRadioProfiles(
                    $parsedControllers[$pairedIndex]['radio_profiles'],
                    $this->parseRadioProfiles($blockContent, $partnerApGroups),
                );
                $parsedControllers[$pairedIndex]['auth_servers'] = $this->mergeAuthServers(
                    $parsedControllers[$pairedIndex]['auth_servers'],
                    $this->parseAuthServers($blockContent),
                );
                $parsedControllers[$pairedIndex]['server_groups'] = $this->mergeServerGroups(
                    $parsedControllers[$pairedIndex]['server_groups'],
                    $this->parseServerGroups($blockContent, $partnerWlanProfiles),
                );
                $parsedControllers[$pairedIndex]['user_roles'] = $this->mergeUserRoles(
                    $parsedControllers[$pairedIndex]['user_roles'],
                    $this->parseUserRoles($blockContent),
                );

                continue;
            }

            $parsedControllers[] = $this->parseControllerBlock($block['name'], $blockContent);
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
        if (! preg_match_all('/\(([^)]+)\)(?:\s+\[MDC\])?\s*\*?#\s*show ap database long/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
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
     *     devices: array<int, array{name: string, serial: string, mac: string, group: string}>,
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
     *         enabled: bool,
     *         body: array<string, mixed>,
     *         warnings: array<int, string>
     *     }>,
     *     radio_profiles: array<int, array{
     *         ap_group: string,
     *         profile_name: string,
     *         band: string,
     *         eirp_min: int|null,
     *         eirp_max: int|null
     *     }>,
     *     user_roles: array<int, array{
     *         name: string,
     *         access_lists: array<int, array{
     *             name: string,
     *             rules: array<int, array<string, mixed>>,
     *             warnings: array<int, string>
     *         }>,
     *         warnings: array<int, string>
     *     }>
     * }
     */
    private function parseControllerBlock(string $controllerName, string $content): array
    {
        $devices = $this->parseApDatabase($content);
        $apGroups = $this->uniqueApGroups($devices);
        $wlanProfiles = $this->deduplicateWlanProfiles($this->parseWlanProfiles($content, $apGroups));

        return [
            'controller_name' => $controllerName,
            'devices' => $devices,
            'lldp_neighbors' => $this->parseLldpNeighbors($content),
            'auth_servers' => $this->deduplicateAuthServers($this->parseAuthServers($content)),
            'server_groups' => $this->deduplicateServerGroups($this->parseServerGroups($content, $wlanProfiles)),
            'wlan_profiles' => $wlanProfiles,
            'radio_profiles' => $this->deduplicateRadioProfiles($this->parseRadioProfiles($content, $apGroups)),
            'user_roles' => $this->deduplicateUserRoles($this->parseUserRoles($content)),
        ];
    }

    /**
     * When the dump has exactly one running-config section, return it for reuse across controllers.
     */
    private function findSharedRunningConfig(string $content): ?string
    {
        if (! preg_match_all(self::RUNNING_CONFIG_PATTERN, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        if (count($matches[0]) !== 1) {
            return null;
        }

        return $this->extractSection($content, self::RUNNING_CONFIG_PATTERN, null);
    }

    private function withRunningConfig(string $blockContent, ?string $sharedRunningConfig): string
    {
        if ($this->extractSection($blockContent, self::RUNNING_CONFIG_PATTERN, null) !== null) {
            return $blockContent;
        }

        if ($sharedRunningConfig === null) {
            return $blockContent;
        }

        return rtrim($blockContent)."\n\n".$sharedRunningConfig;
    }

    /**
     * @param  array<int, array{name: string, serial: string, mac: string, group: string}>  $devices
     * @return array<int, string>
     */
    private function uniqueApGroups(array $devices): array
    {
        $groups = [];

        foreach ($devices as $device) {
            $group = $device['group'] ?? '';

            if ($group !== '') {
                $groups[$group] = true;
            }
        }

        return array_keys($groups);
    }

    /**
     * @return array<int, array{name: string, serial: string, mac: string, group: string}>
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
                $serial = $match[4];

                if (isset($seenSerials[$serial])) {
                    continue;
                }

                $seenSerials[$serial] = true;
                $devices[] = [
                    'name' => $match[1],
                    'group' => $match[2],
                    'mac' => strtolower($match[3]),
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
        $section = $this->extractSection($content, '/#show ap lldp neighbors/i', '/(?:#show running-config(?:uration)?|\([^)]+\) #)/i');

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
     *     enabled: bool,
     *     body: array<string, mixed>,
     *     warnings: array<int, string>
     * }>  $primaryProfiles
     * @param  array<int, array{
     *     ssid_profile_name: string,
     *     raw_vlan: string|null,
     *     vlan_name: string|null,
     *     enabled: bool,
     *     body: array<string, mixed>,
     *     warnings: array<int, string>
     * }>  $partnerProfiles
     * @return array<int, array{
     *     ssid_profile_name: string,
     *     raw_vlan: string|null,
     *     vlan_name: string|null,
     *     enabled: bool,
     *     body: array<string, mixed>,
     *     warnings: array<int, string>
     * }>
     */
    private function mergeWlanProfiles(array $primaryProfiles, array $partnerProfiles): array
    {
        return $this->deduplicateWlanProfiles(array_merge($primaryProfiles, $partnerProfiles));
    }

    /**
     * @param  array<int, array{ap_group: string, profile_name: string, band: string, eirp_min: int|null, eirp_max: int|null}>  $primaryProfiles
     * @param  array<int, array{ap_group: string, profile_name: string, band: string, eirp_min: int|null, eirp_max: int|null}>  $partnerProfiles
     * @return array<int, array{ap_group: string, profile_name: string, band: string, eirp_min: int|null, eirp_max: int|null}>
     */
    private function mergeRadioProfiles(array $primaryProfiles, array $partnerProfiles): array
    {
        return $this->deduplicateRadioProfiles(array_merge($primaryProfiles, $partnerProfiles));
    }

    /**
     * @param  array<int, array{ap_group: string, profile_name: string, band: string, eirp_min: int|null, eirp_max: int|null}>  $profiles
     * @return array<int, array{ap_group: string, profile_name: string, band: string, eirp_min: int|null, eirp_max: int|null}>
     */
    private function deduplicateRadioProfiles(array $profiles): array
    {
        $byKey = [];

        foreach ($profiles as $profile) {
            $key = $profile['ap_group']."\0".$profile['band']."\0".$profile['profile_name'];
            $byKey[$key] = $profile;
        }

        $deduplicated = array_values($byKey);
        usort(
            $deduplicated,
            function (array $a, array $b): int {
                return strcmp($a['ap_group'], $b['ap_group'])
                    ?: strcmp($a['band'], $b['band'])
                    ?: strcmp($a['profile_name'], $b['profile_name']);
            },
        );

        return $deduplicated;
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
     *     access_lists: array<int, array{name: string, rules: array<int, array<string, mixed>>, warnings: array<int, string>}>,
     *     warnings: array<int, string>
     * }>  $primaryRoles
     * @param  array<int, array{
     *     name: string,
     *     access_lists: array<int, array{name: string, rules: array<int, array<string, mixed>>, warnings: array<int, string>}>,
     *     warnings: array<int, string>
     * }>  $partnerRoles
     * @return array<int, array{
     *     name: string,
     *     access_lists: array<int, array{name: string, rules: array<int, array<string, mixed>>, warnings: array<int, string>}>,
     *     warnings: array<int, string>
     * }>
     */
    private function mergeUserRoles(array $primaryRoles, array $partnerRoles): array
    {
        return $this->deduplicateUserRoles(array_merge($primaryRoles, $partnerRoles));
    }

    /**
     * @param  array<int, array{
     *     name: string,
     *     access_lists: array<int, array{name: string, rules: array<int, array<string, mixed>>, warnings: array<int, string>}>,
     *     warnings: array<int, string>
     * }>  $roles
     * @return array<int, array{
     *     name: string,
     *     access_lists: array<int, array{name: string, rules: array<int, array<string, mixed>>, warnings: array<int, string>}>,
     *     warnings: array<int, string>
     * }>
     */
    private function deduplicateUserRoles(array $roles): array
    {
        $byName = [];

        foreach ($roles as $role) {
            $name = $role['name'];

            if (! isset($byName[$name])) {
                $byName[$name] = $role;

                continue;
            }

            $byName[$name] = $this->preferUserRole($byName[$name], $role);
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
     *     access_lists: array<int, array{name: string, rules: array<int, array<string, mixed>>, warnings: array<int, string>}>,
     *     warnings: array<int, string>
     * }  $first
     * @param  array{
     *     name: string,
     *     access_lists: array<int, array{name: string, rules: array<int, array<string, mixed>>, warnings: array<int, string>}>,
     *     warnings: array<int, string>
     * }  $second
     * @return array{
     *     name: string,
     *     access_lists: array<int, array{name: string, rules: array<int, array<string, mixed>>, warnings: array<int, string>}>,
     *     warnings: array<int, string>
     * }
     */
    private function preferUserRole(array $first, array $second): array
    {
        $firstCount = count($first['access_lists']);
        $secondCount = count($second['access_lists']);

        if ($secondCount > $firstCount) {
            return $second;
        }

        if ($firstCount > $secondCount) {
            return $first;
        }

        $firstRules = array_sum(array_map(
            fn (array $acl): int => count($acl['rules']),
            $first['access_lists'],
        ));
        $secondRules = array_sum(array_map(
            fn (array $acl): int => count($acl['rules']),
            $second['access_lists'],
        ));

        return $secondRules > $firstRules ? $second : $first;
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
     *     enabled: bool,
     *     body: array<string, mixed>,
     *     warnings: array<int, string>
     * }>  $profiles
     * @return array<int, array{
     *     ssid_profile_name: string,
     *     raw_vlan: string|null,
     *     vlan_name: string|null,
     *     enabled: bool,
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
     *     enabled: bool,
     *     body: array<string, mixed>,
     *     warnings: array<int, string>
     * }  $first
     * @param  array{
     *     ssid_profile_name: string,
     *     raw_vlan: string|null,
     *     vlan_name: string|null,
     *     enabled: bool,
     *     body: array<string, mixed>,
     *     warnings: array<int, string>
     * }  $second
     * @return array{
     *     ssid_profile_name: string,
     *     raw_vlan: string|null,
     *     vlan_name: string|null,
     *     enabled: bool,
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
        $section = $this->extractSection($content, self::RUNNING_CONFIG_PATTERN, null);

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
        $section = $this->extractSection($content, self::RUNNING_CONFIG_PATTERN, null);

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
     * @param  array<int, string>  $apGroupNames
     * @return array<int, array{
     *     ssid_profile_name: string,
     *     raw_vlan: string|null,
     *     vlan_name: string|null,
     *     enabled: bool,
     *     body: array<string, mixed>,
     *     warnings: array<int, string>
     * }>
     */
    private function parseWlanProfiles(string $content, array $apGroupNames): array
    {
        $section = $this->extractSection($content, self::RUNNING_CONFIG_PATTERN, null);

        if ($section === null || $apGroupNames === []) {
            return [];
        }

        $ssidProfiles = $this->parseSsidProfileBlocks($section);
        $virtualApsByName = $this->parseVirtualApBlocks($section);
        $apGroups = $this->parseApGroupBlocks($section);
        $aaaProfiles = $this->parseAaaProfileBlocks($section);

        /** @var array<string, array{vlan: ?string, allowed_band: ?string, aaa_profile: ?string, vap_enabled: bool}> $virtualApBySsidProfile */
        $virtualApBySsidProfile = [];

        foreach ($apGroupNames as $groupName) {
            $group = $apGroups[$groupName] ?? null;

            if ($group === null) {
                continue;
            }

            foreach ($group['virtual_aps'] as $vapName) {
                $vap = $virtualApsByName[$vapName] ?? null;

                if ($vap === null || $vap['ssid_profile'] === null) {
                    continue;
                }

                $ssidProfileName = $vap['ssid_profile'];
                $candidate = [
                    'vlan' => $vap['vlan'],
                    'allowed_band' => $vap['allowed_band'],
                    'aaa_profile' => $vap['aaa_profile'],
                    'vap_enabled' => $vap['vap_enabled'],
                ];

                if (! isset($virtualApBySsidProfile[$ssidProfileName])) {
                    $virtualApBySsidProfile[$ssidProfileName] = $candidate;

                    continue;
                }

                $existing = $virtualApBySsidProfile[$ssidProfileName];

                if ($existing['vlan'] === null && $candidate['vlan'] !== null) {
                    $virtualApBySsidProfile[$ssidProfileName] = $candidate;
                } elseif ($candidate['vap_enabled'] && ! $existing['vap_enabled']) {
                    $virtualApBySsidProfile[$ssidProfileName] = $candidate;
                }
            }
        }

        $profiles = [];

        foreach ($virtualApBySsidProfile as $profileName => $virtualApData) {
            $ssidData = $ssidProfiles[$profileName] ?? null;

            if ($ssidData === null) {
                continue;
            }

            $rawVlan = $virtualApData['vlan'] ?? null;
            $allowedBand = $virtualApData['allowed_band'] ?? null;
            $aaaProfileName = $virtualApData['aaa_profile'] ?? null;
            $enabled = $virtualApData['vap_enabled'];
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
                'enabled' => $enabled,
                'body' => $this->buildWlanSsidProfileBody(
                    $deployName,
                    $ssidData,
                    $vlanName,
                    $allowedBand,
                    $security,
                    $enabled,
                ),
                'warnings' => $warnings,
            ];
        }

        return $profiles;
    }

    /**
     * @param  array<int, string>  $apGroupNames
     * @return array<int, array{ap_group: string, profile_name: string, band: string, eirp_min: int|null, eirp_max: int|null}>
     */
    private function parseRadioProfiles(string $content, array $apGroupNames): array
    {
        $section = $this->extractSection($content, self::RUNNING_CONFIG_PATTERN, null);

        if ($section === null || $apGroupNames === []) {
            return [];
        }

        $apGroups = $this->parseApGroupBlocks($section);
        $rfProfiles = $this->parseRfRadioProfileBlocks($section);
        $profiles = [];

        foreach ($apGroupNames as $groupName) {
            $group = $apGroups[$groupName] ?? null;

            if ($group === null) {
                continue;
            }

            foreach (['a', 'g'] as $band) {
                $profileName = $group['dot11'.$band.'_radio_profile'] ?? null;

                if ($profileName === null) {
                    continue;
                }

                $rfKey = $band."\0".$profileName;
                $rfData = $rfProfiles[$rfKey] ?? ['eirp_min' => null, 'eirp_max' => null];

                $profiles[] = [
                    'ap_group' => $groupName,
                    'profile_name' => $profileName,
                    'band' => $band,
                    'eirp_min' => $rfData['eirp_min'],
                    'eirp_max' => $rfData['eirp_max'],
                ];
            }
        }

        return $profiles;
    }

    /**
     * @return array<string, array{
     *     virtual_aps: array<int, string>,
     *     dot11a_radio_profile: string|null,
     *     dot11g_radio_profile: string|null
     * }>
     */
    private function parseApGroupBlocks(string $section): array
    {
        $groups = [];
        $currentName = null;
        $currentVirtualAps = [];
        $currentDot11a = null;
        $currentDot11g = null;

        $flush = function () use (&$groups, &$currentName, &$currentVirtualAps, &$currentDot11a, &$currentDot11g): void {
            if ($currentName === null) {
                return;
            }

            $groups[$currentName] = [
                'virtual_aps' => $currentVirtualAps,
                'dot11a_radio_profile' => $currentDot11a,
                'dot11g_radio_profile' => $currentDot11g,
            ];
        };

        foreach (explode("\n", $section) as $line) {
            $trimmed = rtrim($line);

            if (preg_match('/^ap-group "([^"]+)"/', $trimmed, $match)) {
                $flush();
                $currentName = $match[1];
                $currentVirtualAps = [];
                $currentDot11a = null;
                $currentDot11g = null;

                continue;
            }

            if ($trimmed === '!' && $currentName !== null) {
                $flush();
                $currentName = null;
                $currentVirtualAps = [];
                $currentDot11a = null;
                $currentDot11g = null;

                continue;
            }

            if ($currentName === null) {
                continue;
            }

            if (preg_match('/^\s+virtual-ap "([^"]+)"/', $trimmed, $match)) {
                $currentVirtualAps[] = $match[1];
            } elseif (preg_match('/^\s+dot11a-radio-profile "([^"]+)"/', $trimmed, $match)) {
                $currentDot11a = $match[1];
            } elseif (preg_match('/^\s+dot11g-radio-profile "([^"]+)"/', $trimmed, $match)) {
                $currentDot11g = $match[1];
            }
        }

        $flush();

        return $groups;
    }

    /**
     * @return array<string, array{eirp_min: int|null, eirp_max: int|null}>
     */
    private function parseRfRadioProfileBlocks(string $section): array
    {
        $profiles = [];
        $currentKey = null;
        $currentMin = null;
        $currentMax = null;

        $flush = function () use (&$profiles, &$currentKey, &$currentMin, &$currentMax): void {
            if ($currentKey === null) {
                return;
            }

            $profiles[$currentKey] = [
                'eirp_min' => $currentMin,
                'eirp_max' => $currentMax,
            ];
        };

        foreach (explode("\n", $section) as $line) {
            $trimmed = rtrim($line);

            if (preg_match('/^rf dot11([ag])-radio-profile "([^"]+)"/', $trimmed, $match)) {
                $flush();
                $currentKey = $match[1]."\0".$match[2];
                $currentMin = null;
                $currentMax = null;

                continue;
            }

            if ($trimmed === '!' && $currentKey !== null) {
                $flush();
                $currentKey = null;
                $currentMin = null;
                $currentMax = null;

                continue;
            }

            if ($currentKey === null) {
                continue;
            }

            if (preg_match('/^\s+eirp-min\s+(\d+)/', $trimmed, $match)) {
                $currentMin = (int) $match[1];
            } elseif (preg_match('/^\s+eirp-max\s+(\d+)/', $trimmed, $match)) {
                $currentMax = (int) $match[1];
            }
        }

        $flush();

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
     * @return array<string, array{
     *     ssid_profile: string|null,
     *     vlan: string|null,
     *     allowed_band: string|null,
     *     aaa_profile: string|null,
     *     vap_enabled: bool
     * }>
     */
    private function parseVirtualApBlocks(string $section): array
    {
        $map = [];
        $currentName = null;
        $currentSsidProfile = null;
        $currentVlan = null;
        $currentAllowedBand = null;
        $currentAaaProfile = null;
        $currentVapEnabled = true;

        $flush = function () use (
            &$map,
            &$currentName,
            &$currentSsidProfile,
            &$currentVlan,
            &$currentAllowedBand,
            &$currentAaaProfile,
            &$currentVapEnabled,
        ): void {
            if ($currentName === null) {
                return;
            }

            $map[$currentName] = [
                'ssid_profile' => $currentSsidProfile,
                'vlan' => $currentVlan,
                'allowed_band' => $currentAllowedBand,
                'aaa_profile' => $currentAaaProfile,
                'vap_enabled' => $currentVapEnabled,
            ];
        };

        foreach (explode("\n", $section) as $line) {
            $trimmed = rtrim($line);

            if (preg_match('/^wlan virtual-ap "([^"]+)"/', $trimmed, $match)) {
                $flush();
                $currentName = $match[1];
                $currentSsidProfile = null;
                $currentVlan = null;
                $currentAllowedBand = null;
                $currentAaaProfile = null;
                $currentVapEnabled = true;

                continue;
            }

            if ($trimmed === '!' && $currentName !== null) {
                $flush();
                $currentName = null;
                $currentSsidProfile = null;
                $currentVlan = null;
                $currentAllowedBand = null;
                $currentAaaProfile = null;
                $currentVapEnabled = true;

                continue;
            }

            if ($currentName === null) {
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
            } elseif (preg_match('/^\s+no vap-enable\b/', $trimmed)) {
                $currentVapEnabled = false;
            } elseif (preg_match('/^\s+vap-enable\b/', $trimmed)) {
                $currentVapEnabled = true;
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
        bool $enabled = true,
    ): array {
        $body = [
            'ssid' => $ssidName,
            'enable' => $enabled,
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

    /**
     * @return array<int, array{
     *     name: string,
     *     access_lists: array<int, array{name: string, rules: array<int, array<string, mixed>>, warnings: array<int, string>}>,
     *     warnings: array<int, string>
     * }>
     */
    private function parseUserRoles(string $content): array
    {
        $section = $this->extractSection($content, self::RUNNING_CONFIG_PATTERN, null);

        if ($section === null) {
            return [];
        }

        $services = $this->parseNetServices($section);
        $aliases = $this->parseNetDestinations($section);
        $accessLists = $this->parseSessionAccessLists($section);

        $roles = [];
        $currentName = null;
        $currentAclNames = [];

        foreach (explode("\n", $section) as $line) {
            $trimmed = rtrim($line);

            if (preg_match('/^user-role\s+(\S+)/', $trimmed, $match)) {
                if ($currentName !== null) {
                    $roles[] = $this->buildUserRole($currentName, $currentAclNames, $accessLists, $aliases, $services);
                }

                $currentName = $match[1];
                $currentAclNames = [];

                continue;
            }

            if ($trimmed === '!' && $currentName !== null) {
                $roles[] = $this->buildUserRole($currentName, $currentAclNames, $accessLists, $aliases, $services);
                $currentName = null;
                $currentAclNames = [];

                continue;
            }

            if ($currentName === null) {
                continue;
            }

            $inner = trim($trimmed);

            if (preg_match('/^access-list\s+session\s+(\S+)/', $inner, $aclMatch)) {
                $currentAclNames[] = $aclMatch[1];
            }
        }

        if ($currentName !== null) {
            $roles[] = $this->buildUserRole($currentName, $currentAclNames, $accessLists, $aliases, $services);
        }

        return $roles;
    }

    /**
     * @param  array<int, string>  $aclNames
     * @param  array<string, array{name: string, rules: array<int, array<string, mixed>>, warnings: array<int, string>}>  $accessLists
     * @param  array<string, array{name: string, invert: bool, entries: array<int, array<string, mixed>>}>  $aliases
     * @param  array<string, array{name: string, protocol: string|null, values: array<int, string>, alg: string|null}>  $services
     * @return array{
     *     name: string,
     *     access_lists: array<int, array{name: string, rules: array<int, array<string, mixed>>, warnings: array<int, string>}>,
     *     warnings: array<int, string>
     * }
     */
    private function buildUserRole(
        string $name,
        array $aclNames,
        array $accessLists,
        array $aliases,
        array $services,
    ): array {
        $includedNames = array_slice($aclNames, 2);
        $resolvedAcls = [];
        $warnings = [];

        foreach ($includedNames as $aclName) {
            if (! isset($accessLists[$aclName])) {
                $warnings[] = "Access-list \"{$aclName}\" not found";
                $resolvedAcls[] = [
                    'name' => $aclName,
                    'rules' => [],
                    'warnings' => ["Access-list \"{$aclName}\" not found"],
                ];

                continue;
            }

            $resolvedAcls[] = $this->resolveAccessListReferences(
                $accessLists[$aclName],
                $aliases,
                $services,
            );
        }

        return [
            'name' => $name,
            'access_lists' => $resolvedAcls,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array{name: string, rules: array<int, array<string, mixed>>, warnings: array<int, string>}  $accessList
     * @param  array<string, array{name: string, invert: bool, entries: array<int, array<string, mixed>>}>  $aliases
     * @param  array<string, array{name: string, protocol: string|null, values: array<int, string>, alg: string|null}>  $services
     * @return array{name: string, rules: array<int, array<string, mixed>>, warnings: array<int, string>}
     */
    private function resolveAccessListReferences(array $accessList, array $aliases, array $services): array
    {
        $warnings = $accessList['warnings'];
        $rules = [];

        foreach ($accessList['rules'] as $rule) {
            $source = $rule['source'];
            $destination = $rule['destination'];
            $service = $rule['service'];

            if (($source['type'] ?? null) === 'alias') {
                $aliasName = $source['value'] ?? '';
                if (isset($aliases[$aliasName])) {
                    $source['resolved'] = $aliases[$aliasName];
                } else {
                    $source['resolved'] = null;
                    $warnings[] = "Unresolved alias \"{$aliasName}\"";
                }
            }

            if (($destination['type'] ?? null) === 'alias') {
                $aliasName = $destination['value'] ?? '';
                if (isset($aliases[$aliasName])) {
                    $destination['resolved'] = $aliases[$aliasName];
                } else {
                    $destination['resolved'] = null;
                    $warnings[] = "Unresolved alias \"{$aliasName}\"";
                }
            }

            if (($service['type'] ?? null) === 'svc') {
                $svcName = $service['name'] ?? '';
                if (isset($services[$svcName])) {
                    $service['resolved'] = $services[$svcName];
                } else {
                    $service['resolved'] = null;
                    $warnings[] = "Unresolved service \"{$svcName}\"";
                }
            }

            $rules[] = [
                'source' => $source,
                'destination' => $destination,
                'service' => $service,
                'action' => $rule['action'],
                'other' => $rule['other'],
            ];
        }

        return [
            'name' => $accessList['name'],
            'rules' => $rules,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @return array<string, array{name: string, protocol: string|null, values: array<int, string>, alg: string|null}>
     */
    private function parseNetServices(string $section): array
    {
        $services = [];

        foreach (explode("\n", $section) as $line) {
            $trimmed = trim($line);

            if (! preg_match('/^netservice\s+(\S+)\s+(.*)$/', $trimmed, $match)) {
                continue;
            }

            $name = $match[1];
            $rest = trim($match[2]);
            $alg = null;

            if (preg_match('/\s+ALG\s+(\S+)\s*$/i', $rest, $algMatch)) {
                $alg = $algMatch[1];
                $rest = trim(substr($rest, 0, -strlen($algMatch[0])));
            }

            $protocol = null;
            $values = [];

            if (preg_match('/^(tcp|udp)\s+(.*)$/i', $rest, $protoMatch)) {
                $protocol = strtolower($protoMatch[1]);
                $rest = trim($protoMatch[2]);
            }

            if (preg_match('/^list\s+"([^"]*)"/i', $rest, $listMatch)) {
                $values = preg_split('/\s+/', trim($listMatch[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            } elseif ($rest !== '') {
                $values = preg_split('/\s+/', $rest, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }

            $services[$name] = [
                'name' => $name,
                'protocol' => $protocol,
                'values' => array_values($values),
                'alg' => $alg,
            ];
        }

        return $services;
    }

    /**
     * @return array<string, array{name: string, invert: bool, entries: array<int, array<string, mixed>>}>
     */
    private function parseNetDestinations(string $section): array
    {
        $destinations = [];
        $currentName = null;
        $currentInvert = false;
        $currentEntries = [];

        foreach (explode("\n", $section) as $line) {
            $trimmed = rtrim($line);

            if (preg_match('/^netdestination\s+(\S+)/', $trimmed, $match)) {
                if ($currentName !== null) {
                    $destinations[$currentName] = [
                        'name' => $currentName,
                        'invert' => $currentInvert,
                        'entries' => $currentEntries,
                    ];
                }

                $currentName = $match[1];
                $currentInvert = false;
                $currentEntries = [];

                continue;
            }

            // Ignore IPv6 destinations; flush any open IPv4 block first.
            if (preg_match('/^netdestination6\b/', $trimmed)) {
                if ($currentName !== null) {
                    $destinations[$currentName] = [
                        'name' => $currentName,
                        'invert' => $currentInvert,
                        'entries' => $currentEntries,
                    ];
                    $currentName = null;
                    $currentInvert = false;
                    $currentEntries = [];
                }

                continue;
            }

            if ($trimmed === '!' && $currentName !== null) {
                $destinations[$currentName] = [
                    'name' => $currentName,
                    'invert' => $currentInvert,
                    'entries' => $currentEntries,
                ];
                $currentName = null;
                $currentInvert = false;
                $currentEntries = [];

                continue;
            }

            if ($currentName === null) {
                continue;
            }

            $inner = trim($trimmed);

            if ($inner === '' || str_starts_with($inner, 'ipv6')) {
                continue;
            }

            if ($inner === 'invert') {
                $currentInvert = true;

                continue;
            }

            if (preg_match('/^host(?:\s+(\S+))?$/', $inner, $hostMatch)) {
                $entry = ['type' => 'host'];
                if (isset($hostMatch[1])) {
                    $entry['value'] = $hostMatch[1];
                }
                $currentEntries[] = $entry;

                continue;
            }

            if (preg_match('/^network\s+(\S+)\s+(\S+)/', $inner, $networkMatch)) {
                $currentEntries[] = [
                    'type' => 'network',
                    'value' => $networkMatch[1],
                    'subnet' => $networkMatch[2],
                ];

                continue;
            }

            if (preg_match('/^name\s+(\S+)/', $inner, $nameMatch)) {
                $currentEntries[] = [
                    'type' => 'name',
                    'value' => $nameMatch[1],
                ];
            }
        }

        if ($currentName !== null) {
            $destinations[$currentName] = [
                'name' => $currentName,
                'invert' => $currentInvert,
                'entries' => $currentEntries,
            ];
        }

        return $destinations;
    }

    /**
     * @return array<string, array{name: string, rules: array<int, array<string, mixed>>, warnings: array<int, string>}>
     */
    private function parseSessionAccessLists(string $section): array
    {
        $lists = [];
        $currentName = null;
        $currentLines = [];

        foreach (explode("\n", $section) as $line) {
            $trimmed = rtrim($line);

            if (preg_match('/^ip access-list session\s+(\S+)/', $trimmed, $match)) {
                if ($currentName !== null) {
                    $lists[$currentName] = $this->parseSessionAccessListLines($currentName, $currentLines);
                }

                $currentName = $match[1];
                $currentLines = [];

                continue;
            }

            if ($trimmed === '!' && $currentName !== null) {
                $lists[$currentName] = $this->parseSessionAccessListLines($currentName, $currentLines);
                $currentName = null;
                $currentLines = [];

                continue;
            }

            if ($currentName !== null) {
                $currentLines[] = $trimmed;
            }
        }

        if ($currentName !== null) {
            $lists[$currentName] = $this->parseSessionAccessListLines($currentName, $currentLines);
        }

        return $lists;
    }

    /**
     * @param  array<int, string>  $lines
     * @return array{name: string, rules: array<int, array<string, mixed>>, warnings: array<int, string>}
     */
    private function parseSessionAccessListLines(string $name, array $lines): array
    {
        $rules = [];
        $warnings = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            if (str_starts_with($trimmed, 'ipv6')) {
                continue;
            }

            $rule = $this->parseAccessListRule($trimmed);

            if ($rule === null) {
                $warnings[] = "Unparsed rule: {$trimmed}";

                continue;
            }

            $rules[] = $rule;
        }

        return [
            'name' => $name,
            'rules' => $rules,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{
     *     source: array<string, mixed>,
     *     destination: array<string, mixed>,
     *     service: array<string, mixed>,
     *     action: string,
     *     other: string
     * }|null
     */
    private function parseAccessListRule(string $line): ?array
    {
        $tokens = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === false || $tokens === []) {
            return null;
        }

        $index = 0;
        $source = $this->consumeEndpoint($tokens, $index);

        if ($source === null) {
            return null;
        }

        $destination = $this->consumeEndpoint($tokens, $index);

        if ($destination === null) {
            return null;
        }

        $service = $this->consumeService($tokens, $index);

        if ($service === null) {
            return null;
        }

        if (! isset($tokens[$index])) {
            return null;
        }

        $action = $tokens[$index];
        $index++;
        $otherTokens = array_slice($tokens, $index);

        return [
            'source' => $source,
            'destination' => $destination,
            'service' => $service,
            'action' => $action,
            'other' => implode(' ', $otherTokens),
        ];
    }

    /**
     * @param  array<int, string>  $tokens
     * @return array<string, mixed>|null
     */
    private function consumeEndpoint(array $tokens, int &$index): ?array
    {
        if (! isset($tokens[$index])) {
            return null;
        }

        $token = $tokens[$index];

        if ($token === 'user' || $token === 'any') {
            $index++;

            return ['type' => $token];
        }

        if ($token === 'host') {
            $index++;

            if (! isset($tokens[$index])) {
                return null;
            }

            $value = $tokens[$index];
            $index++;

            return [
                'type' => 'host',
                'value' => $value,
            ];
        }

        if ($token === 'alias') {
            $index++;

            if (! isset($tokens[$index])) {
                return null;
            }

            $value = $tokens[$index];
            $index++;

            return [
                'type' => 'alias',
                'value' => $value,
                'resolved' => null,
            ];
        }

        if ($token === 'network') {
            $index++;

            if (! isset($tokens[$index], $tokens[$index + 1])) {
                return null;
            }

            $ip = $tokens[$index];
            $mask = $tokens[$index + 1];
            $index += 2;

            return [
                'type' => 'network',
                'value' => $ip,
                'subnet' => $mask,
            ];
        }

        return null;
    }

    /**
     * @param  array<int, string>  $tokens
     * @return array<string, mixed>|null
     */
    private function consumeService(array $tokens, int &$index): ?array
    {
        if (! isset($tokens[$index])) {
            return null;
        }

        $token = $tokens[$index];

        if ($token === 'any') {
            $index++;

            return ['type' => 'any'];
        }

        if ($token === 'tcp' || $token === 'udp') {
            $index++;
            $ports = [];

            while (isset($tokens[$index]) && preg_match('/^\d+$/', $tokens[$index])) {
                $ports[] = $tokens[$index];
                $index++;
            }

            return [
                'type' => $token,
                'ports' => $ports,
            ];
        }

        if (str_starts_with($token, 'svc-')) {
            $index++;

            return [
                'type' => 'svc',
                'name' => $token,
                'resolved' => null,
            ];
        }

        if ($token === 'app') {
            $index++;

            if (! isset($tokens[$index])) {
                return null;
            }

            $name = $tokens[$index];
            $index++;

            return [
                'type' => 'app',
                'name' => $name,
            ];
        }

        $index++;

        return [
            'type' => 'other',
            'raw' => $token,
        ];
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
