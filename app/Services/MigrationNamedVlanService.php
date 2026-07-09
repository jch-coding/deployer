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
     * @return array<int, array{name: string, status: string, message: string}>
     */
    public function deployOffsetNamedVlans(CentralAPIHelper $helper, string $scopeId): array
    {
        $getQueryParameters = [
            'view-type' => 'LOCAL',
            'scope-id' => $scopeId,
            'device-function' => 'CAMPUS_AP',
        ];

        $response = $helper->get_named_vlans($getQueryParameters);

        if (is_array($response) && array_key_exists('error', $response)) {
            return [[
                'name' => '*',
                'status' => 'error',
                'message' => (string) $response['error'],
            ]];
        }

        if (! $response->successful()) {
            return [[
                'name' => '*',
                'status' => 'error',
                'message' => $response->body() ?: 'Failed to fetch named VLAN profiles with status '.$response->status(),
            ]];
        }

        $profiles = $response->json('profile', []);

        if (! is_array($profiles) || $profiles === []) {
            return [[
                'name' => '*',
                'status' => 'skipped',
                'message' => 'No named VLAN profiles returned from Central',
            ]];
        }

        $postQueryParameters = [
            'view-type' => 'LOCAL',
            'object-type' => 'LOCAL',
            'scope-id' => $scopeId,
            'device-function' => 'CAMPUS_AP',
        ];

        $results = [];

        foreach ($profiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $profileName = trim((string) ($profile['name'] ?? ''));
            $vlanIdRanges = $profile['vlan']['vlan-id-ranges'] ?? null;

            if ($profileName === '') {
                continue;
            }

            if (! is_array($vlanIdRanges) || $vlanIdRanges === []) {
                $results[] = [
                    'name' => $profileName,
                    'status' => 'skipped',
                    'message' => 'Missing vlan-id-ranges',
                ];

                continue;
            }

            $stringRanges = array_map(
                fn ($range): string => (string) $range,
                $vlanIdRanges,
            );

            $offsetRanges = self::offsetVlanIdRanges($stringRanges);
            $postResponse = $helper->post_named_vlan_profile(
                $profileName,
                $postQueryParameters,
                $profileName,
                $offsetRanges,
            );

            if (is_array($postResponse) && array_key_exists('error', $postResponse)) {
                $results[] = [
                    'name' => $profileName,
                    'status' => 'error',
                    'message' => (string) $postResponse['error'],
                ];

                continue;
            }

            if ($postResponse->successful()) {
                $results[] = [
                    'name' => $profileName,
                    'status' => 'success',
                    'message' => 'Deployed successfully with offset vlan-id-ranges',
                ];
            } else {
                $results[] = [
                    'name' => $profileName,
                    'status' => 'error',
                    'message' => $postResponse->body() ?: 'Request failed with status '.$postResponse->status(),
                ];
            }
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
