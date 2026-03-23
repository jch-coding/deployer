<?php

namespace App\Helper;

use App\Models\Client;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\Site;
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
    ];

    public array $system = [
        'system_info' => 'network-config/v1alpha1/system-info',
    ];

    public array $configManagement = [
        'persona_assignment' => 'network-config/v1alpha1/persona-assignment/',
    ];

    public array $interfaces = [
        'interface_ethernet' => 'network-config/v1alpha1/ethernet-interfaces/',
        'interface_portchannel' => 'network-config/v1alpha1/portchannels/',
        'switch_port_profile' => 'network-config/v1alpha1/sw-port-profiles/',
        'interface_vlan' => 'network-config/v1alpha1/vlan-interfaces/',
    ];

    public array $vlans_and_networks = [
        'l2_vlans' => 'network-config/v1alpha1/layer2-vlan/',
    ];

    public array $switchMonitoring = [
        'switches' => 'network-monitoring/v1/switches/',
    ];

    public array $classic_monitoring = [
        'sites' => 'central/v2/sites',
    ];

    public array $classic_configuration = [
        'move_devices_to_group' => 'configuration/v1/devices/move',
        'preprovision_devices_to_group' => 'configuration/v1/preassign',
    ];

    public function __construct(public Client $client) {}

    public function getScopeIdFromCentral(Device $device)
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        }

        if (str_contains($device->device_function, 'SWITCH')) {
            $get_switches_response = $this->get_switches();
            if (! $get_switches_response->ok()) {
                Log::error('failed to get switches from central. Using switch serial to retrieve scope-id. Will fail if switch is a stack.');
            } else {
                $switches = $get_switches_response->json()['items'];
                $stack_id = static::getStackId($device, $switches);
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

    public function updateSystemInfo(Device $device)
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters([
                    'object-type' => 'LOCAL',
                    'scope-id' => $device->scope_id,
                    'device_function' => $device->device_function,
                ])->withBody(json_encode([
                    'hostname' => $device->name,
                ]))->patch($this->client->base_url.$this->system['system_info']);

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
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $body = [
                'persona-device-list' => [
                    [
                        'device-function' => $device_function,
                        'device-id-list-v2' => array_map(fn ($serial) => ['device-id-v2' => $serial], $devices),
                    ],
                ],
            ];
            $response = Http::withToken($this->client->bearer_token)
                ->post($this->client->base_url.$this->configManagement['persona_assignment'].$device_function, $body);

            return $response;
        }
    }

    public static function categorize_device_interfaces(array $deviceInterfaces)
    {
        return collect($deviceInterfaces)->groupBy(function ($interface) {
            return $interface->lacp_profile ? 'portchannel_interfaces' : 'ethernet_interfaces';
        })->toArray();
    }

    public static function build_portchannel_from_device_interface(DeviceInterface $deviceInterface)
    {
        $switch_port_configuration = static::build_switchport_from_device_interface($deviceInterface);

        if ($deviceInterface->lacp_profile !== null) {
            $lacp_profile = [
                'port-list' => $deviceInterface->lacp_profile->port_list,
                'mode' => $deviceInterface->lacp_profile->mode,
                'rate' => $deviceInterface->lacp_profile->rate,
            ];
            $switch_port_configuration['trunk-type'] = $deviceInterface->lacp_profile->trunk_type;
        }
        if ($deviceInterface->sw_profile !== null) {
            $switch_port_configuration['sw-profile'] = $deviceInterface->sw_profile;
        } elseif ($deviceInterface->stp_profile !== null) {
            $switch_port_configuration['stp'] = $deviceInterface->stp_profile->filter(fn ($attr) => $attr !== null)->toArray();
        }

        return array_merge($switch_port_configuration, ['lacp' => $lacp_profile]);
    }

    public static function build_switchport_from_device_interface(DeviceInterface $deviceInterface)
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
        $switchport_rest_body = ['name' => $deviceInterface->interface];
        if ($deviceInterface->sw_profile !== null) {
            $switchport_rest_body['sw-profile'] = $deviceInterface->sw_profile;
        } elseif ($deviceInterface->portchannel_lag !== null) {
            $switchport_rest_body['portchannel-lag'] = $deviceInterface->portchannel_lag;
        } else {
            $switchport_rest_body = array_merge($switchport_rest_body, [
                'switchport' => $switch_port,
                'stp' => $stp_profile,
            ]);
        }

        return array_filter($switchport_rest_body, fn ($value) => $value !== []);
    }

    public function patch_ethernet_interface(DeviceInterface $deviceInterface)
    {
        $interface_rest_body = static::build_switchport_from_device_interface($deviceInterface);

        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters([
                    'view-type' => 'LOCAL',
                    'object-type' => 'LOCAL',
                    'scope-id' => $deviceInterface->device->scope_id,
                    'device_function' => $deviceInterface->device->device_function,
                ])->withBody(json_encode($interface_rest_body))
                ->patch($this->client->base_url.$this->interfaces['interface_ethernet'].$deviceInterface->interface);

            return $response;
        }
    }

    public function get_ethernet_interface(Device $device, DeviceInterface $deviceInterface)
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters([
                    'view-type' => 'LOCAL',
                    'object-type' => 'LOCAL',
                    'scope-id' => $device->scope_id,
                    'device_function' => $device->device_function,
                ])->get($this->client->base_url.$this->interfaces['interface_ethernet'].$deviceInterface->interface);

            return $response;
        }
    }

    public function post_interface_portchannel(DeviceInterface $deviceInterface)
    {
        $switch_port = static::build_portchannel_from_device_interface($deviceInterface);

        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters([
                    'view-type' => 'LOCAL',
                    'object-type' => 'LOCAL',
                    'scope-id' => $deviceInterface->device->scope_id,
                    'device_function' => $deviceInterface->device->device_function,
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

    public function patch_interface_portchannel(DeviceInterface $deviceInterface, $queryParameters = [])
    {
        $switch_port = static::build_portchannel_from_device_interface($deviceInterface);
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters($queryParameters)
                ->patch($this->client->base_url.$this->interfaces['interface_portchannel'].$switch_port->name, $switch_port);

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
                ->withQueryParameters([
                    'view-type' => 'LOCAL',
                    'object-type' => 'LOCAL',
                    'scope-id' => $device->scope_id,
                    'device-function' => $device->device_function,
                ])
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
    public function post_l2_vlan(Device $device, array $l2_vlan)
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
                ->post($this->client->base_url.$this->vlans_and_networks['l2_vlans'].$l2_vlan['vlan'], $l2_vlan);

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

    /*
     *  param: $filter = [ 'siteId', 'siteName', 'model', 'status', 'deployment' ]
     *
     *  used with eq or in. Ex ['siteName' => ' eq SAC Warehouse'] will translate to 'siteName eq "SAC Warehouse"'
     */
    public function get_switches(array $filter = [])
    {
        $response = Http::withToken($this->client->bearer_token)
            ->withQueryParameters($filter)
            ->get($this->client->base_url.$this->switchMonitoring['switches']);

        return $response;
    }

    public function get_sites()
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->get($this->client->base_url.$this->scopeManagement['sites']['sites']);

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
