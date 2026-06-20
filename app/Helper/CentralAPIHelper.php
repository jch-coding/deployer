<?php

namespace App\Helper;

use App\InterfaceKind;
use App\Models\Client;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\Site;
use App\Support\TrunkVlanRanges;
use App\VsxRole;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CentralAPIHelper
{
    public const VSX_KEEPALIVE_VRF = 'WHSE-VSX-Keep-Alive';

    public array $scopeManagement = [
        'hierarchy' => [
            'scope_hierarchy' => 'network-config/v1/hierarchy',
        ],
        'sites' => [
            'sites' => 'network-config/v1/sites',
        ],
        'site_collections' => [
            'site_collections' => 'network-config/v1/site-collections',
        ],
        'device_groups' => [
            'device_groups' => 'network-config/v1/device-groups',
        ],
    ];

    public array $system = [
        'system_info' => 'network-config/v1alpha1/system-info',
        'dns' => 'network-config/v1alpha1/dns',
        'ntp' => 'network-config/v1alpha1/ntp',
        'local_management' => 'network-config/v1alpha1/local-management',
    ];

    public array $routing_and_overlays = [
        'static_route' => 'network-config/v1alpha1/static-route',
        'vrf' => 'network-config/v1alpha1/vrfs',
    ];

    public array $configManagement = [
        'persona_assignment' => 'network-config/v1alpha1/persona-assignment',
        'persona_mapping' => 'network-config/v1alpha1/device-persona-mapping',
    ];

    public array $interfaces = [
        'interface_ethernet' => 'network-config/v1alpha1/ethernet-interfaces',
        'interface_portchannel' => 'network-config/v1alpha1/portchannels',
        'switch_port_profile' => 'network-config/v1alpha1/sw-port-profiles',
        'interface_vlan' => 'network-config/v1alpha1/vlan-interfaces',
        'interface_loopback' => 'network-config/v1alpha1/loopback-interfaces',
        'mirrors' => 'network-config/v1alpha1/mirrors',
    ];

    public array $vlans_and_networks = [
        'l2_vlans' => 'network-config/v1alpha1/layer2-vlan',
    ];

    public array $switchMonitoring = [
        'switches' => 'network-monitoring/v1/switches',
    ];

    public array $deviceMonitoring = [
        'devices' => 'network-monitoring/v1/devices',
    ];

    public array $high_availability = [
        'switch_stack' => 'network-config/v1alpha1/stacks',
        'vsx' => 'network-config/v1alpha1/vsx-profiles',
    ];

    public array $classic_monitoring = [
        'sites' => 'central/v2/sites',
        'switches' => 'monitoring/v1/switches',
        'aps' => 'monitoring/v2/aps',
    ];

    public array $classic_configuration = [
        'move_devices_to_group' => 'configuration/v1/devices/move',
        'preprovision_devices_to_group' => 'configuration/v1/preassign',
        'groups' => 'configuration/v2/groups',
        'groupsv3' => 'configuration/v3/groups',
    ];

    public array $classic_subscription = [
        'subscriptions' => 'platform/licensing/v1/subscriptions',
        'enabled_services' => 'platform/licensing/v1/services/enabled',
        'unassign_subscription' => 'platform/licensing/v1/subscriptions/unassign',
        'assign_subscription' => 'platform/licensing/v1/subscriptions/assign',
        'subscription_status' => 'platform/licensing/v1/subscriptions/stats',
        'device_inventory' => 'platform/device_inventory/v1/devices',
    ];

    public array $classic_firmware = [
        'firmware_versions' => 'firmware/v1/versions',
        'firmware_compliance' => 'firmware/v1/upgrade/compliance_version'
    ];

    public function __construct(public Client $client) {}

    private function classicApiUrl(string $path): string
    {
        return rtrim($this->client->classicBaseUrlString(), '/').'/'.ltrim($path, '/');
    }

    /**
     * Query parameters for LOCAL device-scoped interface GET/POST/PATCH calls.
     *
     * @return array{view-type: string, object-type: string, scope-id: string, device-function: string}
     */
    public static function localDeviceInterfaceQueryParameters(Device $device): array
    {
        return [
            'view-type' => 'LOCAL',
            'object-type' => 'LOCAL',
            'scope-id' => (string) $device->scope_id,
            'device-function' => static::deviceFunctionQueryValue($device),
        ];
    }

    public static function deviceFunctionQueryValue(Device $device): string
    {
        $value = $device->device_function;

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        return (string) $value;
    }

    public function getScopeIdFromCentral(Device $device)
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        }

        if (str_contains($device->device_function, 'SWITCH')) {
            $switchesResult = $this->get_all_switches();
            if (array_key_exists('error', $switchesResult)) {
                Log::error('failed to get switches from central. Using switch serial to retrieve scope-id. Will fail if switch is a stack.');
            } else {
                $stack_id = static::getStackId($device, $switchesResult);
                if (array_key_exists('error', $stack_id)) {
                    Log::error('failed to get stack-id from central. Using switch serial to retrieve scope-id.');

                    return ['error' => 'switch does not exist in central or the central API has returned an error.'];
                } else {
                    $device->stack_id = $stack_id['stackId'];
                    $device->save();
                }
            }
        }

        $response = Http::withToken($this->client->bearer_token)
            ->withQueryParameters([
                'id' => $device->stack_id ?? $device->serial,
                'type' => 'device',
            ])->get($this->client->base_url.$this->scopeManagement['hierarchy']['scope_hierarchy']);

        if (! $response->ok()) {
            return ['error' => 'failed to get scope-id from central.'];
        }

        return $this->extractScopeIdFromHierarchyResponse($response->json());
    }

    public function extractScopeIdFromHierarchyResponse(array $response)
    {
        if (! array_key_exists('items', $response)) {
            return ['error' => 'failed to get scope-id from central.'];
        } else {
            $items = $response['items'];
            $hierarchy = $items[0]['hierarchy'];

            return array_filter($hierarchy, fn ($item) => $item['childCount'] === null && $item['scopeType'] === 'device');
        }
    }

    public static function getStackId(Device $device, array $switches)
    {
        $condutor_serial = $device->serial;
        $conductor_switch = array_filter($switches, fn ($switch) => $switch['serialNumber'] === $condutor_serial);
        if (count($conductor_switch) === 0) {
            return ['error' => 'failed to get stack-id from central.'];
        } else {
            return ['stackId' => array_shift($conductor_switch)['stackId']];
        }
    }

    public function get_site_scope_id(Site $site)
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return null;
        }
        $response = $this->get_sites();
        if (is_array($response) && array_key_exists('error', $response)) {
            return null;
        }
        if (! $response->ok()) {
            return null;
        }
        $central_sites = $response->json('items', []);
        if (! is_array($central_sites)) {
            return null;
        }
        $central_site = array_find(
            $central_sites,
            fn ($central_site) => is_array($central_site) && ($central_site['scopeName'] ?? null) === $site->name
        );
        if (! is_array($central_site) || ! isset($central_site['scopeId'])) {
            return null;
        }

        return $central_site['scopeId'];
    }

    /**
     * @return array{sites: array<int, array{scopeName: string, scopeId: string}>, error: string|null}
     */
    public function collectScopeManagementSites(): array
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return [
                'sites' => [],
                'error' => 'Could not authenticate with Central to load sites.',
            ];
        }

        try {
            $limit = 100;
            $offset = 0;
            $raw_items = [];

            while (true) {
                $central_sites = $this->get_sites([
                    'offset' => $offset,
                    'limit' => $limit,
                ]);

                if (is_array($central_sites) && array_key_exists('error', $central_sites)) {
                    return [
                        'sites' => [],
                        'error' => 'Could not load sites from Central.',
                    ];
                }

                if (! $central_sites->ok()) {
                    return [
                        'sites' => [],
                        'error' => 'Could not load sites from Central.',
                    ];
                }

                $page_items = $central_sites->json('items', []);
                $raw_items = array_merge($raw_items, is_array($page_items) ? $page_items : []);

                if (count($page_items) < $limit) {
                    break;
                }

                $offset += $limit;
            }

            return [
                'sites' => $this->mapScopeManagementItems($raw_items),
                'error' => null,
            ];
        } catch (RequestException|ConnectionException) {
            return [
                'sites' => [],
                'error' => 'Could not load sites from Central.',
            ];
        }
    }

    /**
     * @return array{groups: array<int, array{scopeName: string, scopeId: string}>, error: string|null}
     */
    public function collectScopeManagementDeviceGroups(): array
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return [
                'groups' => [],
                'error' => 'Could not authenticate with Central to load device groups.',
            ];
        }

        try {
            $response = $this->get_device_groups();

            if (is_array($response) && array_key_exists('error', $response)) {
                return [
                    'groups' => [],
                    'error' => 'Could not load device groups from Central.',
                ];
            }

            if (! $response->ok()) {
                return [
                    'groups' => [],
                    'error' => 'Could not load device groups from Central.',
                ];
            }

            $items = $response->json('items', []);

            return [
                'groups' => $this->mapScopeManagementItems(is_array($items) ? $items : []),
                'error' => null,
            ];
        } catch (RequestException|ConnectionException) {
            return [
                'groups' => [],
                'error' => 'Could not load device groups from Central.',
            ];
        }
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array{scopeName: string, scopeId: string}>
     */
    private function mapScopeManagementItems(array $items): array
    {
        $mapped = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $scopeName = trim((string) ($item['scopeName'] ?? ''));
            if ($scopeName === '') {
                continue;
            }

            $mapped[] = [
                'scopeName' => $scopeName,
                'scopeId' => trim((string) ($item['scopeId'] ?? '')),
            ];
        }

        return $mapped;
    }

    /**
     * @param  Collection<int, Site>|iterable<Site>  $sites
     * @return array{updated: int, error: string|null}
     */
    public function syncScopeIdsForSites(iterable $sites): array
    {
        $sites = collect($sites)->unique('id')->values();

        if ($sites->isEmpty()) {
            return ['updated' => 0, 'error' => null];
        }

        if (! $this->client->handleBearerTokenAuth()) {
            return [
                'updated' => 0,
                'error' => 'Could not authenticate with Central to load site scope IDs.',
            ];
        }

        $limit = 100;
        $offset = 0;
        $returned_sites = [];

        while (true) {
            $central_sites = $this->get_sites([
                'offset' => $offset,
                'limit' => $limit,
            ]);

            if (is_array($central_sites) && array_key_exists('error', $central_sites)) {
                return [
                    'updated' => 0,
                    'error' => 'Could not load site scope IDs from Central.',
                ];
            }

            if (! $central_sites->ok()) {
                return [
                    'updated' => 0,
                    'error' => 'Could not load site scope IDs from Central.',
                ];
            }

            $page_items = $central_sites->json('items', []);
            $returned_sites = array_merge($returned_sites, is_array($page_items) ? $page_items : []);

            if (count($page_items) < $limit) {
                break;
            }

            $offset += $limit;
        }

        $updated = 0;
        foreach ($sites as $site) {
            $central_site = array_find(
                $returned_sites,
                fn (mixed $s): bool => is_array($s) && ($s['scopeName'] ?? null) === $site->name
            );

            if (! is_array($central_site) || ! isset($central_site['scopeId'])) {
                continue;
            }

            $site->scope_id = $central_site['scopeId'];
            $site->save();
            $updated++;
        }

        $stillMissing = $sites->filter(fn (Site $site): bool => blank($site->scope_id));
        if ($stillMissing->isNotEmpty()) {
            $names = $stillMissing->pluck('name')->filter()->values()->all();

            return [
                'updated' => $updated,
                'error' => 'Could not resolve scope ID for sites: '.implode(', ', $names).'.',
            ];
        }

        return ['updated' => $updated, 'error' => null];
    }

    public function getSystemInfo(Device $device)
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters([
                    'view-type' => 'LOCAL',
                    'object-type' => 'LOCAL',
                    'scope-id' => $device->scope_id,
                    'device-function' => $device->device_function,
                ])->get($this->client->base_url.$this->system['system_info']);

            return $response;
        }
    }

    public function updateSystemInfo(Device $device)
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $body = ['profile' => [['name' => 'sys-system-info-profile', 'hostname' => $device->name]]];
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters([
                    'object-type' => 'LOCAL',
                    'scope-id' => $device->scope_id,
                    'device-function' => $device->device_function,
                ])->patch($this->client->base_url.$this->system['system_info'], $body);

            return $response;
        }
    }

    public function postSystemInfo(Device $device)
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $body = ['profile' => [['name' => 'sys-system-info-profile', 'hostname' => $device->name]]];
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters([
                    'object-type' => 'LOCAL',
                    'scope-id' => $device->scope_id,
                    'device-function' => $device->device_function,
                ])->post($this->client->base_url.$this->system['system_info'], $body);

            return $response;
        }
    }

    /***
     * @param array $devices = ['serial1', 'serial2', ...]
     * @param string $device_function
     * @param $queryParameters
     * @return \GuzzleHttp\Promise\PromiseInterface|\Illuminate\Http\Client\Promises\LazyPromise|\Illuminate\Http\Client\Response|string[]
     * @throws \Illuminate\Http\Client\ConnectionException
     */
    public function assignDeviceFunction(array $devices, string $device_function)
    {
        $device_id_list_v2_for_switches = array_map(fn ($serial) => ['device-id-v2' => $serial, 'deployment-type' => 'DEPLOYMENT_TYPE_UNKNOWN'], $devices);
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $body = [
                'persona-device-list' => [
                    [
                        'device-function' => $device_function,
                        'device-id-list-v2' => str_contains($device_function, 'SWITCH') ? $device_id_list_v2_for_switches : array_map(fn ($serial) => ['device-id-v2' => $serial], $devices),
                    ],
                ],
            ];
            $response = Http::withToken($this->client->bearer_token)
                ->post($this->client->base_url.$this->configManagement['persona_assignment'].'/'.$device_function, $body);

            return $response;
        }
    }

    /**
     * @param  array  $vsx_profile  = [
     *                              'name' => string,
     *                              'auto-role' => boolean,
     *                              'peer1' => [
     *                              'device-serial' => string,
     *                              'keepalive-device => [
     *                              'keepalive-type' => KA_ETHERNET | KA_PORTCHANNEL,
     *                              'keepalive-version' => IPV4 | IPV6,
     *                              'source-ip' => string,
     *                              'peer-ip' => string,
     *                              'ethernet-ifname | portchannel-ifname' => string,
     *                              'vrf' => string,
     *                              ],
     *                              'role' => VSX_PRIMARY | VSX_SECONDARY,
     *                              'ethernet-interface' => [
     *                              [
     *                              'ethernet-ifname' => string,
     *                              'vrf-forwarding' => string,
     *                              'ipv4-address' => string with netmask,
     *                              'existing-ethernet-ip' => boolean,
     *                              ]
     *                              'port-channel-interface' => [
     *                              [
     *                              'switchport' => [
     *                              'tag' => boolean,
     *                              'interface-mode' => ACCESS | TRUNK,
     *                              'access-vlan' => integer,
     *                              'native-vlan' => integer,
     *                              'trunk-vlan-all' => boolean,
     *                              'trunk-vlan-ranges' => string,
     *                              'trunk-vlan-all' => boolean,
     *                              ],
     *                              'ipv4-address' => string with netmask,
     *                              'existing-portchannel-ip' => boolean,
     *                              'portchannel-ifname' => string,
     *                              'enable' => boolean,
     *                              'description' => string,
     *                              'lacp-mode' => ACTIVE | PASSIVE | AUTO,
     *                              'port-list' => [ string, ...],
     *                              'trunk-type' => LACP | TRUNK | DT_TRUNK | MULTI_CHASSIS | MULTI_CHASSIS_STATIC,
     *                              'routing' => boolean,
     *                              'vrf-forwarding' => string,
     *                              ],
     *                              'inter-switch-link' => [
     *                              'portchannel-interface' => string,
     *                              ],
     *                              'vrf' => [
     *                              [
     *                              'vrf-name' => string,
     *                              'existing-vrf' => boolean,
     *                              ], ...
     *                              ]
     *                              ]
     *                              ],
     *                              'peer2' => [ same as peer1 except 'device-serial' => string, 'role' => VSX_PRIMARY | VSX_SECONDARY, ],
     *                              'sync-features' => [
     *                              'system-mac' => string,
     *                              ]
     *                              ]
     *                              ]
     *                              The keepalive interface/portchannel as well as the inter-switch-link portchannel can be configured as part of the vsx profile if they have not been configured yet.
     *                              If the keepalive interface/portchannel and/or the inter-switch-link portchannel has been configured, the objects can be omitted in the vsx profile.
     */
    public function post_vsx_profile(array $vsx_profile = [], string $site_scope_id = '')
    {
        if (empty($site_scope_id)) {
            return ['error' => 'site scope id is required.'];
        }
        $queryParameters = [
            'scope-id' => $site_scope_id,
            'object-type' => 'LOCAL',
            'device-function' => 'SERVICE_PERSONA',
        ];
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($queryParameters)
                ->post($this->client->base_url.$this->high_availability['vsx'].'/'.$vsx_profile['name'], $vsx_profile);

            return $response;
        }
    }

    public function post_vsf_profile(Device $device, array $vsf_profile = [])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        }

        $stack_name = $device->name.'-STACK';

        $vsf_auto_stack_profile = [
            'platform' => $this->convert_sku_to_platform($device->sku),
            'name' => $stack_name,
            'auto-stack' => true,
            'split-detection-method' => 'MGMT',
            'members' => [
                [
                    'id' => 1,
                    'sku' => $device->sku,
                    'serial' => $device->serial,
                    'use-auto-stacking-ports' => true,
                ],
            ],
        ];

        if (empty($vsf_profile)) {
            $vsf_profile = $vsf_auto_stack_profile;
        }

        $response = Http::withToken($this->client->bearer_token)
            ->withQueryParameters([
                'object-type' => 'LOCAL',
                'scope-id' => $device->site->scope_id,
                'device-function' => $device->device_function,
            ])->post($this->client->base_url.$this->high_availability['switch_stack'].'/'.$stack_name, $vsf_profile);

        return $response;
    }

    public function convert_sku_to_platform(string $sku)
    {
        $platform_6300 = [
            'R8S90A',
            'R8S89A',
            'R8S91A',
            'R8S92A',
            'JL658A',
            'JL659A',
            'JL660A',
            'JL661A',
            'JL662A',
            'JL663A',
            'JL659A',
            'JL665A',
            'JL666A',
            'JL667A',
            'JL668A',
            'R9F63A',
            'S0G03A',
            'S0G04A',
            'S0G05A',
            'S0G06A',
            'S0G95A',
            'S0G96A',
            'S0G97A',
            'S0G98A',
            'S0G02A',
            'S0X44A',
            'S4P41A',
            'S4P42A',
            'S4P43A',
            'S4P44A',
            'S4P45A',
            'S4P46A',
            'S4P47A',
            'S4P48A',
        ];
        $platform_6300L = [
            'JL762A',
            'S0E91A',
        ];
        $platform_6200 = [
            'R8Q70A',
            'JL724A',
            'R8Q72A',
            'S0M82A',
            'JL724B',
            'JL725B',
            'S0M83A',
            'JL726B',
            'R8Q67A',
            'R8Q68A',
            'S0M84A',
            'R8Q69A',
            'JL727B',
            'S0M85A',
            'JL728B',
            'R8Q71A',
            'JL728A',
            'S0M81A',
            'JL725A',
            'JL726A',
            'JL727A',
        ];
        switch ($sku) {
            case in_array($sku, $platform_6300):
                return 'PLATFORM_6300';
            case in_array($sku, $platform_6300L):
                return 'PLATFORM_6300L';
            case in_array($sku, $platform_6200):
                return 'PLATFORM_6200';
        }
    }

    public static function categorize_device_interfaces(array $deviceInterfaces)
    {
        return collect($deviceInterfaces)->groupBy(function ($interface) {
            return $interface->lacp_profile ? 'portchannel_interfaces' : 'ethernet_interfaces';
        })->toArray();
    }

    public static function build_portchannel_from_device_interface(DeviceInterface $deviceInterface, bool $forCreatingLAG = false)
    {
        if (static::is_routed_lag_interface($deviceInterface)) {
            return $forCreatingLAG
                ? static::build_routed_lag_portchannel_post_body($deviceInterface)
                : static::build_routed_lag_portchannel_patch_body($deviceInterface);
        }

        if ($deviceInterface->sw_profile !== null && ! $forCreatingLAG) {
            return [
                'name' => $deviceInterface->interface,
                'sw-profile' => $deviceInterface->sw_profile,
            ];
        }

        $switch_port_configuration = static::build_switchport_from_device_interface($deviceInterface, $forCreatingLAG);

        if ($deviceInterface->lacp_profile === null) {
            return $switch_port_configuration;
        }

        $lacp_profile = [
            'mode' => $deviceInterface->lacp_profile->mode,
            'rate' => $deviceInterface->lacp_profile->rate,
        ];
        $trunkType = $deviceInterface->lacp_profile->trunk_type;
        $switch_port_configuration['trunk-type'] = $deviceInterface->lacp_profile->trunk_type;
        $switch_port_configuration['port-list'] = $deviceInterface->lacp_profile->port_list;
        $switch_port_configuration['enable'] = $deviceInterface->enable;

        if (in_array($trunkType, ['LACP', 'MULTI_CHASSIS'], true)) {
            $switch_port_configuration['lacp'] = $lacp_profile;
        }

        return $switch_port_configuration;
    }

    public static function build_switchport_from_device_interface(DeviceInterface $deviceInterface, bool $forCreatingLAG = false)
    {
        $switch_port = [];
        $stp_profile = [];

        if ($deviceInterface->switch_port !== null) {
            $switch_port = ArrayHelper::take_only_keys(
                ['access-vlan', 'native-vlan', 'trunk-vlan-all', 'interface-mode', 'trunk-vlan-ranges'],
                ArrayHelper::replace_keys(
                    ArrayHelper::replace_underscores_with_dashes(array_keys($deviceInterface->switch_port->toArray())),
                    array_values($deviceInterface->switch_port->toArray())
                )
            );
            if ($switch_port['trunk-vlan-ranges'] === null) {
            }
        }
        if ($deviceInterface->stp_profile !== null) {
            $stp_profile = ArrayHelper::take_only_keys(
                ['admin-edge-port', 'admin-edge-port-trunk', 'bpdu-guard', 'loop-guard'],
                ArrayHelper::replace_keys(
                    ArrayHelper::replace_underscores_with_dashes(array_keys($deviceInterface->stp_profile->toArray())),
                    array_values($deviceInterface->stp_profile->toArray())
                )
            );
        }
        $switchport_rest_body = [
            'name' => $deviceInterface->interface,
        ];
        if ($deviceInterface->sw_profile !== null && ! $forCreatingLAG) {
            $switchport_rest_body['sw-profile'] = $deviceInterface->sw_profile;
        } else {
            $switchport_rest_body['vsx'] = ['shutdown-on-split' => (bool) $deviceInterface->shutdown_on_split];
            if ($deviceInterface->description !== null) {
                $switchport_rest_body['description'] = $deviceInterface->description;
            }
            if ($deviceInterface->portchannel_lag !== null) {
                $switchport_rest_body['portchannel-lag'] = $deviceInterface->portchannel_lag;
            }
            $switchport_rest_body = array_merge($switchport_rest_body, [
                'switchport' => $switch_port,
                'stp' => $stp_profile,
            ]);
        }

        return array_filter($switchport_rest_body, fn ($value) => $value !== []);
    }

    public static function is_ethernet_interface_part_of_any_lag(DeviceInterface $deviceInterface): bool
    {
        $device = $deviceInterface->device;
        if ($device === null) {
            return false;
        }

        $lag_interfaces = $device->interfaces()
            ->whereNotNull('lacp_profile_id')
            ->with('lacp_profile')
            ->get();

        foreach ($lag_interfaces as $lag_interface) {
            if (in_array($deviceInterface->interface, $lag_interface->lacp_profile?->port_list ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    public static function is_routed_ethernet_interface(DeviceInterface $deviceInterface): bool
    {
        return InterfaceHelper::isRoutedEthernetRow([
            'ip_address' => $deviceInterface->ip_address,
            'interface' => $deviceInterface->interface,
        ]);
    }

    public static function is_routed_lag_interface(DeviceInterface $deviceInterface): bool
    {
        $ipAddress = $deviceInterface->ip_address;
        if ($ipAddress === null || trim((string) $ipAddress) === '') {
            return false;
        }

        if ($deviceInterface->interface_kind === InterfaceKind::LAG) {
            return true;
        }

        return $deviceInterface->lacp_profile_id !== null;
    }

    public static function build_routed_lag_portchannel_post_body(DeviceInterface $deviceInterface): array
    {
        $body = [
            'name' => $deviceInterface->interface,
            'ipv4' => [
                'address' => $deviceInterface->ip_address,
            ],
        ];

        if ($deviceInterface->description !== null) {
            $body['description'] = $deviceInterface->description;
        }

        $vrfForwarding = static::routedLagVrfForwarding($deviceInterface->vrf_forwarding);
        if ($vrfForwarding !== null) {
            $body['vrf-forwarding'] = $vrfForwarding;
        }

        if ($deviceInterface->lacp_profile !== null) {
            $trunkType = $deviceInterface->lacp_profile->trunk_type;
            $body['trunk-type'] = $trunkType;
            $body['port-list'] = $deviceInterface->lacp_profile->port_list;
            $body['enable'] = $deviceInterface->enable;

            if (in_array($trunkType, ['LACP', 'MULTI_CHASSIS'], true)) {
                $body['lacp'] = [
                    'mode' => $deviceInterface->lacp_profile->mode,
                    'rate' => $deviceInterface->lacp_profile->rate,
                ];
            }
        }

        return $body;
    }

    public static function build_routed_lag_portchannel_patch_body(DeviceInterface $deviceInterface): array
    {
        $body = [
            'routing' => true,
            'ipv4' => [
                'address' => $deviceInterface->ip_address,
            ],
        ];

        $vrfForwarding = static::routedLagVrfForwarding($deviceInterface->vrf_forwarding);
        if ($vrfForwarding !== null) {
            $body['vrf-forwarding'] = $vrfForwarding;
        }

        return $body;
    }

    protected static function routedLagVrfForwarding(?string $vrfForwarding): ?string
    {
        if ($vrfForwarding === null || trim($vrfForwarding) === '' || trim($vrfForwarding) === 'default') {
            return null;
        }

        return trim($vrfForwarding);
    }

    public static function build_routed_ethernet_interface_patch_body(DeviceInterface $deviceInterface): array
    {
        $ipv4 = [
            'address' => $deviceInterface->ip_address,
        ];
        $vrfForwarding = $deviceInterface->vrf_forwarding;
        if ($vrfForwarding !== null && trim($vrfForwarding) !== '') {
            $ipv4['vrf-forwarding'] = $vrfForwarding;
        }

        return array_filter([
            'name' => $deviceInterface->interface,
            'description' => $deviceInterface->description,
            'routing' => true,
            'ipv4' => $ipv4,
        ], fn ($value) => $value !== null);
    }

    public static function build_ethernet_interface_patch_body(DeviceInterface $deviceInterface): array
    {
        if (static::is_routed_ethernet_interface($deviceInterface)) {
            return static::build_routed_ethernet_interface_patch_body($deviceInterface);
        }

        if (static::is_ethernet_interface_part_of_any_lag($deviceInterface)) {
            return array_filter([
                'name' => $deviceInterface->interface,
                'description' => $deviceInterface->description,
            ], fn ($value) => $value !== null);
        }

        return static::build_switchport_from_device_interface($deviceInterface);
    }

    public function patch_ethernet_interface(DeviceInterface $deviceInterface, array $patch_body = [])
    {
        if (empty($patch_body)) {
            $interface_rest_body = static::build_ethernet_interface_patch_body($deviceInterface);
        } else {
            $interface_rest_body = $patch_body;
        }

        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters([
                    'view-type' => 'LOCAL',
                    'object-type' => 'LOCAL',
                    'scope-id' => $deviceInterface->device->scope_id,
                    'device-function' => $deviceInterface->device->device_function,
                ])->withBody(json_encode($interface_rest_body))
                ->patch($this->client->base_url.$this->interfaces['interface_ethernet'].'/'.$deviceInterface->interface);

            return $response;
        }
    }

    public function get_ethernet_interface(DeviceInterface $deviceInterface)
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters([
                    'view-type' => 'LOCAL',
                    'object-type' => 'LOCAL',
                    'scope-id' => $deviceInterface->device->scope_id,
                    'device-function' => $deviceInterface->device->device_function,
                ])->get($this->client->base_url.$this->interfaces['interface_ethernet'].'/'.$deviceInterface->interface);

            return $response;
        }
    }

    public function get_ethernet_interfaces(Device $device)
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters(static::localDeviceInterfaceQueryParameters($device))
                ->get($this->client->base_url.$this->interfaces['interface_ethernet']);

            return $response;
        }
    }

    public function get_monitoring_interfaces(Device $device, array $filter = [])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $queryParameters = array_merge(static::localDeviceInterfaceQueryParameters($device), $filter);
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($queryParameters)
                ->get($this->client->base_url.$this->interfaces['interface_ethernet']).'/'.$device->serial.'/interfaces';

            return $response;
        }
    }

    public function post_interface_portchannel(DeviceInterface $deviceInterface)
    {
        $switch_port = static::build_portchannel_from_device_interface($deviceInterface, true);

        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters([
                    'view-type' => 'LOCAL',
                    'object-type' => 'LOCAL',
                    'scope-id' => $deviceInterface->device->scope_id,
                    'device-function' => $deviceInterface->device->device_function,
                ])->post($this->client->base_url.$this->interfaces['interface_portchannel'].'/'.$deviceInterface->interface, $switch_port);

            return $response;
        }
    }

    public function get_interface_portchannels($queryParameters = ['view-type' => 'LIBRARY'])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($queryParameters)
                ->get($this->client->base_url.$this->interfaces['interface_portchannel']);

            return $response;
        }
    }

    /**
     * Page through all portchannels using cursor pagination (limit + next).
     *
     * @param  array<string, mixed>  $queryParameters
     * @return array<int, array<string, mixed>>|array{error: string}
     */
    public function get_all_interface_portchannels(array $queryParameters = []): array
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        }

        $allItems = [];
        $limit = 100;
        $next = null;

        while (true) {
            $params = array_merge($queryParameters, ['limit' => $limit]);
            if ($next !== null && $next !== '') {
                $params['next'] = $next;
            }

            $response = $this->get_interface_portchannels($params);

            if (is_array($response) && array_key_exists('error', $response)) {
                return ['error' => (string) $response['error']];
            }

            if (! $response instanceof Response || ! $response->ok()) {
                $message = $response instanceof Response
                    ? (string) ($response->json('message') ?? $response->body())
                    : '';

                return ['error' => $message !== '' ? $message : 'Failed to fetch portchannels from Central.'];
            }

            $pageItems = $response->json('interface', []);
            if ($pageItems === []) {
                $pageItems = $response->json('items', []);
            }
            if (! is_array($pageItems)) {
                $pageItems = [];
            }

            if ($pageItems === []) {
                break;
            }

            foreach ($pageItems as $item) {
                if (is_array($item)) {
                    $allItems[] = $item;
                }
            }

            $next = $response->json('next');
            if ($next === null || $next === '') {
                break;
            }
        }

        return $allItems;
    }

    public function patch_interface_portchannel(DeviceInterface $deviceInterface, $queryParameters = [])
    {
        $switch_port = static::build_portchannel_from_device_interface($deviceInterface);
        if (empty($queryParameters)) {
            $queryParameters = [
                'view-type' => 'LOCAL',
                'object-type' => 'LOCAL',
                'scope-id' => $deviceInterface->device->scope_id,
                'device-function' => $deviceInterface->device->device_function,
            ];
        }
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($queryParameters)
                ->patch($this->client->base_url.$this->interfaces['interface_portchannel'].'/'.$deviceInterface->interface, $switch_port);

            return $response;
        }
    }

    public function delete_interface_portchannel(string $portchannel_name, $queryParameters = [])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($queryParameters)
                ->delete($this->client->base_url.$this->interfaces['interface_portchannel'].'/'.$portchannel_name);

            return $response;
        }
    }

    public function get_vlan_interfaces(Device $device)
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters(static::localDeviceInterfaceQueryParameters($device))
                ->get($this->client->base_url.$this->interfaces['interface_vlan']);

            return $response;
        }
    }

    public function post_vlan_interface(DeviceInterface $deviceInterface)
    {
        $vlan_id = $deviceInterface->interface;
        $interface_vlan_body = [
            'id' => $vlan_id,
            'ipv4' => [
                'address' => $deviceInterface->ip_address,
            ],
            'enable' => $deviceInterface->enable,
            'is-valid' => true,
        ];
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters([
                    'view-type' => 'LOCAL',
                    'object-type' => 'LOCAL',
                    'scope-id' => $deviceInterface->device->scope_id,
                    'device-function' => $deviceInterface->device->device_function,
                ])
                ->post($this->client->base_url.$this->interfaces['interface_vlan'].'/'.$vlan_id, $interface_vlan_body);

            return $response;
        }
    }

    public function patch_vlan_interface(DeviceInterface $deviceInterface)
    {
        $vlan_id = $deviceInterface->interface;
        $interface_vlan_body = [
            'id' => $vlan_id,
            'ipv4' => [
                'address' => $deviceInterface->ip_address,
            ],
            'enable' => $deviceInterface->enable,
            'is-valid' => true,
        ];
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters([
                    'view-type' => 'LOCAL',
                    'object-type' => 'LOCAL',
                    'scope-id' => $deviceInterface->device->scope_id,
                    'device-function' => $deviceInterface->device->device_function,
                ])
                ->patch($this->client->base_url.$this->interfaces['interface_vlan'].'/'.$vlan_id, $interface_vlan_body);

            return $response;
        }
    }

    public function get_l2_vlans($query_parameters = ['view-type' => 'LIBRARY'])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($query_parameters)
                ->get($this->client->base_url.$this->vlans_and_networks['l2_vlans']);

            return $response;
        }
    }

    /***
     * @param Device $device
     * @param array $l2_vlan = ['vlan' => VLAN_ID]
     * @return \GuzzleHttp\Promise\PromiseInterface|\Illuminate\Http\Client\Promises\LazyPromise|\Illuminate\Http\Client\Response|string[]
     * @throws \Illuminate\Http\Client\ConnectionException
     */
    public function post_l2_vlan(array $query_params, array $l2_vlan)
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($query_params)
                ->post($this->client->base_url.$this->vlans_and_networks['l2_vlans'].'/'.$l2_vlan['vlan'], $l2_vlan);

            return $response;
        }
    }

    public function delete_l2_vlan(Device $device, string $l2_vlan)
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters([
                    'object-type' => 'LOCAL',
                    'scope-id' => $device->scope_id,
                    'device-function' => $device->device_function,
                ])
                ->delete($this->client->base_url.$this->vlans_and_networks['l2_vlans'].'/'.$l2_vlan);

            return $response;
        }
    }

    public function get_dns_profiles($queryParameters = ['view-type' => 'LIBRARY'])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($queryParameters)
                ->get($this->client->base_url.$this->system['dns']);

            return $response;
        }
    }

    public function delete_dns_profile(string $profile_name, $queryParameters = ['view-type' => 'LIBRARY'])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($queryParameters)
                ->delete($this->client->base_url.$this->system['dns'].'/'.$profile_name);

            return $response;
        }
    }

    public function get_ntp_profiles($queryParameters = ['view-type' => 'LIBRARY'])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($queryParameters)
                ->get($this->client->base_url.$this->system['ntp']);

            return $response;
        }
    }

    public function delete_ntp_profile(string $profile_name, $queryParameters = ['view-type' => 'LIBRARY'])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($queryParameters)
                ->delete($this->client->base_url.$this->system['ntp'].'/'.$profile_name);

            return $response;
        }
    }

    public function get_static_route($query_parameters = ['view-type' => 'LIBRARY'])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($query_parameters)
                ->get($this->client->base_url.$this->routing_and_overlays['static_route']);

            return $response;
        }
    }

    public function delete_static_route(string $profile_name, $queryParameters = ['view-type' => 'LIBRARY'])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($queryParameters)
                ->delete($this->client->base_url.$this->routing_and_overlays['static_route'].'/'.$profile_name);

            return $response;
        }
    }

    public function get_vrf($vrf_name = '', $queryParameters = ['view-type' => 'LIBRARY'])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($queryParameters)
                ->get($this->client->base_url.$this->routing_and_overlays['vrf'].'/'.$vrf_name);

            return $response;
        }
    }

    public function get_vrfs($queryParameters = ['view-type' => 'LIBRARY'])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($queryParameters)
                ->get($this->client->base_url.$this->routing_and_overlays['vrf']);

            return $response;
        }
    }

    /**
     * @return array{view-type: string, scope-id: string, device-function: string}
     */
    public static function localVrfQueryParameters(Device $device, string $scopeId): array
    {
        return [
            'view-type' => 'LOCAL',
            'scope-id' => $scopeId,
            'device-function' => static::deviceFunctionQueryValue($device),
        ];
    }

    public function resolveGroupScopeId(Device $device): ?string
    {
        if (! filled($device->group)) {
            return null;
        }

        $scopeId = $this->get_scopeid_for_device_group($device->group);
        if (is_array($scopeId) && array_key_exists('error', $scopeId)) {
            return null;
        }

        if (! is_string($scopeId) || $scopeId === '') {
            return null;
        }

        return $scopeId;
    }

    public function resolveSiteScopeId(Device $device): ?string
    {
        $site = $device->site;
        if ($site === null) {
            return null;
        }

        if (filled($site->scope_id)) {
            return (string) $site->scope_id;
        }

        $scopeId = $this->get_site_scope_id($site);
        if ($scopeId !== null && $scopeId !== '') {
            $site->scope_id = $scopeId;
            $site->save();
        }

        return $scopeId;
    }

    /**
     * @return list<string>
     */
    public function resolveVrfLookupScopeIds(Device $device): array
    {
        $scopeIds = [];

        $groupScopeId = $this->resolveGroupScopeId($device);
        if ($groupScopeId !== null) {
            $scopeIds[] = $groupScopeId;
        }

        $siteScopeId = $this->resolveSiteScopeId($device);
        if ($siteScopeId !== null) {
            $scopeIds[] = $siteScopeId;
        }

        if (filled($device->scope_id)) {
            $scopeIds[] = (string) $device->scope_id;
        }

        return $scopeIds;
    }

    /**
     * @param  list<string>  $scopeIds
     * @return list<array<string, mixed>>|null
     */
    public function fetchVrfsFromScopes(Device $device, array $scopeIds): ?array
    {
        $latestVrfs = null;

        foreach ($scopeIds as $scopeId) {
            $response = $this->get_vrfs(static::localVrfQueryParameters($device, $scopeId));
            if (is_array($response) && array_key_exists('error', $response)) {
                continue;
            }

            if (! $response->ok()) {
                continue;
            }

            $vrfs = $response->json('vrf', []);
            if (is_array($vrfs) && $vrfs !== []) {
                $latestVrfs = $vrfs;
            }
        }

        return $latestVrfs;
    }

    /**
     * @param  list<array<string, mixed>>  $vrfs
     */
    public static function vrfNameExists(array $vrfs, string $name): bool
    {
        foreach ($vrfs as $vrf) {
            if (is_array($vrf) && ($vrf['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    public function resolveVrfPostScopeId(Device $device): ?string
    {
        if (filled($device->group)) {
            $groupScopeId = $this->resolveGroupScopeId($device);
            if ($groupScopeId !== null) {
                return $groupScopeId;
            }
        }

        return $this->resolveSiteScopeId($device);
    }

    /**
     * @return array{ok: true, created?: bool}|array{error: string}
     */
    public function ensureVrfForRoutedInterface(DeviceInterface $deviceInterface): array
    {
        if (! static::is_routed_ethernet_interface($deviceInterface) && ! static::is_routed_lag_interface($deviceInterface)) {
            return ['ok' => true];
        }

        $vrfName = trim((string) ($deviceInterface->vrf_forwarding ?? ''));
        if ($vrfName === '' || $vrfName === 'default') {
            return ['ok' => true];
        }

        $device = $deviceInterface->device;
        if ($device === null) {
            return ['error' => 'Device not found for interface.'];
        }

        $vrfs = $this->fetchVrfsFromScopes($device, $this->resolveVrfLookupScopeIds($device));
        if ($vrfs !== null && static::vrfNameExists($vrfs, $vrfName)) {
            return ['ok' => true];
        }

        $postScopeId = $this->resolveVrfPostScopeId($device);
        if ($postScopeId === null) {
            return ['error' => 'Could not resolve group or site scope ID to create VRF.'];
        }

        $response = $this->post_vrf(
            ['name' => $vrfName],
            static::localVrfQueryParameters($device, $postScopeId),
        );

        if (is_array($response) && array_key_exists('error', $response)) {
            return ['error' => (string) $response['error']];
        }

        if (! $response->ok()) {
            $message = (string) ($response->json('message') ?? $response->body());

            return ['error' => $message !== '' ? $message : 'Failed to create VRF in Central.'];
        }

        return ['ok' => true, 'created' => true];
    }

    /**
     * @param  array  $vrf  = [ 'name' => string ]
     */
    public function post_vrf(array $vrf, array $queryParameters = [])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($queryParameters)
                ->post($this->client->base_url.$this->routing_and_overlays['vrf'].'/'.$vrf['name'], $vrf);

            return $response;
        }
    }

    public function get_sw_port_profile($profile_name = '', $queryParameters = ['view-type' => 'LIBRARY'])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($queryParameters
                )->get($this->client->base_url.$this->interfaces['switch_port_profile'].'/'.$profile_name);

            return $response;
        }
    }

    public function post_sw_port_profile(array $sw_port_profile, array $queryParameters = [])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($queryParameters)
                ->post($this->client->base_url.$this->interfaces['switch_port_profile'].'/'.$sw_port_profile['profile-name'], $sw_port_profile);

            return $response;
        }
    }

    public function patch_sw_port_profile(array $sw_port_profile, array $queryParameters = [])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($queryParameters)
                ->patch($this->client->base_url.$this->interfaces['switch_port_profile'].'/'.$sw_port_profile['profile-name'], $sw_port_profile);

            return $response;
        }
    }

    public function get_sw_port_profiles($profile_name = '', $queryParameters = ['view-type' => 'LIBRARY'])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($queryParameters
                )->get($this->client->base_url.$this->interfaces['switch_port_profile']);

            return $response;
        }
    }

    /***
     * @param array $loopback_interface [ 'id' => LOOPBACK_ID, 'ipv4-prefix' => IPV4_ADRESS/PREFIX]
     */

    public function post_interface_loopback(array $loopback_interface, Device $device)
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters([
                    'view-type' => 'LOCAL',
                    'object-type' => 'LOCAL',
                    'scope-id' => $device->scope_id,
                    'device-function' => $device->device_function,
                ])
                ->post($this->client->base_url.$this->interfaces['interface_loopback'].'/'.$loopback_interface['id'], $loopback_interface);

            return $response;
        }
    }

    /**
     * Single-page GET for network-monitoring switches.
     *
     * @param  array<string, mixed>  $filter  Query parameters:
     *                                        - limit: page size (0–1000)
     *                                        - next: pagination cursor from a previous response
     *                                        - filter: OData filter (siteId, siteName, model, status, deployment)
     *                                        - sort: sort expression
     */
    public function get_switches(array $filter = [])
    {
        $response = Http::withToken($this->client->bearer_token)
            ->withQueryParameters($filter)
            ->get($this->client->base_url.$this->switchMonitoring['switches']);

        return $response;
    }

    /**
     * Page through all switches using cursor pagination (limit + next).
     *
     * @param  array<string, mixed>  $filter  Optional OData filter/sort (limit and next are managed internally)
     * @return array<int, array<string, mixed>>|array{error: string}
     */
    public function get_all_switches(array $filter = []): array
    {
        $allItems = [];
        $limit = 100;
        $next = null;

        while (true) {
            $params = array_merge($filter, ['limit' => $limit]);
            if ($next !== null && $next !== '') {
                $params['next'] = $next;
            }

            $response = $this->get_switches($params);

            if (! $response->ok()) {
                return ['error' => 'failed to get switches from central.'];
            }

            $pageItems = $response->json('items', []);
            if (! is_array($pageItems)) {
                $pageItems = [];
            }

            if ($pageItems === []) {
                break;
            }

            $allItems = array_merge($allItems, $pageItems);

            $next = $response->json('next');
            if ($next === null || $next === '') {
                break;
            }
        }

        return $allItems;
    }

    public function get_mirrors($queryParameters = [])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($queryParameters)
                ->get($this->client->base_url.$this->interfaces['mirrors']);

            return $response;
        }
    }

    /**
     * @param  array  $mirror  [
     *                         'name' => string,
     *                         'description' => string,
     *                         'session' => [
     *                         'enable' => boolean,
     *                         'session-id' => number 1 - 4 inclusive,
     *                         'session-destination' => [
     *                         'destination-switch-serial' => string,
     *                         'destination-type' => CPU | INTERFACES | TUNNEL,
     *                         'eth-interfaces' => [
     *                         'eth-interface' => string,
     *                         ]
     *                         ],
     *                         'session-sources' => [
     *                         'vlans' => [
     *                         [
     *                         'direction' => RX | TX | BOTH,
     *                         'vlan-id' => number 1 - 4094 inclusive,
     *                         ]
     *                         ]
     *                         ]
     *                         ]
     *                         ]
     */
    public function post_mirror(array $mirror, array $queryParameters = [])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($queryParameters)
                ->post($this->client->base_url.$this->interfaces['mirrors'].'/'.$mirror['name'], $mirror);

            return $response;
        }
    }

    public function patch_mirror(array $mirror, array $queryParameters = [])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($queryParameters)
                ->patch($this->client->base_url.$this->interfaces['mirrors'].'/'.$mirror['name'], $mirror);

            return $response;
        }
    }

    public function get_mirror(string $name, array $queryParameters = [])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($queryParameters)
                ->get($this->client->base_url.$this->interfaces['mirrors'].'/'.$name);

            return $response;
        }
    }

    public function get_sites(array $queryParameters = [])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($queryParameters)
                ->get($this->client->base_url.$this->scopeManagement['sites']['sites']);

            return $response;
        }
    }

    public function get_device_groups()
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->get($this->client->base_url.$this->scopeManagement['device_groups']['device_groups']);

            return $response;
        }
    }

    public function get_scopeid_for_device_group(string $device_group)
    {
        $device_groups = $this->get_device_groups();
        if (array_key_exists('items', $device_groups->json())) {
            $found_device_group = collect($device_groups->json()['items'])->firstWhere('scopeName', $device_group);
            if ($found_device_group) {
                return $found_device_group['scopeId'];
            } else {
                return null;
            }
        } else {
            return ['error' => 'failed to retrieve device groups from Central.'];
        }
    }

    public function get_site_collections()
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->get($this->client->base_url.$this->scopeManagement['site_collections']['site_collections']);

            return $response;
        }
    }

    /**
     * Single-page GET for network-monitoring devices.
     *
     * @param  array<string, mixed>  $filter  Query parameters:
     *                                        - limit: page size (0–1000)
     *                                        - next: pagination cursor from a previous response
     *                                        - filter: OData filter (siteId, siteName, model, status, deployment)
     *                                        - sort: sort expression
     * @return \GuzzleHttp\Promise\PromiseInterface|\Illuminate\Http\Client\Promises\LazyPromise|\Illuminate\Http\Client\Response|string[]
     *
     * @throws \Illuminate\Http\Client\ConnectionException
     */
    public function get_devices(array $queryParameters = [])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($queryParameters)
                ->get($this->client->base_url.$this->deviceMonitoring['devices']);

            if (! $response->ok()) {
                return ['error' => 'failed to get devices from central.'];
            }

            return $response;
        }
    }

    /**
     * Page through all devices using cursor pagination (limit + next).
     *
     * @param  array<string, mixed>  $queryParameters  Optional OData filter/sort (limit and next are managed internally)
     * @return array<int, array<string, mixed>>|array{error: string}
     */
    public function get_all_devices(array $queryParameters = []): array
    {
        $allItems = [];
        $requestedLimit = $queryParameters['limit'] ?? null;
        unset($queryParameters['limit']);

        $limit = is_numeric($requestedLimit) ? (int) $requestedLimit : 1000;
        $next = null;

        while (true) {
            $params = array_merge($queryParameters, ['limit' => $limit]);
            if ($next !== null && $next !== '') {
                $params['next'] = $next;
            }

            $response = $this->get_devices($params);

            if (is_array($response)) {
                return ['error' => (string) ($response['error'] ?? 'Failed to fetch devices from Central.')];
            }

            if (! $response->ok()) {
                return ['error' => 'failed to get devices from central.'];
            }

            $pageItems = $response->json('items', []);
            if (! is_array($pageItems)) {
                $pageItems = [];
            }

            if ($pageItems === []) {
                break;
            }

            $allItems = array_merge($allItems, $pageItems);

            $next = $response->json('next');
            if ($next === null || $next === '') {
                break;
            }
        }

        return $allItems;
    }

    public function get_local_management_profiles($queryParameters = ['view-type' => 'LIBRARY'])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($queryParameters)
                ->get($this->client->base_url.$this->system['local_management']);

            return $response;
        }
    }

    public function delete_local_management_profile($profile_name, $queryParameters = ['view-type' => 'LIBRARY'])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($queryParameters)
                ->delete($this->client->base_url.$this->system['local_management'].'/'.$profile_name);

            return $response;
        }
    }

    public function classic_get_sites()
    {
        if (! $this->client->handleClassicBearerToken()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->classic_access_token)
                ->get($this->classicApiUrl($this->classic_monitoring['sites']));

            return $response;
        }
    }

    /**
     * Page through Classic Central sites and merge all entries (deduped by site_id).
     *
     * @return array{sites: array<int, array<string, mixed>>}|array{error: string}
     */
    public function classic_collect_all_sites(): array
    {
        if (! $this->client->handleClassicBearerToken()) {
            return ['error' => 'failed to get access token from central.'];
        }

        $sitesById = [];
        $limit = 100;
        $offset = 0;

        while (true) {
            $response = Http::withToken($this->client->classic_access_token)
                ->withQueryParameters(['limit' => $limit, 'offset' => $offset])
                ->get($this->classicApiUrl($this->classic_monitoring['sites']));

            if (! $response->ok()) {
                return ['error' => 'Could not load sites from Central.'];
            }

            $pageSites = $response->json('sites');
            if (! is_array($pageSites) || $pageSites === []) {
                break;
            }

            foreach ($pageSites as $site) {
                if (! is_array($site)) {
                    continue;
                }
                $siteId = $site['site_id'] ?? null;
                if ($siteId === null) {
                    continue;
                }
                $sitesById[$siteId] = $site;
            }

            $offset += $limit;
        }

        return ['sites' => array_values($sitesById)];
    }

    public function classic_associate_devices_to_site($device_to_site_body)
    {
        if (! $this->client->handleClassicBearerToken()) {
            return ['error' => 'failed to get access token from central.'];
        }
        $response = Http::withToken($this->client->classic_access_token)
            ->post($this->classicApiUrl($this->classic_monitoring['sites'].'/associations'), $device_to_site_body);

        return $response;
    }

    public function classic_associate_device_to_site($device_to_site_body)
    {
        if (! $this->client->handleClassicBearerToken()) {
            return ['error' => 'failed to get access token from central.'];
        }
        $response = Http::withToken($this->client->classic_access_token)
            ->post($this->classicApiUrl($this->classic_monitoring['sites'].'/associate'), $device_to_site_body);

        return $response;
    }

    public function classic_get_switch_details(Device $device)
    {
        if (! $this->client->handleClassicBearerToken()) {
            return ['error' => 'failed to get access token from central.'];
        }
        $response = Http::withToken($this->client->classic_access_token)
            ->get($this->classicApiUrl($this->classic_monitoring['switches'].'/'.$device->serial));

        return $response;
    }

    /**
     * @param  $queryParameters  array<string, mixed> group : string, status: string, limit: int, offset: int
     */
    public function classic_get_aps($queryParameters = [])
    {
        if (! $this->client->handleClassicBearerToken()) {
            return ['error' => 'failed to get access token from central.'];
        }
        $response = Http::withToken($this->client->classic_access_token)
            ->withQueryParameters($queryParameters)
            ->get($this->classicApiUrl($this->classic_monitoring['aps']));

        return $response;
    }

    /**
     * @param  array<string, mixed>  $queryParameters
     * @return array{aps: array<int, array<string, mixed>>}|array{error: string}
     */
    public function classic_collect_all_aps(array $queryParameters = []): array
    {
        return $this->classicCollectPaginatedMonitoringResource(
            'aps',
            fn (array $params) => $this->classic_get_aps($params),
            $queryParameters,
        );
    }

    /**
     * @param  $queryParameters  array<string, mixed> group : string, status: string, limit: int, offset: int
     */
    public function classic_get_switches($queryParameters = [])
    {
        if (! $this->client->handleClassicBearerToken()) {
            return ['error' => 'failed to get access token from central.'];
        }
        $response = Http::withToken($this->client->classic_access_token)
            ->withQueryParameters($queryParameters)
            ->get($this->classicApiUrl($this->classic_monitoring['switches']));

        return $response;
    }

    /**
     * @param  array<string, mixed>  $queryParameters
     * @return array{switches: array<int, array<string, mixed>>}|array{error: string}
     */
    public function classic_collect_all_switches(array $queryParameters = []): array
    {
        return $this->classicCollectPaginatedMonitoringResource(
            'switches',
            fn (array $params) => $this->classic_get_switches($params),
            $queryParameters,
        );
    }

    /**
     * @param  array<string, mixed>  $queryParameters
     * @return array<string, array<int, array<string, mixed>>>|array{error: string}
     */
    private function classicCollectPaginatedMonitoringResource(
        string $jsonKey,
        callable $fetchPage,
        array $queryParameters = [],
    ): array {
        $allItems = [];
        $limit = (int) ($queryParameters['limit'] ?? 999);
        if ($limit <= 0) {
            $limit = 999;
        }
        $offset = (int) ($queryParameters['offset'] ?? 0);
        $baseParams = $queryParameters;
        unset($baseParams['limit'], $baseParams['offset']);

        while (true) {
            $response = $fetchPage(array_merge($baseParams, ['limit' => $limit, 'offset' => $offset]));
            if (is_array($response)) {
                return ['error' => (string) ($response['error'] ?? 'Failed to fetch '.$jsonKey.' from Central.')];
            }

            if (! $response->ok()) {
                return ['error' => 'failed to get '.$jsonKey.' from central.'];
            }

            $pageItems = $response->json($jsonKey, []);
            if (! is_array($pageItems)) {
                $pageItems = [];
            }

            if ($pageItems === []) {
                break;
            }

            $allItems = array_merge($allItems, $pageItems);

            $total = $response->json('total');
            if (is_numeric($total) && count($allItems) >= (int) $total) {
                break;
            }

            if (count($pageItems) < $limit) {
                break;
            }

            $offset += $limit;
        }

        return [$jsonKey => $allItems];
    }

    public function classic_get_groups()
    {
        if (! $this->client->handleClassicBearerToken()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->classic_access_token)
                ->withQueryParameters(['limit' => 20, 'offset' => 0])
                ->get($this->classicApiUrl($this->classic_configuration['groups']));

            return $response;
        }
    }

    /**
     * @return \Illuminate\Http\Client\Response|array{error: string}
     */
    public function classic_get_subscriptions()
    {
        if (! $this->client->handleClassicBearerToken()) {
            return ['error' => 'failed to get access token from central.'];
        }

        return Http::withToken($this->client->classic_access_token)
            ->get($this->classicApiUrl($this->classic_subscription['subscriptions']));
    }

    /**
     * @return array{subscriptions: array<int, array<string, mixed>>}|array{error: string}
     */
    public function classic_parse_subscriptions(mixed $response): array
    {
        if (is_array($response) && isset($response['error'])) {
            return ['error' => (string) $response['error']];
        }

        if (! $response instanceof \Illuminate\Http\Client\Response || ! $response->ok()) {
            return ['error' => 'failed to get subscriptions from central.'];
        }

        $subscriptions = $response->json('subscriptions', []);
        if (! is_array($subscriptions)) {
            $subscriptions = [];
        }

        return ['subscriptions' => $subscriptions];
    }

    /**
     * @return \Illuminate\Http\Client\Response|array{error: string}
     */
    public function classic_get_enabled_services()
    {
        if (! $this->client->handleClassicBearerToken()) {
            return ['error' => 'failed to get access token from central.'];
        }

        return Http::withToken($this->client->classic_access_token)
            ->get($this->classicApiUrl($this->classic_subscription['enabled_services']));
    }

    /**
     * @return array{services: array<int, string>}|array{error: string}
     */
    public function classic_parse_enabled_services(mixed $response): array
    {
        if (is_array($response) && isset($response['error'])) {
            return ['error' => (string) $response['error']];
        }

        if (! $response instanceof \Illuminate\Http\Client\Response || ! $response->ok()) {
            return ['error' => 'failed to get enabled services from central.'];
        }

        $services = $response->json('services.services', []);
        if (! is_array($services)) {
            $services = [];
        }

        return ['services' => array_values(array_filter($services, fn ($service) => is_string($service) && $service !== ''))];
    }

    /**
     * @param  array<string, mixed>  $queryParameters  Query parameters: limit (default 1000 in collect), offset, sku_type (default all)
     * @return \Illuminate\Http\Client\Response|array{error: string}
     */
    public function classic_get_device_inventory(array $queryParameters = [])
    {
        if (! $this->client->handleClassicBearerToken()) {
            return ['error' => 'failed to get access token from central.'];
        }

        $params = array_merge(['sku_type' => 'all'], $queryParameters);

        return Http::withToken($this->client->classic_access_token)
            ->withQueryParameters($params)
            ->get($this->classicApiUrl($this->classic_subscription['device_inventory']));
    }

    /**
     * @return array<int, array<string, mixed>>|array{error: string}
     */
    public function classic_collect_device_inventory(): array
    {
        $allDevices = [];
        $limit = 1000;
        $offset = 0;

        while (true) {
            $response = $this->classic_get_device_inventory([
                'limit' => $limit,
                'offset' => $offset,
            ]);

            if (is_array($response)) {
                return ['error' => (string) ($response['error'] ?? 'Failed to fetch device inventory from Central.')];
            }

            if (! $response->ok()) {
                return ['error' => 'failed to get device inventory from central.'];
            }

            $pageDevices = $response->json('devices', []);
            if (! is_array($pageDevices)) {
                $pageDevices = [];
            }

            if ($pageDevices === []) {
                break;
            }

            $allDevices = array_merge($allDevices, $pageDevices);

            $total = $response->json('total');
            if (is_numeric($total) && count($allDevices) >= (int) $total) {
                break;
            }

            if (count($pageDevices) < $limit) {
                break;
            }

            $offset += $limit;
        }

        return $allDevices;
    }

    /**
     * @return array<string, string> serial => non-empty display name from Central
     */
    public function indexCentralDeviceNamesBySerial(): array
    {
        $namesBySerial = [];

        $classicDevices = $this->classic_collect_device_inventory();
        if (! isset($classicDevices['error'])) {
            foreach ($classicDevices as $device) {
                if (! is_array($device)) {
                    continue;
                }

                $serial = trim((string) ($device['serial'] ?? ''));
                $name = trim((string) ($device['name'] ?? ''));
                if ($serial !== '' && $name !== '') {
                    $namesBySerial[$serial] = $name;
                }
            }
        }

        $newCentralDevices = $this->get_all_devices();
        if (! isset($newCentralDevices['error'])) {
            foreach ($newCentralDevices as $device) {
                if (! is_array($device)) {
                    continue;
                }

                $serial = trim((string) ($device['serialNumber'] ?? ''));
                $name = trim((string) ($device['deviceName'] ?? ''));
                if ($serial !== '' && $name !== '') {
                    $namesBySerial[$serial] = $name;
                }
            }
        }

        return $namesBySerial;
    }

    /**
     * @param  string  $service_name  can be retrieved using the classic_get_enabled_services() method. For devices, most likely advanced_ap, advanced_switch_6100, advanced_switch_8300_foundation_ap, etc. will be used.
     * @return \Illuminate\Http\Client\Response|array{error: string}
     */
    public function classic_unassign_subscription(array $serials, string $service_name)
    {
        if (! $this->client->handleClassicBearerToken()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->classic_access_token)
                ->post($this->classicApiUrl($this->classic_subscription['unassign_subscription']), ['serials' => $serials, 'service_name' => [$service_name]]);

            return $response;
        }
    }

    public function classic_assign_subscription(array $serials, string $service_name)
    {
        if (! $this->client->handleClassicBearerToken()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->classic_access_token)
                ->post($this->classicApiUrl($this->classic_subscription['assign_subscription']), ['serials' => $serials, 'service_name' => [$service_name]]);

            return $response;
        }

    }

    public function classic_get_subscription_status()
    {
        if (! $this->client->handleClassicBearerToken()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->classic_access_token)
                ->get($this->classicApiUrl($this->classic_subscription['subscription_status']));

            return $response;
        }
    }

    public function classic_make_new_central_group(string $group_name, bool $allow_switches = true, bool $allow_aps = false)
    {
        if (! $this->client->handleClassicBearerToken()) {
            return ['error' => 'failed to get access token from central.'];
        }
        $allowedDevTypes = [];
        $properties = ['MonitorOnly' => [], 'NewCentral' => true];
        $template_info = [];
        if ($allow_switches) {
            $allowedDevTypes[] = 'Switches';
            $properties['AllowedSwitchTypes'] = ['AOS_CX'];
            $template_info['Wired'] = false;
        }
        if ($allow_aps) {
            $allowedDevTypes[] = 'AccessPoints';
            $properties['Architecture'] = 'AOS10';
            $properties['ApNetworkRole'] = 'Standard';
            $template_info['Wireless'] = false;
        }
        $properties['AllowedDevTypes'] = $allowedDevTypes;
        $body = [
            'group' => $group_name,
            'group_attributes' => [
                'template_info' => $template_info,
                'group_properties' => $properties,
            ],
        ];

        $response = Http::withToken($this->client->classic_access_token)
            ->withQueryParameters(['group' => $group_name])
            ->post($this->classicApiUrl($this->classic_configuration['groupsv3']), $body);

        return $response;
    }

    /**
     * Page through Classic Central configuration groups and collect all group name strings.
     *
     * @return array{names: array<int, string>}|array{error: string}
     */
    public function classic_collect_all_group_names(): array
    {
        if (! $this->client->handleClassicBearerToken()) {
            return ['error' => 'failed to get access token from central.'];
        }

        $names = [];
        $limit = 100;
        $offset = 0;

        while (true) {
            $response = Http::withToken($this->client->classic_access_token)
                ->withQueryParameters(['limit' => $limit, 'offset' => $offset])
                ->get($this->classicApiUrl($this->classic_configuration['groups']));

            if (! $response->ok()) {
                return ['error' => 'Could not load groups from Central.'];
            }

            $data = $response->json('data');
            if (! is_array($data) || $data === []) {
                break;
            }

            $pageNames = collect($data)->collapse()->filter(fn ($item) => is_string($item) && $item !== '')->values();
            if ($pageNames->isEmpty()) {
                break;
            }

            $countBefore = count($names);
            foreach ($pageNames as $name) {
                $names[$name] = true;
            }

            if (count($names) === $countBefore) {
                break;
            }

            $offset += $limit;
        }

        return ['names' => array_keys($names)];
    }

    public function move_devices_to_group(string $group, array $device_serials)
    {
        if (! $this->client->handleClassicBearerToken()) {
            return ['error' => 'failed to get access token from central.'];
        }
        $response = Http::withToken($this->client->classic_access_token)
            ->post($this->classicApiUrl($this->classic_configuration['move_devices_to_group']), ['group' => $group, 'serials' => $device_serials]);

        return $response;
    }

    /**
     * @param array<string, mixed> $queryParameters [ 'limit' => int, 'offset' => int, 'device_type' => 'CX' | IAP | MAS | HP | CONTROLLER, 'serial' => string ]
     * @return [ [ 'create_date' => datetime, 'firmware_version' => string, 'release_status' => string ], ...]
     */
    public function classic_get_firmware_versions($queryParameters = [])
    {
        if (! $this->client->handleClassicBearerToken()) {
            return ['error' => 'failed to get access token from central.'];
        }
        $response = Http::withToken($this->client->classic_access_token)
            ->withQueryParameters($queryParameters)
            ->get($this->classicApiUrl($this->classic_firmware['firmware_versions']));

        return $response;
    }

    /**
     * @return array{versions: array<int, string>, error: string|null}
     */
    public function resolveCxFirmwareVersionOptions(): array
    {
        try {
            $response = $this->classic_get_firmware_versions(['device_type' => 'CX']);
            if (is_array($response) && array_key_exists('error', $response)) {
                return ['versions' => [], 'error' => (string) $response['error']];
            }

            if (! $response->ok()) {
                return ['versions' => [], 'error' => 'Could not load CX firmware versions from Central.'];
            }

            $payload = $response->json();
            $items = is_array($payload) && array_is_list($payload)
                ? $payload
                : (is_array($payload['firmware'] ?? null) ? $payload['firmware'] : []);

            $versions = collect($items)
                ->map(fn ($item) => is_array($item) ? trim((string) ($item['firmware_version'] ?? '')) : '')
                ->filter(fn (string $version) => $version !== '')
                ->unique()
                ->sort()
                ->values()
                ->all();

            return ['versions' => $versions, 'error' => null];
        } catch (RequestException|ConnectionException) {
            return ['versions' => [], 'error' => 'Could not load CX firmware versions from Central.'];
        }
    }

    /**
     * @param array<string, mixed> $body [ 'device_type' => 'CX' | IAP | MAS | HP | CONTROLLER, 'group' => string, 'firmware_compliance_version' => string, 'reboot' => boolean, 'allow_unsupported_version' => boolean (optional)]
     */
    public function classic_post_firmware_compliance(array $body)
    {
        if (! $this->client->handleClassicBearerToken()) {
            return ['error' => 'failed to get access token from central.'];
        }
        $response = Http::withToken($this->client->classic_access_token)
            ->post($this->classicApiUrl($this->classic_firmware['firmware_compliance']), $body);

        return $response;
    }

    /**
     * @param array<string, mixed> $queryParameters [ 'device_type' => 'CX' | IAP | MAS | HP | CONTROLLER, 'group' => string ]
     * @return array [ 'firmware_compliance_version' => string]
     */
    public function classic_get_firmware_compliance($queryParameters = [])
    {
        if (! $this->client->handleClassicBearerToken()) {
            return ['error' => 'failed to get access token from central.'];
        }
        $response = Http::withToken($this->client->classic_access_token)
            ->get($this->classicApiUrl($this->classic_firmware['firmware_compliance']), $queryParameters);

        return $response;
    }

    /**
     * @return array{ok: true}|array{error: string}
     */
    public function ensureVsxKeepAliveVrf(Device $device): array
    {
        if (! filled($device->group)) {
            return ['error' => 'Device '.$device->name.' has no group for VRF lookup.'];
        }

        $groupScopeId = $this->resolveGroupScopeId($device);
        if ($groupScopeId === null) {
            return ['error' => 'Could not resolve group scope ID for device '.$device->name.'.'];
        }

        $queryParams = static::localVrfQueryParameters($device, $groupScopeId);
        $response = $this->get_vrfs($queryParams);

        if (is_array($response) && array_key_exists('error', $response)) {
            return ['error' => (string) $response['error']];
        }

        if (! $response instanceof Response || ! $response->ok()) {
            $message = $response instanceof Response
                ? (string) ($response->json('message') ?? $response->body())
                : 'Failed to fetch VRFs from Central.';

            return ['error' => $message !== '' ? $message : 'Failed to fetch VRFs from Central.'];
        }

        $vrfs = $response->json('vrf', []);
        if (! is_array($vrfs)) {
            $vrfs = [];
        }

        if (static::vrfNameExists($vrfs, self::VSX_KEEPALIVE_VRF)) {
            return ['ok' => true];
        }

        $postResponse = $this->post_vrf(['name' => self::VSX_KEEPALIVE_VRF], $queryParams);

        if (is_array($postResponse) && array_key_exists('error', $postResponse)) {
            return ['error' => (string) $postResponse['error']];
        }

        if (! $postResponse instanceof Response || ! $postResponse->ok()) {
            $message = $postResponse instanceof Response
                ? (string) ($postResponse->json('message') ?? $postResponse->body())
                : 'Failed to create VRF in Central.';

            return ['error' => self::VSX_KEEPALIVE_VRF.' VRF creation failed at group level for '.$device->name.': '.($message !== '' ? $message : 'unknown error')];
        }

        return ['ok' => true];
    }

    /**
     * @return array<int, string>|array{error: string}
     */
    public function getSortedEthernetInterfaceNames(Device $device): array
    {
        $response = $this->get_ethernet_interfaces($device);

        if ($response instanceof Response && ! $response->ok()) {
            $message = (string) ($response->json('message') ?? $response->body());

            return ['error' => $message !== '' ? $message : 'Failed to fetch ethernet interfaces from Central.'];
        }

        if (is_array($response) && array_key_exists('error', $response)) {
            return ['error' => (string) $response['error']];
        }

        $items = $response instanceof Response ? $response->json('interface', []) : [];
        if (! is_array($items)) {
            $items = [];
        }

        $names = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = (string) ($item['name'] ?? '');
            if ($name !== '') {
                $names[] = $name;
            }
        }

        sort($names, SORT_NATURAL);

        return $names;
    }

    public static function deviceHasVsxAttributes(Device $device): bool
    {
        return filled($device->vsx_profile)
            || filled($device->vsx_role)
            || filled($device->vsx_system_mac)
            || filled($device->vsx_isl_ports)
            || filled($device->vsx_keepalive_ports);
    }

    /**
     * @param  Collection<int, Device>  $selectedDevices
     */
    public static function deploymentUsesVsxFallbackMode(Collection $selectedDevices): bool
    {
        return ! $selectedDevices->contains(fn (Device $device) => self::deviceHasVsxAttributes($device));
    }

    public static function deviceMatchesVsxNamePattern(Device $device): bool
    {
        $name = strtoupper((string) $device->name);

        return str_contains($name, 'CORE-SW1')
            || str_contains($name, 'CORE-SW2')
            || str_contains($name, 'SVR-SW1')
            || str_contains($name, 'SVR-SW2');
    }

    public static function inferVsxRoleFromName(Device $device): ?VsxRole
    {
        $name = strtoupper((string) $device->name);

        if (str_contains($name, 'CORE-SW1') || str_contains($name, 'SVR-SW1')) {
            return VsxRole::VSX_PRIMARY;
        }

        if (str_contains($name, 'CORE-SW2') || str_contains($name, 'SVR-SW2')) {
            return VsxRole::VSX_SECONDARY;
        }

        return null;
    }

    public static function inferVsxProfileNameFromDevice(Device $device): ?string
    {
        $name = (string) $device->name;
        $prefix = explode('-', $name, 2)[0] ?? '';
        if ($prefix === '') {
            return null;
        }

        $upper = strtoupper($name);
        if (str_contains($upper, 'CORE-SW1') || str_contains($upper, 'CORE-SW2')) {
            return $prefix.'-MDF-CORE-VSX-PROFILE';
        }

        if (str_contains($upper, 'SVR-SW1') || str_contains($upper, 'SVR-SW2')) {
            return $prefix.'-MDF-SVR-VSX-PROFILE';
        }

        return null;
    }

    public static function inferVsxSystemMacFromDevice(Device $device): ?string
    {
        $name = strtoupper((string) $device->name);

        if (str_contains($name, 'CORE-SW1') || str_contains($name, 'CORE-SW2')) {
            return '02:00:00:00:00:01';
        }

        if (str_contains($name, 'SVR-SW1') || str_contains($name, 'SVR-SW2')) {
            return '02:00:00:00:00:02';
        }

        return null;
    }

    public static function applyVsxFallbackAttributes(Device $device): bool
    {
        if (! self::deviceMatchesVsxNamePattern($device)) {
            return false;
        }

        $profile = self::inferVsxProfileNameFromDevice($device);
        $role = self::inferVsxRoleFromName($device);
        $mac = self::inferVsxSystemMacFromDevice($device);

        if ($profile === null || $role === null || $mac === null) {
            return false;
        }

        $device->vsx_profile = $profile;
        $device->vsx_role = $role->name;
        $device->vsx_system_mac = $mac;

        return true;
    }

    public static function resolveVsxProfileName(Device $device, bool $fallbackMode): ?string
    {
        if (filled($device->vsx_profile)) {
            return (string) $device->vsx_profile;
        }

        if ($fallbackMode) {
            return self::inferVsxProfileNameFromDevice($device);
        }

        return null;
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, string>}|array{error: string}
     */
    public static function getVsxPortSelections(Device $device): array
    {
        $hasIslOverride = filled($device->vsx_isl_ports);
        $hasKeepaliveOverride = filled($device->vsx_keepalive_ports);

        if ($hasIslOverride xor $hasKeepaliveOverride) {
            return ['error' => 'Both vsx_isl_ports and vsx_keepalive_ports must be set when overriding VSX LAG member ports on '.$device->name.'.'];
        }

        if ($hasIslOverride && $hasKeepaliveOverride) {
            $islPorts = InterfaceHelper::expandInterfaceRange((string) $device->vsx_isl_ports);
            $keepalivePorts = InterfaceHelper::expandInterfaceRange((string) $device->vsx_keepalive_ports);

            if (count($islPorts) !== 2) {
                return ['error' => 'vsx_isl_ports on '.$device->name.' must expand to exactly 2 interfaces.'];
            }

            if (count($keepalivePorts) !== 2) {
                return ['error' => 'vsx_keepalive_ports on '.$device->name.' must expand to exactly 2 interfaces.'];
            }

            return [$islPorts, $keepalivePorts];
        }

        $name = strtoupper((string) $device->name);
        if (str_contains($name, 'CORE')) {
            return [['1/1/53', '1/1/54'], ['1/1/47', '1/1/48']];
        }

        if (str_contains($name, 'SVR')) {
            return [['1/1/21', '1/1/22'], ['1/1/23', '1/1/24']];
        }

        return ['error' => 'Cannot determine VSX LAG ports for '.$device->name.': device name must contain CORE or SVR, or set vsx_isl_ports and vsx_keepalive_ports.'];
    }

    public static function buildVsxKeepaliveLagPayload(array $portList, VsxRole $role): array
    {
        $address = $role === VsxRole::VSX_PRIMARY ? '1.1.1.1/30' : '1.1.1.2/30';

        return [
            'name' => '255',
            'routing' => true,
            'vrf-forwarding' => self::VSX_KEEPALIVE_VRF,
            'ipv4' => [
                'address' => $address,
            ],
            'trunk-type' => 'LACP',
            'lacp' => ['mode' => 'ACTIVE'],
            'port-list' => $portList,
            'enable' => true,
        ];
    }

    /**
     * @param  array<int, string>  $portList
     * @return array<string, mixed>
     */
    public static function buildVsxIslLagPayload(array $portList): array
    {
        return [
            'name' => '256',
            'switchport' => [
                'interface-mode' => 'TRUNK',
                'native-vlan' => 1,
                'trunk-vlan-all' => true,
            ],
            'trunk-type' => 'LACP',
            'lacp' => ['mode' => 'ACTIVE'],
            'port-list' => $portList,
            'enable' => true,
        ];
    }

    public static function buildVsxLagMemberPortDescription(string $peerDeviceName, string $interfaceName, string $labelSuffix): string
    {
        return $peerDeviceName.' - '.$interfaceName.' '.$labelSuffix;
    }

    /**
     * @return array<int, array{path: string, expected: mixed, actual: mixed}>
     */
    public static function isVsxPortchannelConfiguredOnDevice(array $actual): bool
    {
        return ($actual['name'] ?? null) !== null;
    }

    public static function vsxPortchannelMatchesExpected(array $expected, array $actual): array
    {
        $diffs = [];
        static::compareVsxPortchannelNodes($expected, static::normalizeVsxPortchannelActual($actual), '', $diffs);

        return $diffs;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function normalizeVsxPortchannelActual(array $actual): array
    {
        $normalized = $actual;

        if (! array_key_exists('vrf-forwarding', $normalized) && is_array($actual['ipv4'] ?? null)) {
            $vrf = $actual['ipv4']['vrf-forwarding'] ?? null;
            if ($vrf !== null) {
                $normalized['vrf-forwarding'] = $vrf;
            }
        }

        if (isset($normalized['ipv4']) && is_array($normalized['ipv4'])) {
            $normalized['ipv4'] = array_filter([
                'address' => $normalized['ipv4']['address'] ?? null,
            ], fn ($value) => $value !== null);
        }

        if (isset($normalized['lacp']) && is_array($normalized['lacp'])) {
            $normalized['lacp'] = array_filter([
                'mode' => $normalized['lacp']['mode'] ?? null,
            ], fn ($value) => $value !== null);
        }

        return $normalized;
    }

    /**
     * @param  list<array{path: string, expected: mixed, actual: mixed}>  $diffs
     */
    protected static function compareVsxPortchannelNodes(array $expected, array $actual, string $prefix, array &$diffs): void
    {
        foreach ($expected as $key => $expectedValue) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $actualValue = $actual[$key] ?? null;

            if (is_array($expectedValue) && static::isVsxAssociativeArray($expectedValue)) {
                $actualNested = is_array($actualValue) ? $actualValue : [];
                static::compareVsxPortchannelNodes($expectedValue, $actualNested, $path, $diffs);

                continue;
            }

            if ($key === 'port-list') {
                if (InterfaceHelper::normalizePortListMembers($expectedValue)
                    !== InterfaceHelper::normalizePortListMembers($actualValue)) {
                    $diffs[] = [
                        'path' => $path,
                        'expected' => $expectedValue,
                        'actual' => $actualValue,
                    ];
                }

                continue;
            }

            if (! static::vsxPortchannelValuesMatch($expectedValue, $actualValue)) {
                $diffs[] = [
                    'path' => $path,
                    'expected' => $expectedValue,
                    'actual' => $actualValue,
                ];
            }
        }
    }

    protected static function isVsxAssociativeArray(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }

    protected static function vsxPortchannelValuesMatch(mixed $expected, mixed $actual): bool
    {
        $normalizedExpected = static::normalizeVsxCompareValue($expected);
        $normalizedActual = static::normalizeVsxCompareValue($actual);

        if (is_array($normalizedExpected) && is_array($normalizedActual)) {
            if (static::isVsxListArray($normalizedExpected) && static::isVsxListArray($normalizedActual)) {
                $a = $normalizedExpected;
                $b = $normalizedActual;
                sort($a);
                sort($b);

                return $a === $b;
            }
        }

        return $normalizedExpected === $normalizedActual;
    }

    protected static function isVsxListArray(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }

    protected static function normalizeVsxCompareValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return is_float($value + 0) && floor($value + 0) != ($value + 0)
                ? (float) $value
                : (int) $value;
        }

        if (is_string($value)) {
            $lower = strtolower($value);
            if ($lower === 'true') {
                return true;
            }
            if ($lower === 'false') {
                return false;
            }

            return $value;
        }

        if (is_array($value)) {
            if (static::isVsxListArray($value)) {
                return array_map(fn ($item) => static::normalizeVsxCompareValue($item), $value);
            }

            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = static::normalizeVsxCompareValue($item);
            }

            return $normalized;
        }

        return $value;
    }

    /**
     * @param  array<int, array{path: string, expected: mixed, actual: mixed}>  $diffs
     */
    public static function formatVsxPortchannelDiffSummary(array $diffs): string
    {
        if ($diffs === []) {
            return '';
        }

        return collect($diffs)
            ->map(fn (array $diff) => $diff['path'].': expected '.json_encode($diff['expected']).', got '.json_encode($diff['actual']))
            ->implode('; ');
    }

    public static function deviceVsxRole(Device $device): ?VsxRole
    {
        $role = $device->vsx_role;
        if ($role === null || $role === '') {
            return null;
        }

        foreach (VsxRole::cases() as $case) {
            if ($case->name === $role) {
                return $case;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildVsxPeerPayload(Device $device, Device $peerDevice): array
    {
        $role = static::deviceVsxRole($device);
        $isPrimary = $role === VsxRole::VSX_PRIMARY;
        $keepaliveAddress = $isPrimary ? '1.1.1.1/30' : '1.1.1.2/30';
        $sourceIp = $isPrimary ? '1.1.1.1' : '1.1.1.2';
        $peerIp = $isPrimary ? '1.1.1.2' : '1.1.1.1';

        return [
            'role' => (string) $device->vsx_role,
            'device-serial' => $device->serial,
            'inter-switch-link' => [
                'portchannel-interface' => '256',
            ],
            'port-channel-interface' => [[
                'ipv4-address' => $keepaliveAddress,
                'existing-portchannel-ip' => true,
                'portchannel-ifname' => '255',
                'vrf-forwarding' => self::VSX_KEEPALIVE_VRF,
                'routing' => true,
                'trunk-type' => 'LACP',
                'lacp-mode' => 'ACTIVE',
            ]],
            'vrf' => [[
                'vrf-name' => self::VSX_KEEPALIVE_VRF,
                'existing-vrf' => true,
            ]],
            'keepalive-device' => [
                'source-ip' => $sourceIp,
                'peer-ip' => $peerIp,
                'portchannel-ifname' => '255',
                'keepalive-type' => 'KA_PORTCHANNEL',
                'vrf' => self::VSX_KEEPALIVE_VRF,
                'keepalive-version' => 'IPV4',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildVsxProfilePayload(Device $primary, Device $secondary): array
    {
        return [
            'auto-role' => false,
            'name' => (string) $primary->vsx_profile,
            'sync-features' => [
                'system-mac' => (string) $primary->vsx_system_mac,
            ],
            'peer1' => static::buildVsxPeerPayload($primary, $secondary),
            'peer2' => static::buildVsxPeerPayload($secondary, $primary),
        ];
    }

    /**
     * @return array{ok: true}|array{error: string}
     */
    public function ensureVsxIslLag(Device $device, Device $peerDevice, array $portList): array
    {
        $payload = static::buildVsxIslLagPayload($portList);

        return $this->ensureVsxPortchannelLag(
            $device,
            $peerDevice,
            '256',
            $payload,
            $portList,
            'LAG 256 inter-switch-link',
            '[VSX-Peer-Link]'
        );
    }

    /**
     * @return array{ok: true}|array{error: string}
     */
    public function ensureVsxKeepaliveLag(Device $device, Device $peerDevice, VsxRole $role, array $portList): array
    {
        $payload = static::buildVsxKeepaliveLagPayload($portList, $role);

        return $this->ensureVsxPortchannelLag(
            $device,
            $peerDevice,
            '255',
            $payload,
            $portList,
            'LAG 255 keepalive',
            '[VSX Keep-Alive]'
        );
    }

    /**
     * @param  array<int, string>  $memberPortNames
     * @return array{ok: true}|array{error: string}
     */
    public function ensureVsxPortchannelLag(
        Device $device,
        Device $peerDevice,
        string $lagName,
        array $expectedPayload,
        array $memberPortNames,
        string $failureLabel,
        string $portDescriptionSuffix
    ): array {
        $getResponse = $this->get_interface_portchannel($device, $lagName);

        if (is_array($getResponse) && array_key_exists('error', $getResponse)) {
            return ['error' => (string) $getResponse['error']];
        }

        $shouldCreateLag = false;

        if ($getResponse instanceof Response && $getResponse->ok()) {
            $actual = $getResponse->json();
            if (! is_array($actual)) {
                $actual = [];
            }

            if (static::isVsxPortchannelConfiguredOnDevice($actual)) {
                $diffs = static::vsxPortchannelMatchesExpected($expectedPayload, $actual);
                if ($diffs !== []) {
                    return ['error' => $failureLabel.' on '.$device->name.' does not match expected configuration: '.static::formatVsxPortchannelDiffSummary($diffs)];
                }
            } else {
                $shouldCreateLag = true;
            }
        } elseif ($getResponse instanceof Response && $getResponse->status() === 404) {
            $shouldCreateLag = true;
        } else {
            return ['error' => $failureLabel.' lookup failed on '.$device->name.': '.$this->centralResponseErrorMessage($getResponse)];
        }

        if ($shouldCreateLag) {
            $postResponse = $this->post_raw_interface_portchannel($device, $lagName, $expectedPayload);
            if (! $this->isSuccessfulCentralResponse($postResponse)) {
                $patchResponse = $this->patch_raw_interface_portchannel($device, $lagName, $expectedPayload);
                if (! $this->isSuccessfulCentralResponse($patchResponse)) {
                    return ['error' => $failureLabel.' creation failed on '.$device->name.': '.$this->centralResponseErrorMessage($patchResponse)];
                }
            }
        }

        return $this->ensureVsxLagMemberPortDescriptions($device, $peerDevice, $memberPortNames, $portDescriptionSuffix, $failureLabel);
    }

    /**
     * @param  array<int, string>  $portNames
     * @return array{ok: true}|array{error: string}
     */
    public function ensureVsxLagMemberPortDescriptions(
        Device $device,
        Device $peerDevice,
        array $portNames,
        string $labelSuffix,
        string $failureLabel
    ): array {
        foreach ($portNames as $portName) {
            $expectedDescription = static::buildVsxLagMemberPortDescription($peerDevice->name, $portName, $labelSuffix);
            $getResponse = $this->get_ethernet_interface_by_name($device, $portName);

            if (is_array($getResponse) && array_key_exists('error', $getResponse)) {
                return ['error' => (string) $getResponse['error']];
            }

            if (! $getResponse instanceof Response || ! $getResponse->ok()) {
                return ['error' => $failureLabel.' member port description failed on '.$device->name.' '.$portName.': '.$this->centralResponseErrorMessage($getResponse)];
            }

            $actualDescription = (string) ($getResponse->json('description') ?? '');
            if ($actualDescription === $expectedDescription) {
                continue;
            }

            $patchResponse = $this->patch_ethernet_interface_by_name($device, $portName, [
                'name' => $portName,
                'description' => $expectedDescription,
            ]);

            if (! $this->isSuccessfulCentralResponse($patchResponse)) {
                return ['error' => $failureLabel.' member port description failed on '.$device->name.' '.$portName.': '.$this->centralResponseErrorMessage($patchResponse)];
            }
        }

        return ['ok' => true];
    }

    public function get_interface_portchannel(Device $device, string $interfaceName): Response|array
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        }

        return Http::withToken($this->client->bearer_token)
            ->withQueryParameters(static::localDeviceInterfaceQueryParameters($device))
            ->get($this->client->base_url.$this->interfaces['interface_portchannel'].'/'.$interfaceName);
    }

    public function post_raw_interface_portchannel(Device $device, string $interfaceName, array $payload): Response|array
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        }

        return Http::withToken($this->client->bearer_token)
            ->withQueryParameters(static::localDeviceInterfaceQueryParameters($device))
            ->post($this->client->base_url.$this->interfaces['interface_portchannel'].'/'.$interfaceName, $payload);
    }

    public function patch_raw_interface_portchannel(Device $device, string $interfaceName, array $payload): Response|array
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        }

        return Http::withToken($this->client->bearer_token)
            ->withQueryParameters(static::localDeviceInterfaceQueryParameters($device))
            ->patch($this->client->base_url.$this->interfaces['interface_portchannel'].'/'.$interfaceName, $payload);
    }

    public function get_ethernet_interface_by_name(Device $device, string $interfaceName): Response|array
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        }

        return Http::withToken($this->client->bearer_token)
            ->withQueryParameters(static::localDeviceInterfaceQueryParameters($device))
            ->get($this->client->base_url.$this->interfaces['interface_ethernet'].'/'.$interfaceName);
    }

    public function patch_ethernet_interface_by_name(Device $device, string $interfaceName, array $patchBody): Response|array
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        }

        return Http::withToken($this->client->bearer_token)
            ->withQueryParameters(static::localDeviceInterfaceQueryParameters($device))
            ->withBody(json_encode($patchBody))
            ->patch($this->client->base_url.$this->interfaces['interface_ethernet'].'/'.$interfaceName);
    }

    protected function isSuccessfulCentralResponse(mixed $response): bool
    {
        return $response instanceof Response && $response->ok();
    }

    protected function centralResponseErrorMessage(mixed $response): string
    {
        if ($response instanceof Response) {
            $message = (string) ($response->json('message') ?? $response->body());

            return $message !== '' ? $message : 'Central API request failed.';
        }

        if (is_array($response) && array_key_exists('error', $response)) {
            return (string) $response['error'];
        }

        return 'Central API request failed.';
    }

    public function preprovision_devices_to_group(string $group, array $device_serials)
    {
        if (! $this->client->handleClassicBearerToken()) {
            return ['error' => 'failed to get access token from central.'];
        }
        $response = Http::withToken($this->client->classic_access_token)
            ->post($this->classicApiUrl($this->classic_configuration['preprovision_devices_to_group']), ['group_name' => $group, 'device_id' => $device_serials]);

        return $response;
    }

    public static function deviceHasMirrorAttributes(Device $device): bool
    {
        return filled($device->mirror_session_id)
            || filled($device->mirror_dst_ports)
            || filled($device->mirror_vlans)
            || filled($device->mirror_name);
    }

    /**
     * @param  Collection<int, Device>  $selectedDevices
     */
    public static function deploymentUsesMirrorFallbackMode(Collection $selectedDevices): bool
    {
        return ! $selectedDevices->contains(fn (Device $device) => self::deviceHasMirrorAttributes($device));
    }

    public static function deviceMatchesMirrorSessionNamePattern(Device $device): bool
    {
        $name = strtoupper((string) $device->name);

        if (str_contains($name, 'FZN-MDF-MGMT')) {
            return true;
        }

        if (str_contains($name, 'MDF-MGMT')) {
            return true;
        }

        return str_contains($name, 'CORE');
    }

    public static function defaultMirrorName(Device $device): string
    {
        return $device->name.'-DARKTRACE-SPAN';
    }

    /**
     * @return array{name: string, session_id: int, dst_ports: list<string>, vlan_ids: list<int>}|array{error: string}
     */
    public function resolveMirrorSettings(Device $device, bool $fallbackMode): array
    {
        if ($fallbackMode) {
            if (! self::deviceMatchesMirrorSessionNamePattern($device)) {
                return ['error' => 'Device '.$device->name.' does not match a mirror session name pattern.'];
            }

            $dstPorts = self::resolveMirrorDestinationPortsFromNamePattern($device);
            if (array_key_exists('error', $dstPorts)) {
                return $dstPorts;
            }

            $vlanResult = $this->fetchMirrorVlanIdsForDevice($device);
            if (array_key_exists('error', $vlanResult)) {
                return $vlanResult;
            }

            return [
                'name' => self::defaultMirrorName($device),
                'session_id' => 1,
                'dst_ports' => $dstPorts,
                'vlan_ids' => $vlanResult['vlan_ids'],
            ];
        }

        if (! filled($device->mirror_dst_ports)) {
            return ['error' => 'mirror_dst_ports is required for device '.$device->name.' in explicit mirror mode.'];
        }

        $dstPorts = InterfaceHelper::expandInterfaceRange((string) $device->mirror_dst_ports);
        if ($dstPorts === []) {
            return ['error' => 'mirror_dst_ports on '.$device->name.' must expand to at least one interface.'];
        }

        $sessionId = filled($device->mirror_session_id) ? (int) $device->mirror_session_id : 1;
        $mirrorName = filled($device->mirror_name) ? (string) $device->mirror_name : self::defaultMirrorName($device);

        if (filled($device->mirror_vlans)) {
            $vlanIds = TrunkVlanRanges::expandToVlanIds((string) $device->mirror_vlans, 'mirror_vlans');
            if ($vlanIds === []) {
                return ['error' => 'mirror_vlans on '.$device->name.' must expand to at least one VLAN.'];
            }
        } else {
            $vlanResult = $this->fetchMirrorVlanIdsForDevice($device);
            if (array_key_exists('error', $vlanResult)) {
                return $vlanResult;
            }
            $vlanIds = $vlanResult['vlan_ids'];
        }

        return [
            'name' => $mirrorName,
            'session_id' => $sessionId,
            'dst_ports' => $dstPorts,
            'vlan_ids' => $vlanIds,
        ];
    }

    /**
     * @return array{vlan_ids: list<int>}|array{error: string}
     */
    public function fetchMirrorVlanIdsForDevice(Device $device): array
    {
        if (! $device->scope_id) {
            return ['error' => 'No scope id for device '.$device->name];
        }

        $mergedVlanIds = [];

        $deviceResult = $this->fetchVlanIdsFromL2Vlans($device, static::localDeviceInterfaceQueryParameters($device));
        if (array_key_exists('error', $deviceResult)) {
            return $deviceResult;
        }
        $mergedVlanIds = array_merge($mergedVlanIds, $deviceResult['vlan_ids']);

        if (filled($device->group)) {
            $groupScopeId = $this->resolveGroupScopeId($device);
            if ($groupScopeId === null) {
                return ['error' => 'Could not resolve group scope ID for device '.$device->name.'.'];
            }

            if ($groupScopeId !== (string) $device->scope_id) {
                $groupQueryParameters = [
                    'view-type' => 'LOCAL',
                    'object-type' => 'LOCAL',
                    'scope-id' => $groupScopeId,
                    'device-function' => static::deviceFunctionQueryValue($device),
                ];

                $groupResult = $this->fetchVlanIdsFromL2Vlans($device, $groupQueryParameters);
                if (array_key_exists('error', $groupResult)) {
                    return $groupResult;
                }

                $mergedVlanIds = array_merge($mergedVlanIds, $groupResult['vlan_ids']);
            }
        }

        $mergedVlanIds = array_values(array_unique($mergedVlanIds));
        sort($mergedVlanIds);

        if ($mergedVlanIds === []) {
            return ['error' => 'No VLANs found for device '.$device->name];
        }

        return ['vlan_ids' => $mergedVlanIds];
    }

    /**
     * @return array{vlan_ids: list<int>}|array{error: string}
     */
    private function fetchVlanIdsFromL2Vlans(Device $device, array $queryParameters): array
    {
        $response = $this->get_l2_vlans($queryParameters);
        if (is_array($response) && array_key_exists('error', $response)) {
            return ['error' => (string) $response['error']];
        }

        if (! $response instanceof Response || ! $response->ok()) {
            return ['error' => 'Failed to retrieve VLANs for device '.$device->name.': '.$this->centralResponseErrorMessage($response)];
        }

        $l2Vlans = $response->json()['l2-vlan'] ?? [];
        if (! is_array($l2Vlans)) {
            $l2Vlans = [];
        }

        $vlanIds = [];
        foreach ($l2Vlans as $vlan) {
            if (! is_array($vlan)) {
                continue;
            }
            $vlanId = (int) ($vlan['vlan'] ?? 0);
            if ($vlanId >= TrunkVlanRanges::MIN_VLAN && $vlanId <= TrunkVlanRanges::MAX_VLAN) {
                $vlanIds[] = $vlanId;
            }
        }

        return ['vlan_ids' => array_values(array_unique($vlanIds))];
    }

    /**
     * @param  list<string>  $dstPorts
     * @param  list<int>  $vlanIds
     * @return array<string, mixed>
     */
    public static function buildMirrorPayload(Device $device, string $name, int $sessionId, array $dstPorts, array $vlanIds): array
    {
        return [
            'name' => $name,
            'session' => [
                'enable' => true,
                'session-id' => $sessionId,
                'session-destination' => [
                    'destination-type' => 'INTERFACES',
                    'destination-switch-serial' => $device->serial,
                    'eth-interfaces' => array_map(
                        fn (string $port): array => ['eth-interface' => $port],
                        $dstPorts
                    ),
                ],
                'session-sources' => [
                    'source-switch-serial' => $device->serial,
                    'vlans' => array_map(
                        fn (int $vlanId): array => ['direction' => 'BOTH', 'vlan-id' => $vlanId],
                        $vlanIds
                    ),
                ],
            ],
        ];
    }

    /**
     * @return list<string>|array{error: string}
     */
    private static function resolveMirrorDestinationPortsFromNamePattern(Device $device): array
    {
        $name = strtoupper((string) $device->name);

        if (str_contains($name, 'FZN-MDF-MGMT')) {
            return ['1/1/21', '1/1/22'];
        }

        if (str_contains($name, 'MDF-MGMT')) {
            return ['1/1/16', '2/1/9'];
        }

        if (str_contains($name, 'CORE')) {
            return ['1/1/43'];
        }

        return ['error' => 'Cannot determine mirror destination ports for '.$device->name.'.'];
    }
}
