<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class CentralApiProxyService
{
    private const ALLOWED_PATH_PREFIXES = [
        'network-config/',
        'network-monitoring/',
    ];

    private const ALLOWED_METHODS = ['GET', 'POST', 'PATCH', 'DELETE'];

    public function __construct(private CentralOpenApiRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     * @return array{
     *     ok: bool,
     *     status: int|null,
     *     duration_ms: int,
     *     headers: array<string, string>,
     *     body: mixed,
     *     request_url: string|null,
     *     error: string|null
     * }
     */
    public function execute(Client $client, string $operationId, array $query = [], ?array $body = null): array
    {
        $started = microtime(true);

        try {
            $operation = $this->registry->operation($operationId);
        } catch (InvalidArgumentException $exception) {
            return $this->errorResult($exception->getMessage(), $started);
        }

        $method = strtoupper($operation['method']);

        if (! in_array($method, self::ALLOWED_METHODS, true)) {
            return $this->errorResult("Method [{$method}] is not enabled in the API explorer.", $started);
        }

        $relativePath = ltrim($operation['path'], '/');

        if (! $this->pathIsAllowlisted($relativePath)) {
            return $this->errorResult('Path is not allowlisted for the API explorer.', $started);
        }

        if (str_contains($relativePath, '..')) {
            return $this->errorResult('Invalid path.', $started);
        }

        $validationError = $this->validateRequiredParameters($operation['parameters'], $query);

        if ($validationError !== null) {
            return $this->errorResult($validationError, $started);
        }

        $bodyError = $this->validateRequestBody($operation, $body);

        if ($bodyError !== null) {
            return $this->errorResult($bodyError, $started);
        }

        [$resolvedPath, $query] = $this->resolvePathParameters($relativePath, $operation['parameters'], $query);

        if (! $client->handleBearerTokenAuth(true)) {
            return $this->errorResult('Failed to obtain access token from Central. Check client credentials on the Clients page.', $started, 401);
        }

        $client->refresh();

        if (blank($client->bearer_token)) {
            return $this->errorResult('Central access token is missing after authentication.', $started, 401);
        }

        $requestUrl = rtrim($client->base_url, '/').'/'.$resolvedPath;
        $query = $this->normalizeQueryParameters($query);

        try {
            $pending = Http::withToken($client->bearer_token)
                ->timeout(60)
                ->acceptJson()
                ->asJson();

            if ($query !== []) {
                $pending = $pending->withQueryParameters($query);
            }

            $response = match ($method) {
                'GET' => $pending->get($requestUrl),
                'POST' => $pending->post($requestUrl, $body ?? []),
                'PATCH' => $pending->patch($requestUrl, $body ?? []),
                'DELETE' => $pending->delete($requestUrl),
                default => throw new InvalidArgumentException("Unsupported method [{$method}]."),
            };
        } catch (ConnectionException) {
            return $this->errorResult('Connection to Central failed.', $started, 503);
        }

        $responseBody = $response->json();

        if ($responseBody === null && $response->body() !== '') {
            $responseBody = $response->body();
        }

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'headers' => $this->sanitizeHeaders($response->headers()),
            'body' => $responseBody,
            'request_url' => $this->requestUrlForDisplay($requestUrl, $query),
            'error' => $response->successful() ? null : $this->extractErrorMessage($response),
        ];
    }

    private function pathIsAllowlisted(string $path): bool
    {
        foreach (self::ALLOWED_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $parameters
     * @param  array<string, mixed>  $query
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function resolvePathParameters(string $path, array $parameters, array $query): array
    {
        foreach ($parameters as $parameter) {
            if (($parameter['in'] ?? 'query') !== 'path') {
                continue;
            }

            $name = $parameter['name'] ?? '';

            if ($name === '' || ! array_key_exists($name, $query)) {
                continue;
            }

            $value = $query[$name];
            unset($query[$name]);
            $path = str_replace('{'.$name.'}', rawurlencode((string) $value), $path);
        }

        return [$path, $query];
    }

    /**
     * @param  list<array<string, mixed>>  $parameters
     */
    private function validateRequiredParameters(array $parameters, array $query): ?string
    {
        foreach ($parameters as $parameter) {
            if (! ($parameter['required'] ?? false)) {
                continue;
            }

            $name = $parameter['name'] ?? '';

            if ($name === '') {
                continue;
            }

            $value = $query[$name] ?? null;

            if ($value === null || $value === '') {
                return "Missing required parameter [{$name}].";
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>|null  $body
     */
    private function validateRequestBody(array $operation, ?array $body): ?string
    {
        if (! ($operation['requires_body'] ?? false)) {
            return null;
        }

        if ($body === null || $body === []) {
            return 'Request body is required for this operation.';
        }

        return null;
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     * @return array<string, string>
     */
    private function sanitizeHeaders(array $headers): array
    {
        $allowed = [
            'content-type',
            'x-ratelimit-limit',
            'x-ratelimit-remaining',
            'x-ratelimit-reset',
        ];

        $sanitized = [];

        foreach ($headers as $name => $values) {
            $lower = strtolower($name);

            if (! in_array($lower, $allowed, true)) {
                continue;
            }

            $sanitized[$lower] = $values[0] ?? '';
        }

        return $sanitized;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function requestUrlForDisplay(string $url, array $query): string
    {
        if ($query === []) {
            return $url;
        }

        return $url.'?'.http_build_query($query);
    }

    /**
     * @return array{
     *     ok: bool,
     *     status: int|null,
     *     duration_ms: int,
     *     headers: array<string, string>,
     *     body: mixed,
     *     request_url: string|null,
     *     error: string|null
     * }
     */
    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function normalizeQueryParameters(array $query): array
    {
        $normalized = [];

        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (in_array($key, ['limit', 'offset'], true) && is_numeric($value)) {
                $normalized[$key] = (int) $value;

                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function extractErrorMessage(\Illuminate\Http\Client\Response $response): string
    {
        $message = $response->json('message')
            ?? $response->json('error')
            ?? $response->json('detail');

        if (is_array($message)) {
            $message = json_encode($message);
        }

        if (is_string($message) && $message !== '') {
            return $message;
        }

        return $response->reason() ?: 'Central API request failed.';
    }

    private function errorResult(string $message, float $started, int $status = 422): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'headers' => [],
            'body' => ['message' => $message],
            'request_url' => null,
            'error' => $message,
        ];
    }
}
