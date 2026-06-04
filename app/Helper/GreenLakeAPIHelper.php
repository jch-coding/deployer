<?php

namespace App\Helper;

use App\Models\Client;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GreenLakeAPIHelper
{
    public const BASE_URL = 'https://global.api.greenlake.hpe.com';

    public array $subscriptions = [
        'list' => '/subscriptions/v1/subscriptions',
        'detail' => '/subscriptions/v1/subscriptions/{id}',
        'create' => '/subscriptions/v1/subscriptions',
        'async_operation' => '/subscriptions/v1/async-operations/{id}',
    ];

    public array $devices = [
        'list' => '/devices/v1/devices',
        'detail' => '/devices/v1/devices/{id}',
        'create' => '/devices/v1/devices',
        'update' => '/devices/v1/devices',
        'async_operation' => '/devices/v1/async-operations/{id}',
    ];

    public function __construct(public Client $client) {}

    private function apiUrl(string $path): string
    {
        return rtrim(self::BASE_URL, '/').$path;
    }

    /**
     * @return Response|array{error: string}
     */
    public function getSubscriptions(array $queryParameters = [])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token for GreenLake.'];
        }

        $queryParameters = array_merge([
            'select' => 'id,key,sku,tierDescription,quantity,availableQuantity,subscriptionStatus,productType,startTime,endTime,tags,tier',
        ], $queryParameters);

        return Http::withToken($this->client->bearer_token)
            ->acceptJson()
            ->withQueryParameters($queryParameters)
            ->get($this->apiUrl($this->subscriptions['list']));
    }

    /**
     * @return array{subscriptions: array<int, array<string, mixed>>}|array{error: string}
     */
    public function parseSubscriptions(mixed $response): array
    {
        if (is_array($response) && isset($response['error'])) {
            return ['error' => (string) $response['error']];
        }

        if (! $response instanceof Response || ! $response->ok()) {
            return ['error' => 'failed to get subscriptions from GreenLake.'];
        }

        $items = $response->json('items', []);
        if (! is_array($items)) {
            $items = [];
        }

        return $this->parseSubscriptionsFromItems($items);
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array{subscriptions: array<int, array<string, mixed>>}
     */
    public function parseSubscriptionsFromItems(array $items): array
    {
        $subscriptions = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $subscriptions[] = $this->normalizeGreenLakeSubscription($item);
        }

        return ['subscriptions' => $subscriptions];
    }

    /**
     * GreenLake subscription list defaults to 50 per page; large limit values can be rejected.
     *
     * @return array<int, array<string, mixed>>|array{error: string}
     */
    public function collectSubscriptions(int $limit = 50): array
    {
        $allItems = [];
        $offset = 0;

        while (true) {
            $response = $this->getSubscriptions([
                'limit' => $limit,
                'offset' => $offset,
            ]);

            if (is_array($response)) {
                return ['error' => (string) ($response['error'] ?? 'Failed to fetch subscriptions from GreenLake.')];
            }

            if (! $response->ok()) {
                return ['error' => $this->formatSubscriptionFetchError($response, $offset, $limit)];
            }

            $pageItems = $this->parseSubscriptionPageItems($response);

            if ($pageItems === []) {
                break;
            }

            $allItems = array_merge($allItems, $pageItems);

            if (! $this->shouldFetchNextSubscriptionPage($response, count($pageItems), $limit, count($allItems))) {
                break;
            }

            $offset += $limit;
        }

        return $allItems;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseSubscriptionPageItems(Response $response): array
    {
        $items = $response->json('items', []);
        if (! is_array($items)) {
            $items = [];
        }

        return array_values(array_filter($items, fn ($item) => is_array($item)));
    }

    private function shouldFetchNextSubscriptionPage(
        Response $response,
        int $pageCount,
        int $limit,
        int $collectedTotal,
    ): bool {
        $remaining = $response->json('remainingRecords');
        if ($remaining === false) {
            return false;
        }

        if ($remaining === true) {
            return true;
        }

        $total = $response->json('total') ?? $response->json('count');
        if (is_numeric($total) && $collectedTotal >= (int) $total) {
            return false;
        }

        return $pageCount >= $limit;
    }

    private function formatSubscriptionFetchError(Response $response, int $offset, int $limit): string
    {
        $message = $this->extractErrorMessage($response);

        return "failed to get subscriptions from GreenLake (HTTP {$response->status()}, offset {$offset}, limit {$limit}): {$message}";
    }

    /**
     * Distinguishes collect* error payloads from successful numeric lists.
     */
    public static function isCollectError(mixed $result): bool
    {
        return is_array($result)
            && isset($result['error'])
            && ! array_is_list($result);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    /**
     * GreenLake tags are key-value metadata. Returns tag keys for display and filtering.
     *
     * @return array<int, string>
     */
    public static function normalizeTagKeys(mixed $tags): array
    {
        if (! is_array($tags) || $tags === []) {
            return [];
        }

        if (! array_is_list($tags)) {
            return array_values(array_unique(array_filter(
                array_map(
                    fn ($key) => is_string($key) ? trim($key) : '',
                    array_keys($tags),
                ),
                fn ($key) => $key !== '',
            )));
        }

        $keys = [];
        foreach ($tags as $tag) {
            if (is_string($tag)) {
                $trimmed = trim($tag);
                if ($trimmed !== '') {
                    $keys[] = $trimmed;
                }

                continue;
            }

            if (! is_array($tag)) {
                continue;
            }

            $key = trim((string) ($tag['key'] ?? ''));
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    public function normalizeGreenLakeSubscription(array $item): array
    {
        $status = (string) ($item['subscriptionStatus'] ?? '');
        $normalizedStatus = self::normalizeSubscriptionStatus($status);

        return [
            'subscription_key' => (string) ($item['key'] ?? $item['subscriptionKey'] ?? ''),
            'subscription_sku' => (string) ($item['sku'] ?? ''),
            'license_type' => (string) ($item['tierDescription'] ?? ''),
            'start_date' => $this->normalizeDateTime($item['startTime'] ?? null),
            'end_date' => $this->normalizeDateTime($item['endTime'] ?? null),
            'status' => $normalizedStatus,
            'subscription_type' => (string) ($item['productType'] ?? ''),
            'available' => (int) (
                $item['availableQuantity']
                ?? $item['available']
                ?? $item['unassignedQuantity']
                ?? 0
            ),
            'quantity' => (int) ($item['quantity'] ?? $item['totalQuantity'] ?? 0),
            'acpapp_name' => '',
            'tags' => self::normalizeTagKeys($item['tags'] ?? []),
            'greenlake_subscription_id' => (string) ($item['id'] ?? ''),
            'tier' => (string) ($item['tier'] ?? ''),
        ];
    }

    public static function normalizeSubscriptionStatus(string $status): string
    {
        $upper = strtoupper(trim($status));
        if ($upper === '') {
            return '';
        }

        if (in_array($upper, ['ACTIVE', 'OK', 'VALID', 'ENABLED', 'AVAILABLE', 'SUBSCRIBED', 'IN_USE', 'ASSIGNED'], true)) {
            return 'OK';
        }

        return $status;
    }

    public static function subscriptionIsAssignable(string $status): bool
    {
        $raw = trim($status);
        if ($raw === '') {
            return true;
        }

        if (self::normalizeSubscriptionStatus($raw) === 'OK') {
            return true;
        }

        $upper = strtoupper($raw);

        return ! in_array($upper, [
            'EXPIRED',
            'TERMINATED',
            'CANCELLED',
            'CANCELED',
            'REVOKED',
            'SUSPENDED',
            'INACTIVE',
            'FAILED',
            'DISABLED',
        ], true);
    }

    private function normalizeDateTime(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->getTimestampMs();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return Response|array{error: string}
     */
    public function getSubscription(string $id)
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token for GreenLake.'];
        }

        $path = str_replace('{id}', $id, $this->subscriptions['detail']);

        return Http::withToken($this->client->bearer_token)
            ->get($this->apiUrl($path));
    }

    /**
     * @param  array<int, array{key: string}>  $subscriptions
     * @return Response|array{error: string}
     */
    public function addSubscriptions(array $subscriptions)
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token for GreenLake.'];
        }

        return Http::withToken($this->client->bearer_token)
            ->post($this->apiUrl($this->subscriptions['create']), [
                'subscriptions' => $subscriptions,
            ]);
    }

    /**
     * @return Response|array{error: string}
     */
    public function getSubscriptionAsyncOperation(string $id)
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token for GreenLake.'];
        }

        $path = str_replace('{id}', $id, $this->subscriptions['async_operation']);

        return Http::withToken($this->client->bearer_token)
            ->get($this->apiUrl($path));
    }

    /**
     * @return Response|array{error: string}
     */
    public function getDevices(array $queryParameters = [])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token for GreenLake.'];
        }

        return Http::withToken($this->client->bearer_token)
            ->withQueryParameters($queryParameters)
            ->get($this->apiUrl($this->devices['list']));
    }

    /**
     * @return array{devices: array<int, array<string, mixed>>}|array{error: string}
     */
    public function parseDevices(mixed $response): array
    {
        if (is_array($response) && isset($response['error'])) {
            return ['error' => (string) $response['error']];
        }

        if (! $response instanceof Response || ! $response->ok()) {
            return ['error' => 'failed to get devices from GreenLake.'];
        }

        $items = $response->json('items', []);
        if (! is_array($items)) {
            $items = [];
        }

        return ['devices' => array_values(array_filter($items, fn ($item) => is_array($item)))];
    }

    /**
     * @return array<int, array<string, mixed>>|array{error: string}
     */
    public function collectDevices(int $limit = 1000): array
    {
        $allDevices = [];
        $offset = 0;

        while (true) {
            $response = $this->getDevices([
                'limit' => $limit,
                'offset' => $offset,
            ]);

            if (is_array($response)) {
                return ['error' => (string) ($response['error'] ?? 'Failed to fetch devices from GreenLake.')];
            }

            if (! $response->ok()) {
                return ['error' => 'failed to get devices from GreenLake.'];
            }

            $pageDevices = $response->json('items', []);
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
     * @return Response|array{error: string}
     */
    public function getDevice(string $id)
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token for GreenLake.'];
        }

        $path = str_replace('{id}', $id, $this->devices['detail']);

        return Http::withToken($this->client->bearer_token)
            ->get($this->apiUrl($path));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Response|array{error: string}
     */
    public function addDevices(array $payload)
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token for GreenLake.'];
        }

        return Http::withToken($this->client->bearer_token)
            ->post($this->apiUrl($this->devices['create']), $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Response|array{error: string}
     */
    public function updateDevice(string $deviceId, array $payload)
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token for GreenLake.'];
        }

        return Http::withToken($this->client->bearer_token)
            ->withQueryParameters(['id' => $deviceId])
            ->patch($this->apiUrl($this->devices['update']), $payload);
    }

    /**
     * @param  array<int, string>  $deviceIds
     * @return array{responses: array<int, Response>, error: string|null}
     */
    public function assignSubscriptionToDevices(array $deviceIds, string $greenlakeSubscriptionId): array
    {
        $responses = [];
        $firstError = null;

        foreach ($deviceIds as $deviceId) {
            $deviceId = trim($deviceId);
            if ($deviceId === '') {
                continue;
            }

            $response = $this->updateDevice($deviceId, [
                'subscription' => [
                    ['id' => $greenlakeSubscriptionId],
                ],
            ]);

            if (is_array($response)) {
                $firstError ??= (string) ($response['error'] ?? 'failed to assign subscription on GreenLake.');

                continue;
            }

            $responses[] = $response;

            if (! $response->ok()) {
                $firstError ??= $this->extractErrorMessage($response);
            }
        }

        return [
            'responses' => $responses,
            'error' => $firstError,
        ];
    }

    /**
     * @param  array<int, string>  $deviceIds
     * @return array{responses: array<int, Response>, error: string|null}
     */
    public function unassignSubscriptionFromDevices(array $deviceIds): array
    {
        $responses = [];
        $firstError = null;

        foreach ($deviceIds as $deviceId) {
            $deviceId = trim($deviceId);
            if ($deviceId === '') {
                continue;
            }

            $response = $this->updateDevice($deviceId, [
                'subscription' => [],
            ]);

            if (is_array($response)) {
                $firstError ??= (string) ($response['error'] ?? 'failed to unassign subscription on GreenLake.');

                continue;
            }

            $responses[] = $response;

            if (! $response->ok()) {
                $firstError ??= $this->extractErrorMessage($response);
            }
        }

        return [
            'responses' => $responses,
            'error' => $firstError,
        ];
    }

    /**
     * @param  array<int, string>  $deviceIds
     * @return array{
     *     results: array<int, array{device_id: string, success: bool}>,
     *     responses: array<int, Response>,
     *     error: string|null
     * }
     */
    public function removeDevicesFromWorkspace(array $deviceIds): array
    {
        $responses = [];
        $results = [];
        $firstError = null;

        foreach ($deviceIds as $deviceId) {
            $deviceId = trim($deviceId);
            if ($deviceId === '') {
                continue;
            }

            $success = true;

            $unassignResponse = $this->updateDevice($deviceId, [
                'subscription' => [],
            ]);

            if (is_array($unassignResponse)) {
                $firstError ??= (string) ($unassignResponse['error'] ?? 'failed to unassign subscription on GreenLake.');
                $results[] = ['device_id' => $deviceId, 'success' => false];

                continue;
            }

            $responses[] = $unassignResponse;

            if (! $unassignResponse->ok()) {
                $firstError ??= $this->extractErrorMessage($unassignResponse);
                $results[] = ['device_id' => $deviceId, 'success' => false];

                continue;
            }

            $applicationResponse = $this->assignDeviceToApplication($deviceId, null);

            if (is_array($applicationResponse)) {
                $firstError ??= (string) ($applicationResponse['error'] ?? 'failed to unassign application on GreenLake.');
                $success = false;
            } else {
                $responses[] = $applicationResponse;

                if (! $applicationResponse->ok()) {
                    $firstError ??= $this->extractErrorMessage($applicationResponse);
                    $success = false;
                }
            }

            $results[] = ['device_id' => $deviceId, 'success' => $success];
        }

        return [
            'results' => $results,
            'responses' => $responses,
            'error' => $firstError,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Response|array{error: string}
     */
    public function updateDevices(string $deviceId, array $payload)
    {
        return $this->updateDevice($deviceId, $payload);
    }

    /**
     * @param  string|null  $applicationId  Pass null to remove application assignment.
     * @return Response|array{error: string}
     */
    public function assignDeviceToApplication(string $deviceId, ?string $applicationId)
    {
        $payload = [];
        if ($applicationId !== null && $applicationId !== '') {
            $payload['application'] = ['id' => $applicationId];
        } else {
            $payload['application'] = null;
        }

        return $this->updateDevice($deviceId, $payload);
    }

    /**
     * @return Response|array{error: string}
     */
    public function getDeviceAsyncOperation(string $id)
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token for GreenLake.'];
        }

        $path = str_replace('{id}', $id, $this->devices['async_operation']);

        return Http::withToken($this->client->bearer_token)
            ->get($this->apiUrl($path));
    }

    private function extractErrorMessage(Response $response): string
    {
        $json = $response->json();
        if (is_array($json) && isset($json['message'])) {
            return (string) $json['message'];
        }

        return $response->body() !== '' ? $response->body() : 'GreenLake request failed.';
    }

    /**
     * @param  array<string, mixed>  $device
     * @param  array<string, array<string, mixed>>  $subscriptionsByGreenLakeId
     * @param  array<string, array<string, mixed>>  $subscriptionsByKey
     * @return array<string, mixed>
     */
    public function normalizeGreenLakeDevice(
        array $device,
        array $subscriptionsByGreenLakeId = [],
        array $subscriptionsByKey = [],
    ): array {
        $serial = (string) ($device['serialNumber'] ?? '');
        $greenlakeDeviceId = (string) ($device['id'] ?? '');
        $subscriptionKey = '';
        $assignedServices = [];

        $subscriptionRefs = $device['subscription'] ?? $device['subscriptions'] ?? [];
        if (is_array($subscriptionRefs) && $subscriptionRefs !== []) {
            $first = $subscriptionRefs[0] ?? null;
            if (is_array($first)) {
                $subId = (string) ($first['id'] ?? '');
                if ($subId !== '' && isset($subscriptionsByGreenLakeId[$subId])) {
                    $subscriptionKey = (string) ($subscriptionsByGreenLakeId[$subId]['subscription_key'] ?? '');
                }
                $subKey = (string) ($first['key'] ?? '');
                if ($subscriptionKey === '' && $subKey !== '') {
                    $subscriptionKey = $subKey;
                }
            }
        }

        $tier = (string) ($device['tier'] ?? '');
        if ($tier !== '') {
            $assignedServices[] = $tier;
        }

        $deviceType = (string) ($device['deviceType'] ?? $device['type'] ?? '');

        return [
            'serial' => $serial,
            'model' => (string) ($device['model'] ?? ''),
            'mac' => (string) ($device['macAddress'] ?? ''),
            'device_type' => $deviceType,
            'name' => (string) ($device['name'] ?? $serial),
            'licensed' => $subscriptionKey !== '',
            'assigned_services' => array_values(array_unique($assignedServices)),
            'subscription_key' => $subscriptionKey,
            'greenlake_device_id' => $greenlakeDeviceId,
        ];
    }
}
