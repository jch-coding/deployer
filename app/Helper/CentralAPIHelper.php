<?php

namespace App\Helper;

use App\Models\Client;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CentralAPIHelper
{
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
    ];

    public array $routing_and_overlays = [
        'static_route' => 'network-config/v1alpha1/static-route',
    ];

    public array $configManagement = [
        'persona_assignment' => 'network-config/v1alpha1/persona-assignment/',
    ];

    public array $interfaces = [
        'interface_ethernet' => 'network-config/v1alpha1/ethernet-interfaces/',
        'interface_portchannel' => 'network-config/v1alpha1/portchannels/',
        'switch_port_profile' => 'network-config/v1alpha1/sw-port-profiles/',
        'interface_vlan' => 'network-config/v1alpha1/vlan-interfaces/',
        'interface_loopback' => 'network-config/v1alpha1/loopback-interfaces/',
    ];

    public array $vlans_and_networks = [
        'l2_vlans' => 'network-config/v1alpha1/layer2-vlan/',
    ];

    public array $switchMonitoring = [
        'switches' => 'network-monitoring/v1/switches',
    ];

    public array $high_availability = [
        'switch_stack' => 'network-config/v1alpha1/stacks/',
    ];

    public array $classic_monitoring = [
        'sites' => 'central/v2/sites',
    ];

    public array $classic_configuration = [
        'move_devices_to_group' => 'configuration/v1/devices/move',
        'preprovision_devices_to_group' => 'configuration/v1/preassign',
        'groups' => 'configuration/v2/groups',
        'groupsv3' => 'configuration/v3/groups',
    ];

    public function __construct(public Client $client) {}

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
        if (! $response->ok()) {
            return null;
        }
        $central_sites = $response->json()['items'];
        $central_site = array_find($central_sites, fn ($central_site) => $central_site['scopeName'] === $site->name);
        if (count($central_site) === 0) {
            return null;
        }

        return $central_site['scopeId'];
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

    public function updateSystemInfo(Device $device)
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters([
                    'object-type' => 'LOCAL',
                    'scope-id' => $device->scope_id,
                    'device-function' => $device->device_function,
                ])->withBody(json_encode([
                    'hostname' => $device->name,
                ]))->patch($this->client->base_url.$this->system['system_info']);

            return $response;
        }
    }

    public function postSystemInfo(Device $device)
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters([
                    'object-type' => 'LOCAL',
                    'scope-id' => $device->scope_id,
                    'device-function' => $device->device_function,
                ])->withBody(json_encode([
                    'hostname' => $device->name,
                ]))->post($this->client->base_url.$this->system['system_info']);

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
                ->post($this->client->base_url.$this->configManagement['persona_assignment'].$device_function, $body);

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
            ])->post($this->client->base_url.$this->high_availability['switch_stack'].$stack_name, $vsf_profile);

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
        $switch_port_configuration['trunk-type'] = $deviceInterface->lacp_profile->trunk_type;
        $switch_port_configuration['port-list'] = $deviceInterface->lacp_profile->port_list;
        $switch_port_configuration['enable'] = $deviceInterface->enable;

        return array_merge($switch_port_configuration, ['lacp' => $lacp_profile]);
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

    public static function build_ethernet_interface_patch_body(DeviceInterface $deviceInterface): array
    {
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
                ->patch($this->client->base_url.$this->interfaces['interface_ethernet'].$deviceInterface->interface);

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
                ])->get($this->client->base_url.$this->interfaces['interface_ethernet'].$deviceInterface->interface);

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
                ])->post($this->client->base_url.$this->interfaces['interface_portchannel'].$deviceInterface->interface, $switch_port);

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

            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($params)
                ->get($this->client->base_url.$this->interfaces['interface_portchannel']);

            if (! $response->ok()) {
                $message = (string) ($response->json('message') ?? $response->body());

                return ['error' => $message !== '' ? $message : 'Failed to fetch portchannels from Central.'];
            }

            $pageItems = $response->json('items', []);
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
                ->patch($this->client->base_url.$this->interfaces['interface_portchannel'].$deviceInterface->interface, $switch_port);

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
                ->delete($this->client->base_url.$this->interfaces['interface_portchannel'].$portchannel_name);

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
                ->post($this->client->base_url.$this->interfaces['interface_vlan'].$vlan_id, $interface_vlan_body);

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
                ->patch($this->client->base_url.$this->interfaces['interface_vlan'].$vlan_id, $interface_vlan_body);

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
                ->post($this->client->base_url.$this->vlans_and_networks['l2_vlans'].$l2_vlan['vlan'], $l2_vlan);

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
                ->delete($this->client->base_url.$this->vlans_and_networks['l2_vlans'].$l2_vlan);

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

    public function get_sw_port_profile($profile_name = '', $queryParameters = ['view-type' => 'LIBRARY'])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($queryParameters
                )->get($this->client->base_url.$this->interfaces['switch_port_profile'].$profile_name);

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
                ->post($this->client->base_url.$this->interfaces['switch_port_profile'].$sw_port_profile['profile-name'], $sw_port_profile);

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
                ->patch($this->client->base_url.$this->interfaces['switch_port_profile'].$sw_port_profile['profile-name'], $sw_port_profile);

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

    public function classic_get_sites()
    {
        if (! $this->client->handleClassicBearerToken()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->classic_access_token)
                ->get($this->client->classic_base_url.$this->classic_monitoring['sites']);

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
                ->get($this->client->classic_base_url.$this->classic_monitoring['sites']);

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
            ->post($this->client->classic_base_url.$this->classic_monitoring['sites'].'/associations', $device_to_site_body);

        return $response;
    }

    public function classic_associate_device_to_site($device_to_site_body)
    {
        if (! $this->client->handleClassicBearerToken()) {
            return ['error' => 'failed to get access token from central.'];
        }
        $response = Http::withToken($this->client->classic_access_token)
            ->post($this->client->classic_base_url.$this->classic_monitoring['sites'].'/associate', $device_to_site_body);

        return $response;
    }

    public function classic_get_groups()
    {
        if (! $this->client->handleClassicBearerToken()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->classic_access_token)
                ->withQueryParameters(['limit' => 20, 'offset' => 0])
                ->get($this->client->classic_base_url.$this->classic_configuration['groups']);

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
            ->post($this->client->classic_base_url.$this->classic_configuration['groupsv3'], $body);

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
                ->get($this->client->classic_base_url.$this->classic_configuration['groups']);

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
            ->post($this->client->classic_base_url.$this->classic_configuration['move_devices_to_group'], ['group' => $group, 'serials' => $device_serials]);

        return $response;
    }

    public function preprovision_devices_to_group(string $group, array $device_serials)
    {
        if (! $this->client->handleClassicBearerToken()) {
            return ['error' => 'failed to get access token from central.'];
        }
        $response = Http::withToken($this->client->classic_access_token)
            ->post($this->client->classic_base_url.$this->classic_configuration['preprovision_devices_to_group'], ['group_name' => $group, 'device_id' => $device_serials]);

        return $response;
    }
}
