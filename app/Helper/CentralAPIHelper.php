<?php

namespace App\Helper;

use App\Models\Client;
use App\Models\Device;
use App\Models\DeviceInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CentralAPIHelper
{
    public array $scopeManagement = [
        'hierarchy' => [
            'scope_hierarchy' => 'network-config/v1alpha1/hierarchy',
        ],
    ];

    public array $system = [
        'system_info' => 'network-config/v1alpha1/system-info',
    ];

    public array $interfaces = [
        'interface_ethernet' => 'network-config/v1alpha1/ethernet-interfaces/',
        'interface_portchannel' => 'network-config/v1alpha1/portchannels/',
        'switch_port_profile' => 'network-config/v1alpha1/sw-port-profiles/',
    ];

    public function __construct(public Client $client) {}

    public function getScopeIdFromCentral(Device $device)
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        }

        $response = Http::withToken($this->client->bearer_token)
            ->withQueryParameters([
                'scope-id' => $device->serial,
                'scope-type' => 'device',
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

    public static function categorize_device_interfaces(array $deviceInterfaces)
    {
        return collect($deviceInterfaces)->groupBy(function ($interface) {
            return $interface->lacp_profile ? 'portchannel_interfaces' : 'ethernet_interfaces';
        })->toArray();
    }

    public static function build_portchannel_from_device_interface(DeviceInterface $deviceInterface)
    {
        $lacp_profile = [];
        $port_list = [];
        $switch_port_configuration = static::build_switchport_from_device_interface($deviceInterface);

        if ($deviceInterface->lacp_profile !== null)
        {
            $port_list = collect(
                array_map(fn($s) => explode('-', $s),
                    explode('&', $deviceInterface->lacp_profile->port_list))
            )->flatten()->toArray();
            $lacp_profile = [
                'port-list' => $port_list,
                'mode' => $deviceInterface->lacp_profile->mode,
                'rate' => $deviceInterface->lacp_profile->rate
            ];
            $switch_port_configuration['trunk-type'] = $deviceInterface->lacp_profile->trunk_type;
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

        $switchport_rest_body = [
            'name' => $deviceInterface->interface,
            'switchport' => $switch_port,
            'portchannel-lag' => $deviceInterface->portchannel_lag,
            'stp' => $stp_profile,
        ];

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

    public function get_sw_port_profile()
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters([
                    'view-type' => 'LIBRARY'
                ])->get($this->client->base_url.$this->interfaces['switch_port_profile']);
            return $response;
        }
    }
}
