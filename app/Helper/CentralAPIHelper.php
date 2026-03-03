<?php

namespace App\Helper;

use App\Models\Client;
use App\Models\Device;
use App\Models\DeviceInterface;
use Illuminate\Support\Facades\Http;

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
    ];

    public function __construct(public Client $client) {}

    public function getScopeIdFromCentral(Device $device)
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        }

        $response = Http::withToken($this->client->bearer_token)
            ->withQueryParameters([
                'scope-id' => $device->scope_id,
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
            'interface' => $deviceInterface->interface,
            'switchport' => $switch_port,
            'portchannel-lag' => $deviceInterface->portchannel_lag,
            'stp' => $stp_profile,
        ];

        return array_filter($switchport_rest_body, fn ($value) => $value !== []);
    }

    public function patch_ethernet_interface(Device $deviceInterface)
    {
        $interface_rest_body = static::build_switchport_from_device_interface($deviceInterface);

        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters([
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
                    'object-type' => 'LOCAL',
                    'scope-id' => $device->scope_id,
                    'device_function' => $device->device_function,
                ])->get($this->client->base_url.$this->interfaces['interface_ethernet'].$deviceInterface->interface);

            return $response;
        }
    }

    public function post_interface_portchannel(DeviceInterface $deviceInterface)
    {
        $switch_port = static::build_switchport_from_device_interface($deviceInterface);
        $switch_port['trunk-type'] = $deviceInterface->trunk_type;

        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        } else {
            $response = Http::withToken($this->client->bearer_token)
                ->withQueryParameters([
                    'object-type' => 'LOCAL',
                    'scope-id' => $deviceInterface->device->scope_id,
                    'device_function' => $deviceInterface->device->device_function,
                ])->post($this->client->base_url.$this->interfaces['interface_portchannel'].$deviceInterface->interface, $switch_port);

            return $response;
        }
    }
}
