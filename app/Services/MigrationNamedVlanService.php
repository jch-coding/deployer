<?php

namespace App\Services;

use App\Helper\CentralAPIHelper;

class MigrationNamedVlanService
{
    public static function isFreezerSite(string $siteName): bool
    {
        return str_contains($siteName, 'Freezer') && ! str_contains($siteName, 'Hub-Freezer');
    }

    /**
     * @param  array<int, string>  $ranges
     * @return array<int, string>
     */
    public static function offsetVlanIdRanges(array $ranges, int $offset = 200): array
    {
        return array_map(
            fn (string $range): string => self::offsetSingleVlanIdRange($range, $offset),
            $ranges,
        );
    }

    /**
     * @param  array<int, array{ssid_profile_name: string, body: array<string, mixed>}>  $profiles
     * @return array<int, string>
     */
    public static function vlanNamesFromWlanProfiles(array $profiles): array
    {
        $names = [];

        foreach ($profiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $body = $profile['body'] ?? null;

            if (! is_array($body)) {
                continue;
            }

            $vlanName = trim((string) ($body['vlan-name'] ?? ''));

            if ($vlanName !== '') {
                $names[] = $vlanName;
            }
        }

        return array_values(array_unique($names));
    }

    public function resolveSiteCollectionScopeId(CentralAPIHelper $helper, string $siteScopeId): ?string
    {
        $hierarchy = $helper->get_hierarchy(['scope_id' => $siteScopeId], 'site');

        if (array_key_exists('error', $hierarchy)) {
            return null;
        }

        foreach ($hierarchy as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (($entry['scopeType'] ?? '') !== 'site_collection') {
                continue;
            }

            $scopeId = trim((string) ($entry['scopeId'] ?? ''));

            if ($scopeId !== '') {
                return $scopeId;
            }
        }

        return null;
    }

    /**
     * @return array{profiles: array<int, array<string, mixed>>, error: string|null}
     */
    public function fetchNamedVlanProfiles(CentralAPIHelper $helper, string $scopeId, bool $siteCollection = false): array
    {
        $getQueryParameters = [
            'view-type' => 'LOCAL',
            'scope-id' => $scopeId,
            'device-function' => 'CAMPUS_AP',
        ];

        if ($siteCollection) {
            $getQueryParameters['object-type'] = 'SHARED';
        }

        $response = $helper->get_named_vlans($getQueryParameters);

        if (is_array($response) && array_key_exists('error', $response)) {
            return [
                'profiles' => [],
                'error' => (string) $response['error'],
            ];
        }

        if (! $response->successful()) {
            return [
                'profiles' => [],
                'error' => $response->body() ?: 'Failed to fetch named VLAN profiles with status '.$response->status(),
            ];
        }

        $profiles = $response->json('profile', []);

        if (! is_array($profiles)) {
            return [
                'profiles' => [],
                'error' => null,
            ];
        }

        return [
            'profiles' => $profiles,
            'error' => null,
        ];
    }

    /**
     * @return array{profiles: array<int, array<string, mixed>>, error: string|null}
     */
    public function fetchNamedVlanProfilesForFreezerSite(CentralAPIHelper $helper, string $siteScopeId): array
    {
        $siteFetch = $this->fetchNamedVlanProfiles($helper, $siteScopeId);
        $siteProfiles = $siteFetch['error'] === null ? $siteFetch['profiles'] : [];

        $collectionScopeId = $this->resolveSiteCollectionScopeId($helper, $siteScopeId);
        $collectionProfiles = [];

        if ($collectionScopeId !== null) {
            $collectionFetch = $this->fetchNamedVlanProfiles($helper, $collectionScopeId, true);

            if ($collectionFetch['error'] === null) {
                $collectionProfiles = $collectionFetch['profiles'];
            }
        }

        if ($siteFetch['error'] !== null && $collectionProfiles === []) {
            return [
                'profiles' => [],
                'error' => $siteFetch['error'],
            ];
        }

        return [
            'profiles' => $this->mergeNamedVlanProfiles($siteProfiles, $collectionProfiles),
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array{name: string, status: string, message: string}
     */
    public function deploySingleOffsetNamedVlan(CentralAPIHelper $helper, string $scopeId, array $profile): array
    {
        $profileName = trim((string) ($profile['name'] ?? ''));
        $vlanIdRanges = $profile['vlan']['vlan-id-ranges'] ?? null;

        if ($profileName === '') {
            return [
                'name' => '*',
                'status' => 'skipped',
                'message' => 'Missing named VLAN profile name',
            ];
        }

        if (! is_array($vlanIdRanges) || $vlanIdRanges === []) {
            return [
                'name' => $profileName,
                'status' => 'skipped',
                'message' => 'Missing vlan-id-ranges',
            ];
        }

        $postQueryParameters = [
            'view-type' => 'LOCAL',
            'object-type' => 'LOCAL',
            'scope-id' => $scopeId,
            'device-function' => 'CAMPUS_AP',
        ];

        $stringRanges = array_map(
            fn ($range): string => (string) $range,
            $vlanIdRanges,
        );

        $deployName = trim((string) ($profile['deploy_name'] ?? ''));
        if ($deployName === '') {
            $deployName = ArubaControllerConfigParser::mapVlanName($profileName);
        }

        $offsetRanges = self::offsetVlanIdRanges($stringRanges);
        $postResponse = $helper->post_named_vlan_profile(
            $deployName,
            $postQueryParameters,
            $deployName,
            $offsetRanges,
        );

        if (is_array($postResponse) && array_key_exists('error', $postResponse)) {
            return [
                'name' => $deployName,
                'status' => 'error',
                'message' => (string) $postResponse['error'],
            ];
        }

        if ($postResponse->successful()) {
            return [
                'name' => $deployName,
                'status' => 'success',
                'message' => 'Deployed successfully with offset vlan-id-ranges',
            ];
        }

        return [
            'name' => $deployName,
            'status' => 'error',
            'message' => $postResponse->body() ?: 'Request failed with status '.$postResponse->status(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $profiles
     * @param  array<int, string>|null  $requiredVlanNames
     * @return array<int, array<string, mixed>>
     */
    public function deployableNamedVlanProfiles(array $profiles, ?array $requiredVlanNames = null): array
    {
        $required = null;

        if ($requiredVlanNames !== null) {
            $required = array_fill_keys($requiredVlanNames, true);
        }

        $deployable = [];

        foreach ($profiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $profileName = trim((string) ($profile['name'] ?? ''));

            if ($profileName === '') {
                continue;
            }

            $normalizedName = ArubaControllerConfigParser::mapVlanName($profileName);

            if ($required !== null) {
                $deployName = null;

                if (array_key_exists($profileName, $required)) {
                    $deployName = $profileName;
                } elseif (array_key_exists($normalizedName, $required)) {
                    $deployName = $normalizedName;
                }

                if ($deployName === null) {
                    continue;
                }

                $profile['deploy_name'] = $deployName;
            }

            $deployable[] = $profile;
        }

        return $deployable;
    }

    /**
     * @param  array<int, array{ssid_profile_name: string, body: array<string, mixed>}>  $wlanProfiles
     * @return array<int, array{name: string, status: string, message: string}>
     */
    public function deployOffsetNamedVlans(CentralAPIHelper $helper, string $scopeId, array $wlanProfiles = []): array
    {
        $fetch = $this->fetchNamedVlanProfilesForFreezerSite($helper, $scopeId);

        if ($fetch['error'] !== null) {
            return [[
                'name' => '*',
                'status' => 'error',
                'message' => $fetch['error'],
            ]];
        }

        $profiles = $this->deployableNamedVlanProfiles(
            $fetch['profiles'],
            self::vlanNamesFromWlanProfiles($wlanProfiles),
        );

        if ($profiles === []) {
            return [[
                'name' => '*',
                'status' => 'skipped',
                'message' => 'No named VLAN profiles returned from Central',
            ]];
        }

        $results = [];

        foreach ($profiles as $profile) {
            $results[] = $this->deploySingleOffsetNamedVlan($helper, $scopeId, $profile);
        }

        if ($results === []) {
            return [[
                'name' => '*',
                'status' => 'skipped',
                'message' => 'No valid named VLAN profiles found to deploy',
            ]];
        }

        return $results;
    }

    /**
     * @param  array<int, array<string, mixed>>  $siteProfiles
     * @param  array<int, array<string, mixed>>  $collectionProfiles
     * @return array<int, array<string, mixed>>
     */
    private function mergeNamedVlanProfiles(array $siteProfiles, array $collectionProfiles): array
    {
        $byName = [];

        foreach ($collectionProfiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $profileName = trim((string) ($profile['name'] ?? ''));

            if ($profileName === '') {
                continue;
            }

            $byName[$profileName] = $profile;
        }

        foreach ($siteProfiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $profileName = trim((string) ($profile['name'] ?? ''));

            if ($profileName === '') {
                continue;
            }

            $byName[$profileName] = $profile;
        }

        return array_values($byName);
    }

    private static function offsetSingleVlanIdRange(string $range, int $offset): string
    {
        $trimmed = trim($range);

        if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $trimmed, $match)) {
            return ((int) $match[1] + $offset).'-'.((int) $match[2] + $offset);
        }

        if (preg_match('/^\d+$/', $trimmed)) {
            return (string) ((int) $trimmed + $offset);
        }

        return $trimmed;
    }
}
