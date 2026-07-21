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

    public array $devicesV2 = [
        'update' => '/devices/v2beta1/devices',
        'async_operation' => '/devices/v2beta1/async-operations/{id}',
    ];

    public array $locations = [
        'list' => '/locations/v1/locations',
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
    public function getLocations(array $queryParameters = [])
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token for GreenLake.'];
        }

        return Http::withToken($this->client->bearer_token)
            ->acceptJson()
            ->withQueryParameters($queryParameters)
            ->get($this->apiUrl($this->locations['list']));
    }

    /**
     * @return array<int, array{id: string, name: string}>|array{error: string}
     */
    public function collectLocations(int $limit = 50): array
    {
        $allLocations = [];
        $offset = 0;

        while (true) {
            $response = $this->getLocations([
                'limit' => $limit,
                'offset' => $offset,
            ]);

            if (is_array($response)) {
                return ['error' => (string) ($response['error'] ?? 'Failed to fetch locations from GreenLake.')];
            }

            if (! $response->ok()) {
                return ['error' => 'failed to get locations from GreenLake.'];
            }

            $pageItems = $response->json('items', []);
            if (! is_array($pageItems)) {
                $pageItems = [];
            }

            if ($pageItems === []) {
                break;
            }

            foreach ($pageItems as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $id = trim((string) ($item['id'] ?? ''));
                $name = trim((string) ($item['name'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $allLocations[] = [
                    'id' => $id,
                    'name' => $name !== '' ? $name : $id,
                ];
            }

            $total = $response->json('total') ?? $response->json('count');
            if (is_numeric($total) && count($allLocations) >= (int) $total) {
                break;
            }

            if (count($pageItems) < $limit) {
                break;
            }

            $offset += $limit;
        }

        usort(
            $allLocations,
            fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']),
        );

        return $allLocations;
    }

    /**
     * Map serial numbers to GreenLake device resource IDs.
     *
     * @param  array<int, string>  $serials
     * @return array<string, string>|array{error: string}
     */
    public function deviceIdsBySerial(array $serials): array
    {
        $wanted = [];
        foreach ($serials as $serial) {
            $serial = trim((string) $serial);
            if ($serial !== '') {
                $wanted[strtoupper($serial)] = $serial;
            }
        }

        if ($wanted === []) {
            return [];
        }

        $devices = $this->collectDevices();
        if (self::isCollectError($devices)) {
            return ['error' => (string) ($devices['error'] ?? 'failed to get devices from GreenLake.')];
        }

        $map = [];
        foreach ($devices as $device) {
            if (! is_array($device)) {
                continue;
            }
            $serial = trim((string) ($device['serialNumber'] ?? ''));
            $id = trim((string) ($device['id'] ?? ''));
            if ($serial === '' || $id === '') {
                continue;
            }
            $key = strtoupper($serial);
            if (isset($wanted[$key])) {
                $map[$wanted[$key]] = $id;
            }
        }

        return $map;
    }

    /**
     * Assign a GreenLake location to devices (v2beta1) and wait for async completion.
     *
     * @param  array<int, string>  $deviceIds
     * @return array{
     *     success: bool,
     *     error: string|null,
     *     results: array<string, bool>,
     *     transaction_id: string|null,
     *     status: string|null
     * }
     */
    public function assignLocationToDevices(
        array $deviceIds,
        string $locationId,
        bool $sleepBetweenPolls = true,
    ): array {
        $locationId = trim($locationId);
        $ids = [];
        foreach ($deviceIds as $deviceId) {
            $deviceId = trim((string) $deviceId);
            if ($deviceId !== '') {
                $ids[] = $deviceId;
            }
        }
        $ids = array_values(array_unique($ids));

        if ($locationId === '' || $ids === []) {
            return [
                'success' => false,
                'error' => 'Location id and at least one device id are required.',
                'results' => [],
                'transaction_id' => null,
                'status' => null,
            ];
        }

        if (! $this->client->handleBearerTokenAuth()) {
            return [
                'success' => false,
                'error' => 'failed to get access token for GreenLake.',
                'results' => [],
                'transaction_id' => null,
                'status' => null,
            ];
        }

        $aggregateResults = [];
        $lastStatus = null;
        $lastTransactionId = null;

        foreach (array_chunk($ids, 25) as $chunk) {
            $query = collect($chunk)
                ->map(fn (string $id): string => 'id='.rawurlencode($id))
                ->implode('&');

            $response = Http::withToken($this->client->bearer_token)
                ->acceptJson()
                ->asJson()
                ->patch($this->apiUrl($this->devicesV2['update']).'?'.$query, [
                    'location' => ['id' => $locationId],
                ]);

            if ($response->status() !== 202) {
                return [
                    'success' => false,
                    'error' => $this->extractErrorMessage($response),
                    'results' => $aggregateResults,
                    'transaction_id' => $lastTransactionId,
                    'status' => $lastStatus,
                ];
            }

            $operationId = $this->extractAsyncOperationId($response);
            if ($operationId === '') {
                return [
                    'success' => false,
                    'error' => 'GreenLake accepted the location assignment but did not return an async operation id.',
                    'results' => $aggregateResults,
                    'transaction_id' => null,
                    'status' => null,
                ];
            }

            $result = $this->waitForDeviceAsyncOperation(
                $operationId,
                $chunk,
                $sleepBetweenPolls,
                asyncOperationPath: $this->devicesV2['async_operation'],
            );

            $lastStatus = $result['status'];
            $lastTransactionId = $result['transaction_id'];
            foreach ($result['results'] as $deviceId => $ok) {
                $aggregateResults[$deviceId] = $ok;
            }

            if ($result['success'] !== true) {
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'GreenLake location assignment failed.',
                    'results' => $aggregateResults,
                    'transaction_id' => $lastTransactionId,
                    'status' => $lastStatus,
                ];
            }
        }

        foreach ($ids as $id) {
            if (! array_key_exists($id, $aggregateResults)) {
                $aggregateResults[$id] = true;
            }
        }

        return [
            'success' => true,
            'error' => null,
            'results' => $aggregateResults,
            'transaction_id' => $lastTransactionId,
            'status' => $lastStatus,
        ];
    }

    /**
     * Upsert GreenLake device tags (v2beta1) and wait for async completion.
     *
     * @param  array<int, string>  $deviceIds
     * @param  array<string, string>  $tags
     * @return array{
     *     success: bool,
     *     error: string|null,
     *     results: array<string, bool>,
     *     transaction_id: string|null,
     *     status: string|null
     * }
     */
    public function assignTagsToDevices(
        array $deviceIds,
        array $tags,
        bool $sleepBetweenPolls = true,
    ): array {
        $ids = [];
        foreach ($deviceIds as $deviceId) {
            $deviceId = trim((string) $deviceId);
            if ($deviceId !== '') {
                $ids[] = $deviceId;
            }
        }
        $ids = array_values(array_unique($ids));

        $normalizedTags = [];
        foreach ($tags as $key => $value) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            $normalizedTags[$key] = trim((string) $value);
        }

        if ($normalizedTags === [] || $ids === []) {
            return [
                'success' => false,
                'error' => 'At least one tag and one device id are required.',
                'results' => [],
                'transaction_id' => null,
                'status' => null,
            ];
        }

        if (! $this->client->handleBearerTokenAuth()) {
            return [
                'success' => false,
                'error' => 'failed to get access token for GreenLake.',
                'results' => [],
                'transaction_id' => null,
                'status' => null,
            ];
        }

        $aggregateResults = [];
        $lastStatus = null;
        $lastTransactionId = null;

        foreach (array_chunk($ids, 25) as $chunk) {
            $query = collect($chunk)
                ->map(fn (string $id): string => 'id='.rawurlencode($id))
                ->implode('&');

            $response = Http::withToken($this->client->bearer_token)
                ->acceptJson()
                ->asJson()
                ->patch($this->apiUrl($this->devicesV2['update']).'?'.$query, [
                    'tags' => $normalizedTags,
                ]);

            if ($response->status() !== 202) {
                return [
                    'success' => false,
                    'error' => $this->extractErrorMessage($response),
                    'results' => $aggregateResults,
                    'transaction_id' => $lastTransactionId,
                    'status' => $lastStatus,
                ];
            }

            $operationId = $this->extractAsyncOperationId($response);
            if ($operationId === '') {
                return [
                    'success' => false,
                    'error' => 'GreenLake accepted the tag update but did not return an async operation id.',
                    'results' => $aggregateResults,
                    'transaction_id' => null,
                    'status' => null,
                ];
            }

            $result = $this->waitForDeviceAsyncOperation(
                $operationId,
                $chunk,
                $sleepBetweenPolls,
                asyncOperationPath: $this->devicesV2['async_operation'],
            );

            $lastStatus = $result['status'];
            $lastTransactionId = $result['transaction_id'];
            foreach ($result['results'] as $deviceId => $ok) {
                $aggregateResults[$deviceId] = $ok;
            }

            if ($result['success'] !== true) {
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'GreenLake tag update failed.',
                    'results' => $aggregateResults,
                    'transaction_id' => $lastTransactionId,
                    'status' => $lastStatus,
                ];
            }
        }

        foreach ($ids as $id) {
            if (! array_key_exists($id, $aggregateResults)) {
                $aggregateResults[$id] = true;
            }
        }

        return [
            'success' => true,
            'error' => null,
            'results' => $aggregateResults,
            'transaction_id' => $lastTransactionId,
            'status' => $lastStatus,
        ];
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
            ->acceptJson()
            ->asJson()
            ->post($this->apiUrl($this->devices['create']), $payload);
    }

    /**
     * Add network devices to GreenLake inventory and wait for the async operation.
     *
     * @param  array<int, array{serial: string, mac_address: string, tags?: array<string, string>|null}>  $devices
     * @return array{
     *     success: bool,
     *     error: string|null,
     *     results: array<string, bool>,
     *     transaction_id: string|null,
     *     status: string|null
     * }
     */
    public function addNetworkDevices(array $devices, bool $sleepBetweenPolls = true): array
    {
        $network = [];
        foreach ($devices as $device) {
            $serial = trim((string) ($device['serial'] ?? ''));
            $mac = trim((string) ($device['mac_address'] ?? ''));
            if ($serial === '' || $mac === '') {
                continue;
            }
            $item = [
                'serialNumber' => $serial,
                'macAddress' => $mac,
            ];
            $tags = $device['tags'] ?? null;
            if (is_array($tags) && $tags !== []) {
                $item['tags'] = $tags;
            }
            $network[] = $item;
        }

        if ($network === []) {
            return [
                'success' => false,
                'error' => 'No network devices with serial and mac_address to add.',
                'results' => [],
                'transaction_id' => null,
                'status' => null,
            ];
        }

        $response = $this->addDevices([
            'network' => $network,
            'compute' => [],
            'storage' => [],
        ]);

        if (is_array($response)) {
            return [
                'success' => false,
                'error' => (string) ($response['error'] ?? 'failed to add devices to GreenLake.'),
                'results' => [],
                'transaction_id' => null,
                'status' => null,
            ];
        }

        if ($response->status() !== 202) {
            return [
                'success' => false,
                'error' => $this->extractErrorMessage($response),
                'results' => [],
                'transaction_id' => null,
                'status' => null,
            ];
        }

        $operationId = $this->extractAsyncOperationId($response);
        if ($operationId === '') {
            return [
                'success' => false,
                'error' => 'GreenLake accepted the request but did not return an async operation id.',
                'results' => [],
                'transaction_id' => null,
                'status' => null,
            ];
        }

        return $this->waitForDeviceAsyncOperation(
            $operationId,
            array_column($network, 'serialNumber'),
            $sleepBetweenPolls,
        );
    }

    /**
     * Prefer the Location header (HPE/Aruba docs) over body transactionId.
     */
    private function extractAsyncOperationId(Response $response): string
    {
        $location = (string) ($response->header('Location') ?? '');
        if ($location !== '' && preg_match('#/async-operations/([^/?]+)#', $location, $matches) === 1) {
            return trim($matches[1]);
        }

        return trim((string) ($response->json('transactionId') ?? ''));
    }

    /**
     * @param  array<int, string>  $expectedSerials
     * @return array{
     *     success: bool,
     *     error: string|null,
     *     results: array<string, bool>,
     *     transaction_id: string|null,
     *     status: string|null
     * }
     */
    public function waitForDeviceAsyncOperation(
        string $operationId,
        array $expectedSerials = [],
        bool $sleepBetweenPolls = true,
        int $maxAttempts = 60,
        ?string $asyncOperationPath = null,
    ): array {
        $status = null;
        $results = [];

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $response = $this->getDeviceAsyncOperation($operationId, $asyncOperationPath);

            if (is_array($response)) {
                return [
                    'success' => false,
                    'error' => (string) ($response['error'] ?? 'failed to poll GreenLake async operation.'),
                    'results' => $results,
                    'transaction_id' => $operationId,
                    'status' => $status,
                ];
            }

            if (! $response->successful()) {
                $error = $this->extractErrorMessage($response);
                // Async ops can briefly 404 right after accept; retry those.
                if (
                    $response->status() === 404
                    && $attempt < $maxAttempts - 1
                    && str_contains(strtolower($error), 'not found')
                ) {
                    if ($sleepBetweenPolls) {
                        sleep(2);
                    }

                    continue;
                }

                return [
                    'success' => false,
                    'error' => $error,
                    'results' => $results,
                    'transaction_id' => $operationId,
                    'status' => $status,
                ];
            }

            $body = $response->json();
            if (! is_array($body)) {
                return [
                    'success' => false,
                    'error' => 'GreenLake async operation response was invalid.',
                    'results' => $results,
                    'transaction_id' => $operationId,
                    'status' => $status,
                ];
            }

            $status = strtoupper((string) ($body['status'] ?? ''));
            $results = $this->parseAsyncOperationDeviceResults($body, $expectedSerials);

            if ($this->isTerminalAsyncStatus($status)) {
                $success = $this->isSuccessfulAsyncStatus($status);
                $error = null;
                if (! $success) {
                    $error = $this->extractAsyncOperationError($body, $status, $results);
                } elseif ($results !== [] && in_array(false, $results, true)) {
                    $success = false;
                    $error = $this->extractAsyncOperationError($body, $status, $results);
                }

                return [
                    'success' => $success,
                    'error' => $error,
                    'results' => $results,
                    'transaction_id' => $operationId,
                    'status' => $status,
                ];
            }

            $interval = (int) ($body['suggestedPollingIntervalSeconds'] ?? 2);
            if ($sleepBetweenPolls && $interval > 0) {
                sleep(min($interval, 10));
            }
        }

        return [
            'success' => false,
            'error' => 'Timed out waiting for GreenLake device add operation.',
            'results' => $results,
            'transaction_id' => $operationId,
            'status' => $status,
        ];
    }

    private function isTerminalAsyncStatus(string $status): bool
    {
        return in_array($status, [
            'SUCCEEDED',
            'COMPLETED',
            'FAILED',
            'TIMEOUT',
            'TIMEDOUT',
            'TIMED_OUT',
        ], true);
    }

    private function isSuccessfulAsyncStatus(string $status): bool
    {
        return in_array($status, ['SUCCEEDED', 'COMPLETED'], true);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, bool>  $results
     */
    private function extractAsyncOperationError(array $body, string $status, array $results): string
    {
        $resultPayload = $body['result'] ?? null;
        if (is_array($resultPayload)) {
            $failed = $resultPayload['failed'] ?? $resultPayload['failures'] ?? $resultPayload['unsuccessful'] ?? null;
            if (is_array($failed)) {
                $messages = [];
                foreach ($failed as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $serial = trim((string) ($item['serialNumber'] ?? $item['serial'] ?? ''));
                    $message = trim((string) ($item['message'] ?? $item['error'] ?? $item['description'] ?? ''));
                    if ($serial !== '' && $message !== '') {
                        $messages[] = "{$serial}: {$message}";
                    } elseif ($message !== '') {
                        $messages[] = $message;
                    }
                }
                if ($messages !== []) {
                    return implode(' ', $messages);
                }
            }
        }

        if ($results !== []) {
            $failedSerials = array_keys(array_filter($results, fn (bool $ok) => ! $ok));
            if ($failedSerials !== []) {
                return 'GreenLake device add operation '.$status.'. Failed serials: '.implode(', ', $failedSerials).'.';
            }
        }

        return 'GreenLake device add operation '.$status.'.';
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<int, string>  $expectedSerials
     * @return array<string, bool>
     */
    public function parseAsyncOperationDeviceResults(array $body, array $expectedSerials = []): array
    {
        $results = [];
        foreach ($expectedSerials as $serial) {
            $serial = trim($serial);
            if ($serial !== '') {
                $results[$serial] = true;
            }
        }

        $status = strtoupper((string) ($body['status'] ?? ''));
        if (! $this->isSuccessfulAsyncStatus($status) && $this->isTerminalAsyncStatus($status)) {
            foreach (array_keys($results) as $serial) {
                $results[$serial] = false;
            }
        }

        $resultPayload = $body['result'] ?? null;
        if (! is_array($resultPayload)) {
            return $results;
        }

        $succeeded = $resultPayload['succeeded'] ?? $resultPayload['successful'] ?? $resultPayload['success'] ?? null;
        $failed = $resultPayload['failed'] ?? $resultPayload['failures'] ?? $resultPayload['unsuccessful'] ?? null;

        foreach ($this->extractSerialsFromAsyncResultList($succeeded) as $serial) {
            $results[$serial] = true;
        }
        foreach ($this->extractSerialsFromAsyncResultList($failed) as $serial) {
            $results[$serial] = false;
        }

        return $results;
    }

    /**
     * @param  mixed  $list
     * @return array<int, string>
     */
    private function extractSerialsFromAsyncResultList(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }

        $serials = [];
        foreach ($list as $item) {
            if (is_string($item) && trim($item) !== '') {
                $serials[] = trim($item);

                continue;
            }
            if (! is_array($item)) {
                continue;
            }
            $serial = trim((string) ($item['serialNumber'] ?? $item['serial'] ?? ''));
            if ($serial !== '') {
                $serials[] = $serial;
            }
        }

        return $serials;
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

            if (! $response->successful()) {
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
    public function getDeviceAsyncOperation(string $id, ?string $asyncOperationPath = null)
    {
        if (! $this->client->handleBearerTokenAuth()) {
            return ['error' => 'failed to get access token for GreenLake.'];
        }

        $pathTemplate = $asyncOperationPath ?? $this->devices['async_operation'];
        $path = str_replace('{id}', $id, $pathTemplate);

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
