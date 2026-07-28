<?php

namespace App\Services;

use App\Helper\CentralAPIHelper;
use App\Support\TrunkVlanRanges;
use Illuminate\Http\Client\Response;
use Illuminate\Validation\ValidationException;

class SwitchPortProfileInterfaceComparer
{
    private const SCOPE_ORDER = ['device', 'device_group', 'site', 'site_collection'];

    private const DEVICE_FUNCTION = 'ACCESS_SWITCH';

    /**
     * @return array{
     *     serial: string,
     *     scopes: array{device: string|null, device_group: string|null, site: string|null, site_collection: string|null},
     *     profiles: list<array{name: string, scope_level: string|null, scope_id: string|null, found: bool, interface_names: list<string>}>,
     *     interfaces: list<array{
     *         name: string,
     *         sw_profile: string|null,
     *         status: 'match'|'mismatch'|'missing_profile'|'missing_interface'|'no_profile',
     *         differences: list<array{field: string, expected: mixed, actual: mixed}>,
     *         expected: array{interface_mode: string|null, native_vlan: int|null, trunk_vlan_ids: list<int>}|null,
     *         actual: array{vlan_mode: string|null, native_vlan: int|null, allowed_vlan_ids: list<int>}|null
     *     }>,
     *     summary: array{profiles: int, matches: int, mismatches: int, missing_profiles: int, missing_interfaces: int, no_profile: int},
     *     error: string|null
     * }
     */
    public function compare(CentralAPIHelper $helper, string $serial): array
    {
        $serial = trim($serial);

        $scopesResult = $helper->resolveHierarchyScopeIdsForDevice($serial);
        if ($scopesResult['error'] !== null) {
            return $this->errorPayload($serial, $scopesResult['error'], [
                'device' => $scopesResult['device'],
                'device_group' => $scopesResult['device_group'],
                'site' => $scopesResult['site'],
                'site_collection' => $scopesResult['site_collection'],
            ]);
        }

        $scopes = [
            'device' => $scopesResult['device'],
            'device_group' => $scopesResult['device_group'],
            'site' => $scopesResult['site'],
            'site_collection' => $scopesResult['site_collection'],
        ];

        $ethernetResponse = $helper->get_ethernet_interfaces_for_scope(
            (string) $scopes['device'],
            self::DEVICE_FUNCTION,
        );

        if (is_array($ethernetResponse) && array_key_exists('error', $ethernetResponse)) {
            return $this->errorPayload($serial, (string) $ethernetResponse['error'], $scopes);
        }

        if (! $ethernetResponse instanceof Response || ! $ethernetResponse->successful()) {
            $message = $ethernetResponse instanceof Response
                ? (string) ($ethernetResponse->json('message') ?? $ethernetResponse->body())
                : 'Failed to fetch ethernet interfaces from Central.';

            return $this->errorPayload(
                $serial,
                $message !== '' ? $message : 'Failed to fetch ethernet interfaces from Central.',
                $scopes,
            );
        }

        $ethernetItems = $ethernetResponse->json('interface', []);
        if (! is_array($ethernetItems)) {
            $ethernetItems = [];
        }

        $monitoringResult = $helper->get_all_switch_interfaces($serial);
        if (array_key_exists('error', $monitoringResult)) {
            return $this->errorPayload($serial, (string) $monitoringResult['error'], $scopes);
        }

        $monitoringByName = [];
        foreach ($monitoringResult as $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = (string) ($item['name'] ?? '');
            if ($name !== '') {
                $monitoringByName[$name] = $item;
            }
        }

        /** @var array<string, list<string>> $profileToInterfaces */
        $profileToInterfaces = [];
        /** @var list<array{name: string, sw_profile: string|null}> $ethernetWithNames */
        $ethernetWithNames = [];

        foreach ($ethernetItems as $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = (string) ($item['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $swProfile = $item['sw-profile'] ?? null;
            $swProfile = is_string($swProfile) ? trim($swProfile) : null;
            if ($swProfile === '') {
                $swProfile = null;
            }

            $ethernetWithNames[] = [
                'name' => $name,
                'sw_profile' => $swProfile,
            ];

            if ($swProfile === null) {
                continue;
            }

            $profileToInterfaces[$swProfile] ??= [];
            if (! in_array($name, $profileToInterfaces[$swProfile], true)) {
                $profileToInterfaces[$swProfile][] = $name;
            }
        }

        /** @var array<string, array{profile: array<string, mixed>|null, scope_level: string|null, scope_id: string|null, found: bool}> $resolvedProfiles */
        $resolvedProfiles = [];
        $profileSummaries = [];

        foreach ($profileToInterfaces as $profileName => $interfaceNames) {
            $resolved = $this->resolveProfileAcrossScopes($helper, $profileName, $scopes);
            $resolvedProfiles[$profileName] = $resolved;
            $profileSummaries[] = [
                'name' => $profileName,
                'scope_level' => $resolved['scope_level'],
                'scope_id' => $resolved['scope_id'],
                'found' => $resolved['found'],
                'interface_names' => $interfaceNames,
            ];
        }

        $interfaces = [];
        $summary = [
            'profiles' => count($profileSummaries),
            'matches' => 0,
            'mismatches' => 0,
            'missing_profiles' => 0,
            'missing_interfaces' => 0,
            'no_profile' => 0,
        ];

        foreach ($ethernetWithNames as $ethernet) {
            $name = $ethernet['name'];
            $swProfile = $ethernet['sw_profile'];

            if ($swProfile === null) {
                $interfaces[] = [
                    'name' => $name,
                    'sw_profile' => null,
                    'status' => 'no_profile',
                    'differences' => [],
                    'expected' => null,
                    'actual' => $this->mapActual($monitoringByName[$name] ?? null),
                ];
                $summary['no_profile']++;

                continue;
            }

            $resolved = $resolvedProfiles[$swProfile] ?? null;
            if ($resolved === null || ! $resolved['found'] || $resolved['profile'] === null) {
                $interfaces[] = [
                    'name' => $name,
                    'sw_profile' => $swProfile,
                    'status' => 'missing_profile',
                    'differences' => [],
                    'expected' => null,
                    'actual' => $this->mapActual($monitoringByName[$name] ?? null),
                ];
                $summary['missing_profiles']++;

                continue;
            }

            if (! array_key_exists($name, $monitoringByName)) {
                $expected = $this->mapExpected($resolved['profile']);
                $interfaces[] = [
                    'name' => $name,
                    'sw_profile' => $swProfile,
                    'status' => 'missing_interface',
                    'differences' => [],
                    'expected' => $expected,
                    'actual' => null,
                ];
                $summary['missing_interfaces']++;

                continue;
            }

            $expected = $this->mapExpected($resolved['profile']);
            $actual = $this->mapActual($monitoringByName[$name]);
            $differences = $this->diffExpectedActual($expected, $actual);
            $status = $differences === [] ? 'match' : 'mismatch';

            $interfaces[] = [
                'name' => $name,
                'sw_profile' => $swProfile,
                'status' => $status,
                'differences' => $differences,
                'expected' => $expected,
                'actual' => $actual,
            ];

            if ($status === 'match') {
                $summary['matches']++;
            } else {
                $summary['mismatches']++;
            }
        }

        return [
            'serial' => $serial,
            'scopes' => $scopes,
            'profiles' => $profileSummaries,
            'interfaces' => $interfaces,
            'summary' => $summary,
            'error' => null,
        ];
    }

    /**
     * @param  array{device: string|null, device_group: string|null, site: string|null, site_collection: string|null}  $scopes
     * @return array{profile: array<string, mixed>|null, scope_level: string|null, scope_id: string|null, found: bool}
     */
    private function resolveProfileAcrossScopes(CentralAPIHelper $helper, string $profileName, array $scopes): array
    {
        foreach (self::SCOPE_ORDER as $level) {
            $scopeId = $scopes[$level] ?? null;
            if (! is_string($scopeId) || $scopeId === '') {
                continue;
            }

            $result = $helper->fetchLocalSwPortProfile($profileName, $scopeId, self::DEVICE_FUNCTION);
            if ($result['error'] !== null) {
                continue;
            }

            if ($result['empty'] || $result['profile'] === null) {
                continue;
            }

            return [
                'profile' => $result['profile'],
                'scope_level' => $level,
                'scope_id' => $scopeId,
                'found' => true,
            ];
        }

        return [
            'profile' => null,
            'scope_level' => null,
            'scope_id' => null,
            'found' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array{interface_mode: string|null, native_vlan: int|null, trunk_vlan_ids: list<int>}
     */
    public function mapExpected(array $profile): array
    {
        $switchport = $profile['switchport'] ?? [];
        if (! is_array($switchport)) {
            $switchport = [];
        }

        $mode = $switchport['interface-mode'] ?? null;
        $modeString = is_string($mode) || is_numeric($mode) ? (string) $mode : null;
        $isAccess = $modeString !== null && strcasecmp($modeString, 'ACCESS') === 0;

        if ($isAccess) {
            return [
                'interface_mode' => $modeString,
                'native_vlan' => $this->normalizeNativeVlan($switchport['access-vlan'] ?? null),
                'trunk_vlan_ids' => [],
            ];
        }

        $native = $switchport['native-vlan'] ?? null;
        $ranges = $switchport['trunk-vlan-ranges'] ?? [];

        return [
            'interface_mode' => $modeString,
            'native_vlan' => $this->normalizeNativeVlan($native),
            'trunk_vlan_ids' => $this->normalizeVlanIdList($ranges),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $item
     * @return array{vlan_mode: string|null, native_vlan: int|null, allowed_vlan_ids: list<int>}|null
     */
    public function mapActual(?array $item): ?array
    {
        if ($item === null) {
            return null;
        }

        $mode = $item['vlanMode'] ?? null;

        return [
            'vlan_mode' => is_string($mode) || is_numeric($mode) ? (string) $mode : null,
            'native_vlan' => $this->normalizeNativeVlan($item['nativeVlan'] ?? null),
            'allowed_vlan_ids' => $this->normalizeVlanIdList($item['allowedVlanIds'] ?? []),
        ];
    }

    /**
     * @param  array{interface_mode: string|null, native_vlan: int|null, trunk_vlan_ids: list<int>}  $expected
     * @param  array{vlan_mode: string|null, native_vlan: int|null, allowed_vlan_ids: list<int>}  $actual
     * @return list<array{field: string, expected: mixed, actual: mixed}>
     */
    public function diffExpectedActual(array $expected, array $actual): array
    {
        $differences = [];

        $expectedMode = $expected['interface_mode'];
        $actualMode = $actual['vlan_mode'];
        if (! $this->modesEqual($expectedMode, $actualMode)) {
            $differences[] = [
                'field' => 'interface_mode',
                'expected' => $expectedMode,
                'actual' => $actualMode,
            ];
        }

        if ($expected['native_vlan'] !== $actual['native_vlan']) {
            $differences[] = [
                'field' => 'native_vlan',
                'expected' => $expected['native_vlan'],
                'actual' => $actual['native_vlan'],
            ];
        }

        $isAccess = $expectedMode !== null && strcasecmp($expectedMode, 'ACCESS') === 0;
        if (! $isAccess && ! $this->vlanListsEqual($expected['trunk_vlan_ids'], $actual['allowed_vlan_ids'])) {
            $differences[] = [
                'field' => 'trunk_vlans',
                'expected' => $expected['trunk_vlan_ids'],
                'actual' => $actual['allowed_vlan_ids'],
            ];
        }

        return $differences;
    }

    public function modesEqual(?string $expected, ?string $actual): bool
    {
        if ($expected === null && $actual === null) {
            return true;
        }

        if ($expected === null || $actual === null) {
            return false;
        }

        return strcasecmp($expected, $actual) === 0;
    }

    /**
     * @param  list<int>  $a
     * @param  list<int>  $b
     */
    public function vlanListsEqual(array $a, array $b): bool
    {
        $left = array_values(array_unique($a));
        $right = array_values(array_unique($b));
        sort($left);
        sort($right);

        return $left === $right;
    }

    /**
     * @return list<int>
     */
    public function normalizeVlanIdList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_int($value) || (is_string($value) && ctype_digit(trim($value)))) {
            return [(int) $value];
        }

        if (is_string($value)) {
            try {
                return TrunkVlanRanges::expandToVlanIds($value);
            } catch (ValidationException) {
                return [];
            }
        }

        if (! is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $item) {
            if (is_int($item) || (is_string($item) && ctype_digit(trim($item)))) {
                $ids[] = (int) $item;

                continue;
            }

            if (is_string($item)) {
                try {
                    $ids = array_merge($ids, TrunkVlanRanges::expandToVlanIds($item));
                } catch (ValidationException) {
                    continue;
                }
            }
        }

        sort($ids);

        return array_values(array_unique($ids));
    }

    private function normalizeNativeVlan(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param  array{device: string|null, device_group: string|null, site: string|null, site_collection: string|null}  $scopes
     * @return array{
     *     serial: string,
     *     scopes: array{device: string|null, device_group: string|null, site: string|null, site_collection: string|null},
     *     profiles: list<array<string, mixed>>,
     *     interfaces: list<array<string, mixed>>,
     *     summary: array{profiles: int, matches: int, mismatches: int, missing_profiles: int, missing_interfaces: int, no_profile: int},
     *     error: string|null
     * }
     */
    private function errorPayload(string $serial, string $error, array $scopes): array
    {
        return [
            'serial' => $serial,
            'scopes' => $scopes,
            'profiles' => [],
            'interfaces' => [],
            'summary' => [
                'profiles' => 0,
                'matches' => 0,
                'mismatches' => 0,
                'missing_profiles' => 0,
                'missing_interfaces' => 0,
                'no_profile' => 0,
            ],
            'error' => $error,
        ];
    }
}
