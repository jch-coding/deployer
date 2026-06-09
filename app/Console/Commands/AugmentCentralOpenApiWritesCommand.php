<?php

namespace App\Console\Commands;

use App\Services\CentralOpenApiRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AugmentCentralOpenApiWritesCommand extends Command
{
    protected $signature = 'central:augment-openapi-writes';

    protected $description = 'Add POST/PATCH/DELETE operations to existing GET-only OpenAPI spec fragments';

    private const SPEC_DIRECTORY = 'openapi/new-central';

    private const SKIP_SPEC_FILES = [
        'configuration-health.json',
    ];

    public function handle(CentralOpenApiRegistry $registry): int
    {
        $directory = resource_path(self::SPEC_DIRECTORY);
        $added = 0;

        foreach (File::files($directory) as $file) {
            if ($file->getExtension() !== 'json' || in_array($file->getFilename(), self::SKIP_SPEC_FILES, true)) {
                continue;
            }

            $spec = json_decode(File::get($file->getPathname()), true);

            if (! is_array($spec)) {
                $this->warn("Skipping invalid JSON: {$file->getFilename()}");

                continue;
            }

            $fileAdded = 0;

            foreach ($spec['paths'] ?? [] as $path => $pathItem) {
                if (! is_array($pathItem) || ! isset($pathItem['get']) || ! is_array($pathItem['get'])) {
                    continue;
                }

                $readOperation = $pathItem['get'];
                $readOperationId = $readOperation['operationId'] ?? null;

                if (! is_string($readOperationId) || ! str_starts_with($readOperationId, 'read')) {
                    continue;
                }

                $hasPathParam = str_contains($path, '{');

                if ($hasPathParam) {
                    $writes = $this->writesForNamedPath($readOperation, $readOperationId);
                } else {
                    $writes = $this->writesForSingletonPath($readOperation, $readOperationId);
                }

                foreach ($writes as $method => $operation) {
                    if (isset($pathItem[$method])) {
                        continue;
                    }

                    $pathItem[$method] = $operation;
                    $fileAdded++;
                }

                $spec['paths'][$path] = $pathItem;
            }

            if ($fileAdded > 0) {
                File::put(
                    $file->getPathname(),
                    json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
                );
                $this->info("{$file->getFilename()}: added {$fileAdded} write operation(s).");
                $added += $fileAdded;
            }
        }

        if ($added === 0) {
            $this->info('No write operations were added (specs may already be augmented).');
        } else {
            try {
                $registry->clearCache();
                $this->info('Registry cache cleared.');
            } catch (\Throwable) {
                $this->warn('Could not clear registry cache (cache store unavailable).');
            }

            $this->info("Total write operations added: {$added}.");
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function writesForNamedPath(array $readOperation, string $readOperationId): array
    {
        $resource = $this->resourceNameFromReadOperationId($readOperationId);

        if ($resource === null) {
            return [];
        }

        $tags = $readOperation['tags'] ?? ['Uncategorized'];
        $parameters = $readOperation['parameters'] ?? [];

        return [
            'post' => $this->buildWriteOperation(
                'create'.$resource,
                'Create or replace '.$this->humanResourceLabel($resource),
                $readOperation['description'] ?? null,
                $tags,
                $parameters,
                true,
            ),
            'patch' => $this->buildWriteOperation(
                'update'.$resource,
                'Update '.$this->humanResourceLabel($resource),
                $readOperation['description'] ?? null,
                $tags,
                $parameters,
                true,
            ),
            'delete' => $this->buildWriteOperation(
                'delete'.$resource,
                'Delete '.$this->humanResourceLabel($resource),
                $readOperation['description'] ?? null,
                $tags,
                $parameters,
                false,
            ),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function writesForSingletonPath(array $readOperation, string $readOperationId): array
    {
        $resource = $this->resourceNameFromReadOperationId($readOperationId);

        if ($resource === null) {
            return [];
        }

        $tags = $readOperation['tags'] ?? ['Uncategorized'];
        $parameters = $readOperation['parameters'] ?? [];

        return [
            'patch' => $this->buildWriteOperation(
                'update'.$resource,
                'Update '.$this->humanResourceLabel($resource).' configuration',
                $readOperation['description'] ?? null,
                $tags,
                $parameters,
                true,
            ),
        ];
    }

    /**
     * @param  list<string>  $tags
     * @param  list<array<string, mixed>>  $parameters
     * @return array<string, mixed>
     */
    private function buildWriteOperation(
        string $operationId,
        string $summary,
        ?string $description,
        array $tags,
        array $parameters,
        bool $requiresBody,
    ): array {
        $operation = [
            'operationId' => $operationId,
            'summary' => $summary,
            'tags' => $tags,
            'parameters' => $parameters,
        ];

        if ($description !== null && $description !== '') {
            $operation['description'] = $description;
        }

        if ($requiresBody) {
            $operation['requestBody'] = [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                        ],
                    ],
                ],
            ];
        }

        return $operation;
    }

    private function resourceNameFromReadOperationId(string $readOperationId): ?string
    {
        if (! str_starts_with($readOperationId, 'read')) {
            return null;
        }

        $suffix = substr($readOperationId, 4);

        if (str_ends_with($suffix, 'ByName')) {
            return substr($suffix, 0, -6);
        }

        return $suffix;
    }

    private function humanResourceLabel(string $resource): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1 $2', $resource) ?? $resource);
    }
}
