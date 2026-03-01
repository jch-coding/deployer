<?php

namespace App\Helper;
use App\Models\Client;
use App\Models\Device;
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

    public function __construct(public Client $client)
    {
    }

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
        if (!array_key_exists('items', $response)) {
            return ['error' => 'failed to get scope-id from central.'];
        } else {
            $items = $response['items'];
            $hierarchy = $items[0]['hierarchy'];
            return array_filter($hierarchy, fn($item) => $item['childCount'] === null && $item['scopeType'] === 'device');
        }
    }

    public function updateSystemInfo(Device $device)
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token from central.'];
        }
        else {
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
}
