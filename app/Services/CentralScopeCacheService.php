<?php

namespace App\Services;

use App\CentralScopeCacheType;
use App\Helper\CentralAPIHelper;
use App\Models\CentralScopeCache;
use App\Models\Client;
use App\Support\CnacMacRegistrationCsv;
use App\Support\MacAddress;
use Illuminate\Support\Facades\Log;

class CentralScopeCacheService
{
    private const EMPTY_SITES_MESSAGE = 'Central sites have not been refreshed yet. Use Refresh sites to load from Central.';

    private const EMPTY_GROUPS_MESSAGE = 'Central groups have not been refreshed yet. Use Refresh groups to load from Central.';

    private const EMPTY_MAC_REGISTRATIONS_MESSAGE = 'Central NAC MAC registrations have not been refreshed yet. Use Refresh MAC table to load from Central.';

    /**
     * @return array{
     *     sites: array<int, array{scopeName: string, scopeId: string}>,
     *     error: string|null,
     *     refreshed_at: string|null
     * }
     */
    public function getSites(Client $client): array
    {
        $cache = $this->findCache($client, CentralScopeCacheType::Sites);

        if ($cache === null) {
            return [
                'sites' => [],
                'error' => self::EMPTY_SITES_MESSAGE,
                'refreshed_at' => null,
            ];
        }

        /** @var array<int, array{scopeName: string, scopeId: string}> $items */
        $items = is_array($cache->items) ? $cache->items : [];

        return [
            'sites' => $items,
            'error' => $cache->last_error,
            'refreshed_at' => $cache->refreshed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array{siteId: string, siteName: string}>
     */
    public function getSiteOptions(Client $client): array
    {
        return array_map(
            fn (array $site): array => [
                'siteId' => $site['scopeId'],
                'siteName' => $site['scopeName'],
            ],
            $this->getSites($client)['sites'],
        );
    }

    /**
     * @return array{
     *     central_device_groups: array<int, array{scopeName: string, scopeId: string}>,
     *     device_group_options: array<int, array{scopeName: string, scopeId: string, isClassic: bool}>,
     *     error: string|null,
     *     classic_device_groups_error: string|null,
     *     refreshed_at: string|null
     * }
     */
    public function getGroups(Client $client): array
    {
        $cache = $this->findCache($client, CentralScopeCacheType::Groups);

        if ($cache === null) {
            return [
                'central_device_groups' => [],
                'device_group_options' => [],
                'error' => self::EMPTY_GROUPS_MESSAGE,
                'classic_device_groups_error' => null,
                'refreshed_at' => null,
            ];
        }

        /** @var array<string, mixed> $items */
        $items = is_array($cache->items) ? $cache->items : [];

        return [
            'central_device_groups' => is_array($items['central_device_groups'] ?? null)
                ? $items['central_device_groups']
                : [],
            'device_group_options' => is_array($items['device_group_options'] ?? null)
                ? $items['device_group_options']
                : [],
            'error' => $cache->last_error,
            'classic_device_groups_error' => is_string($items['classic_device_groups_error'] ?? null)
                ? $items['classic_device_groups_error']
                : null,
            'refreshed_at' => $cache->refreshed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{
     *     central_sites_cache: array{refreshed_at: string|null, error: string|null},
     *     central_groups_cache: array{refreshed_at: string|null, error: string|null, classic_error: string|null},
     *     central_mac_registrations_cache: array{refreshed_at: string|null, error: string|null}
     * }
     */
    public function getCacheMetadata(Client $client): array
    {
        $sites = $this->getSites($client);
        $groups = $this->getGroups($client);
        $macRegistrations = $this->getMacRegistrations($client);

        return [
            'central_sites_cache' => [
                'refreshed_at' => $sites['refreshed_at'],
                'error' => $sites['error'],
            ],
            'central_groups_cache' => [
                'refreshed_at' => $groups['refreshed_at'],
                'error' => $groups['error'],
                'classic_error' => $groups['classic_device_groups_error'],
            ],
            'central_mac_registrations_cache' => [
                'refreshed_at' => $macRegistrations['refreshed_at'],
                'error' => $macRegistrations['error'],
            ],
        ];
    }

    /**
     * @return array{
     *     entries: list<array{
     *         mac_address: string,
     *         client_name: string,
     *         enabled: bool|null,
     *         static_tags: list<string>
     *     }>,
     *     error: string|null,
     *     refreshed_at: string|null
     * }
     */
    public function getMacRegistrations(Client $client): array
    {
        $cache = $this->findCache($client, CentralScopeCacheType::MacRegistrations);

        if ($cache === null) {
            return [
                'entries' => [],
                'error' => self::EMPTY_MAC_REGISTRATIONS_MESSAGE,
                'refreshed_at' => null,
            ];
        }

        /** @var list<array{mac_address: string, client_name: string, enabled: bool|null, static_tags: list<string>}> $items */
        $items = is_array($cache->items) ? $cache->items : [];

        return [
            'entries' => $items,
            'error' => $cache->last_error,
            'refreshed_at' => $cache->refreshed_at?->toIso8601String(),
        ];
    }

    /**
     * Ensure MAC registrations are loaded; refresh from Central when the cache row is missing.
     *
     * @return array{
     *     entries: list<array{
     *         mac_address: string,
     *         client_name: string,
     *         enabled: bool|null,
     *         static_tags: list<string>
     *     }>,
     *     error: string|null,
     *     refreshed_at: string|null
     * }
     */
    public function ensureMacRegistrations(Client $client, ?CentralAPIHelper $centralHelper = null): array
    {
        $cache = $this->findCache($client, CentralScopeCacheType::MacRegistrations);
        if ($cache === null) {
            return $this->refreshMacRegistrations($client, $centralHelper);
        }

        return $this->getMacRegistrations($client);
    }

    /**
     * Look up MACs in the cached registration table.
     *
     * @param  list<string>  $macAddresses
     * @return array<string, array{
     *     mac_address: string,
     *     client_name: string,
     *     enabled: bool|null,
     *     static_tags: list<string>
     * }|null>
     */
    public function lookupMacs(Client $client, array $macAddresses, ?CentralAPIHelper $centralHelper = null): array
    {
        $payload = $this->ensureMacRegistrations($client, $centralHelper);
        $byMac = [];
        foreach ($payload['entries'] as $entry) {
            $byMac[$entry['mac_address']] = $entry;
        }

        $result = [];
        foreach ($macAddresses as $rawMac) {
            $normalized = MacAddress::normalize((string) $rawMac);
            if ($normalized === null) {
                continue;
            }
            $result[$normalized] = $byMac[$normalized] ?? null;
        }

        return $result;
    }

    /**
     * @return array{
     *     entries: list<array{
     *         mac_address: string,
     *         client_name: string,
     *         enabled: bool|null,
     *         static_tags: list<string>
     *     }>,
     *     error: string|null,
     *     refreshed_at: string|null
     * }
     */
    public function refreshMacRegistrations(Client $client, ?CentralAPIHelper $centralHelper = null): array
    {
        $centralHelper ??= new CentralAPIHelper($client);
        $export = $centralHelper->exportMacCsvFile();
        $refreshedAt = now();

        if (($export['success'] ?? false) !== true) {
            $error = (string) ($export['error'] ?? 'Central MAC CSV export failed.');
            $this->persistCache(
                $client,
                CentralScopeCacheType::MacRegistrations,
                [],
                $refreshedAt,
                $error,
            );

            Log::warning('Failed to refresh Central NAC MAC registrations cache.', [
                'client_id' => $client->id,
                'error' => $error,
            ]);

            return [
                'entries' => [],
                'error' => $error,
                'refreshed_at' => $refreshedAt->toIso8601String(),
            ];
        }

        $parsed = CnacMacRegistrationCsv::parse((string) ($export['csv'] ?? ''));
        if ($parsed['error'] !== null) {
            $this->persistCache(
                $client,
                CentralScopeCacheType::MacRegistrations,
                [],
                $refreshedAt,
                $parsed['error'],
            );

            Log::warning('Failed to parse Central NAC MAC registrations CSV.', [
                'client_id' => $client->id,
                'error' => $parsed['error'],
            ]);

            return [
                'entries' => [],
                'error' => $parsed['error'],
                'refreshed_at' => $refreshedAt->toIso8601String(),
            ];
        }

        $this->persistCache(
            $client,
            CentralScopeCacheType::MacRegistrations,
            $parsed['entries'],
            $refreshedAt,
            null,
        );

        return [
            'entries' => $parsed['entries'],
            'error' => null,
            'refreshed_at' => $refreshedAt->toIso8601String(),
        ];
    }

    /**
     * @return array{
     *     sites: array<int, array{scopeName: string, scopeId: string}>,
     *     error: string|null,
     *     refreshed_at: string|null
     * }
     */
    public function refreshSites(Client $client, ?CentralAPIHelper $centralHelper = null): array
    {
        $centralHelper ??= new CentralAPIHelper($client);
        $result = $centralHelper->collectScopeManagementSites();
        $refreshedAt = now();

        $this->persistCache(
            $client,
            CentralScopeCacheType::Sites,
            $result['sites'],
            $refreshedAt,
            $result['error'],
        );

        if ($result['error'] !== null) {
            Log::warning('Failed to refresh Central sites cache.', [
                'client_id' => $client->id,
                'error' => $result['error'],
            ]);
        }

        return [
            'sites' => $result['sites'],
            'error' => $result['error'],
            'refreshed_at' => $refreshedAt->toIso8601String(),
        ];
    }

    /**
     * @return array{
     *     central_device_groups: array<int, array{scopeName: string, scopeId: string}>,
     *     device_group_options: array<int, array{scopeName: string, scopeId: string, isClassic: bool}>,
     *     error: string|null,
     *     classic_device_groups_error: string|null,
     *     refreshed_at: string|null
     * }
     */
    public function refreshGroups(Client $client, ?CentralAPIHelper $centralHelper = null): array
    {
        $centralHelper ??= new CentralAPIHelper($client);
        $groupsResult = $centralHelper->collectScopeManagementDeviceGroups();
        $centralDeviceGroups = $groupsResult['groups'];
        $centralDeviceGroupsError = $groupsResult['error'];

        $centralGroupNames = collect($centralDeviceGroups)
            ->pluck('scopeName')
            ->flip();
        $deviceGroupOptions = collect($centralDeviceGroups)
            ->map(fn (array $group) => [
                'scopeName' => $group['scopeName'],
                'scopeId' => $group['scopeId'],
                'isClassic' => false,
            ])
            ->values()
            ->all();

        $classicDeviceGroupsError = null;
        $classicGroupsResult = $centralHelper->classic_collect_all_group_names();
        if (array_key_exists('error', $classicGroupsResult)) {
            $classicDeviceGroupsError = $classicGroupsResult['error'];
        } else {
            $classicOnlyNames = collect($classicGroupsResult['names'] ?? [])
                ->filter(fn (string $name) => ! $centralGroupNames->has($name))
                ->sort()
                ->values();

            foreach ($classicOnlyNames as $name) {
                $deviceGroupOptions[] = [
                    'scopeName' => $name,
                    'scopeId' => '',
                    'isClassic' => true,
                ];
            }
        }

        $items = [
            'central_device_groups' => $centralDeviceGroups,
            'device_group_options' => $deviceGroupOptions,
            'classic_device_groups_error' => $classicDeviceGroupsError,
        ];

        $refreshedAt = now();

        $this->persistCache(
            $client,
            CentralScopeCacheType::Groups,
            $items,
            $refreshedAt,
            $centralDeviceGroupsError,
        );

        if ($centralDeviceGroupsError !== null) {
            Log::warning('Failed to refresh Central groups cache.', [
                'client_id' => $client->id,
                'error' => $centralDeviceGroupsError,
            ]);
        }

        return [
            'central_device_groups' => $centralDeviceGroups,
            'device_group_options' => $deviceGroupOptions,
            'error' => $centralDeviceGroupsError,
            'classic_device_groups_error' => $classicDeviceGroupsError,
            'refreshed_at' => $refreshedAt->toIso8601String(),
        ];
    }

    private function findCache(Client $client, CentralScopeCacheType $type): ?CentralScopeCache
    {
        return CentralScopeCache::query()
            ->where('client_id', $client->id)
            ->where('type', $type)
            ->first();
    }

    /**
     * @param  array<int|string, mixed>  $items
     */
    private function persistCache(
        Client $client,
        CentralScopeCacheType $type,
        array $items,
        \DateTimeInterface $refreshedAt,
        ?string $error,
    ): CentralScopeCache {
        return CentralScopeCache::query()->updateOrCreate(
            [
                'client_id' => $client->id,
                'type' => $type,
            ],
            [
                'items' => $items,
                'refreshed_at' => $refreshedAt,
                'last_error' => $error,
            ],
        );
    }
}
