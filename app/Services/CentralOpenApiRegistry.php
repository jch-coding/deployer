<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class CentralOpenApiRegistry
{
    private const CACHE_KEY = 'central_openapi_registry';

    private const SPEC_DIRECTORY = 'openapi/new-central';

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $operationsById = null;

    /**
     * @var list<array<string, mixed>>|null
     */
    private ?array $operationsList = null;

    /**
     * @return list<array{name: string, description: string|null}>
     */
    public function tags(): array
    {
        $tags = [];
        $seen = [];

        foreach ($this->operations() as $operation) {
            foreach ($operation['tags'] as $tagName) {
                if (isset($seen[$tagName])) {
                    continue;
                }

                $seen[$tagName] = true;
                $tags[] = [
                    'name' => $tagName,
                    'description' => $this->tagDescriptions()[$tagName] ?? null,
                ];
            }
        }

        usort($tags, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $tags;
    }

    /**
     * @return list<array{
     *     operation_id: string,
     *     method: string,
     *     path: string,
     *     summary: string|null,
     *     description: string|null,
     *     tags: list<string>,
     *     parameters: list<array<string, mixed>>,
     *     requires_body: bool,
     *     reference_url: string|null
     * }>
     */
    public function operations(): array
    {
        if ($this->operationsList !== null) {
            return $this->operationsList;
        }

        $this->loadOperations();

        return $this->operationsList ?? [];
    }

    /**
     * @return array{
     *     operation_id: string,
     *     method: string,
     *     path: string,
     *     summary: string|null,
     *     description: string|null,
     *     tags: list<string>,
     *     parameters: list<array<string, mixed>>,
     *     requires_body: bool,
     *     reference_url: string|null
     * }
     */
    public function operation(string $operationId): array
    {
        $this->loadOperations();

        if (! isset($this->operationsById[$operationId])) {
            throw new InvalidArgumentException("Unknown operation [{$operationId}].");
        }

        return $this->operationsById[$operationId];
    }

    public function hasOperation(string $operationId): bool
    {
        $this->loadOperations();

        return isset($this->operationsById[$operationId]);
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget($this->cacheKey());
        $this->operationsById = null;
        $this->operationsList = null;
    }

    private function loadOperations(): void
    {
        if ($this->operationsById !== null) {
            return;
        }

        $payload = Cache::remember($this->cacheKey(), now()->addDay(), fn (): array => $this->buildFromSpecs());

        $this->operationsById = $payload['by_id'];
        $this->operationsList = $payload['list'];
    }

    private function cacheKey(): string
    {
        return self::CACHE_KEY.':'.$this->specFingerprint();
    }

    private function specFingerprint(): string
    {
        $directory = resource_path(self::SPEC_DIRECTORY);

        if (! File::isDirectory($directory)) {
            return 'empty';
        }

        $parts = [];

        foreach (File::files($directory) as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }

            $parts[] = $file->getFilename().':'.$file->getMTime();
        }

        sort($parts);

        return substr(sha1(implode('|', $parts)), 0, 12);
    }

    /**
     * @return array{by_id: array<string, array<string, mixed>>, list: list<array<string, mixed>>}
     */
    private function buildFromSpecs(): array
    {
        $directory = resource_path(self::SPEC_DIRECTORY);

        if (! File::isDirectory($directory)) {
            return ['by_id' => [], 'list' => []];
        }

        $byId = [];
        $tagDescriptions = [];

        foreach (File::files($directory) as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }

            $spec = json_decode(File::get($file->getPathname()), true);

            if (! is_array($spec)) {
                continue;
            }

            foreach ($spec['tags'] ?? [] as $tag) {
                if (is_array($tag) && isset($tag['name'])) {
                    $tagDescriptions[$tag['name']] = $tag['description'] ?? null;
                }
            }

            foreach ($spec['paths'] ?? [] as $path => $pathItem) {
                if (! is_array($pathItem)) {
                    continue;
                }

                foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
                    if (! isset($pathItem[$method]) || ! is_array($pathItem[$method])) {
                        continue;
                    }

                    $operation = $pathItem[$method];
                    $operationId = $operation['operationId'] ?? null;

                    if (! is_string($operationId) || $operationId === '') {
                        continue;
                    }

                    $normalizedPath = '/'.ltrim($path, '/');
                    $parameters = [];

                    foreach ($operation['parameters'] ?? [] as $parameter) {
                        if (! is_array($parameter)) {
                            continue;
                        }

                        $parameters[] = [
                            'in' => $parameter['in'] ?? 'query',
                            'name' => $parameter['name'] ?? '',
                            'required' => (bool) ($parameter['required'] ?? false),
                            'description' => $parameter['description'] ?? null,
                            'schema' => is_array($parameter['schema'] ?? null) ? $parameter['schema'] : ['type' => 'string'],
                        ];
                    }

                    $byId[$operationId] = [
                        'operation_id' => $operationId,
                        'method' => strtoupper($method),
                        'path' => $normalizedPath,
                        'summary' => $operation['summary'] ?? null,
                        'description' => $operation['description'] ?? null,
                        'tags' => array_values(array_filter(
                            $operation['tags'] ?? ['Uncategorized'],
                            fn ($tag): bool => is_string($tag) && $tag !== '',
                        )),
                        'parameters' => $parameters,
                        'requires_body' => $this->operationRequiresBody($method, $operation),
                        'reference_url' => $this->referenceUrlForOperation($operationId),
                        'spec_file' => $file->getFilename(),
                    ];
                }
            }
        }

        $list = array_values($byId);

        usort($list, function (array $a, array $b): int {
            $tagCompare = strcmp($a['tags'][0] ?? '', $b['tags'][0] ?? '');

            return $tagCompare !== 0 ? $tagCompare : strcmp($a['summary'] ?? $a['operation_id'], $b['summary'] ?? $b['operation_id']);
        });

        return [
            'by_id' => $byId,
            'list' => $list,
            'tag_descriptions' => $tagDescriptions,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function tagDescriptions(): array
    {
        $payload = Cache::get($this->cacheKey());

        if (is_array($payload) && isset($payload['tag_descriptions']) && is_array($payload['tag_descriptions'])) {
            return $payload['tag_descriptions'];
        }

        $built = $this->buildFromSpecs();

        return $built['tag_descriptions'] ?? [];
    }

    private function referenceUrlForOperation(string $operationId): ?string
    {
        return 'https://developer.arubanetworks.com/new-central-config/reference/'.strtolower($operationId);
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function operationRequiresBody(string $method, array $operation): bool
    {
        if (! in_array($method, ['post', 'put', 'patch'], true)) {
            return false;
        }

        return isset($operation['requestBody']) && is_array($operation['requestBody']);
    }
}
