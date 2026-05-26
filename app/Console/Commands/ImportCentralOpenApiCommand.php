<?php

namespace App\Console\Commands;

use App\Services\CentralOpenApiRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ImportCentralOpenApiCommand extends Command
{
    protected $signature = 'central:import-openapi
                            {source? : URL to fetch or path to a local OpenAPI JSON file}
                            {--name= : Output filename without extension (defaults from info.title)}
                            {--extract-json : Extract JSON from a ReadMe markdown page containing a ```json block}';

    protected $description = 'Import a New Central OpenAPI spec fragment into resources/openapi/new-central/';

    public function handle(CentralOpenApiRegistry $registry): int
    {
        $source = $this->argument('source');

        if ($source === null) {
            $this->line('Usage: php artisan central:import-openapi <url-or-file> [--name=scope-management]');
            $this->line('Place JSON files in resources/openapi/new-central/ or import from Developer Hub reference pages.');

            return self::SUCCESS;
        }

        $raw = $this->readSource($source);

        if ($raw === null) {
            return self::FAILURE;
        }

        $spec = json_decode($raw, true);

        if (! is_array($spec)) {
            $this->error('Source is not valid JSON.');

            return self::FAILURE;
        }

        $name = $this->option('name')
            ?? Str::slug($spec['info']['title'] ?? 'imported-spec', '-');

        $outputDir = resource_path('openapi/new-central');
        File::ensureDirectoryExists($outputDir);

        $outputPath = $outputDir.'/'.$name.'.json';

        $normalized = [
            'openapi' => $spec['openapi'] ?? '3.0.3',
            'info' => $spec['info'] ?? ['title' => $name],
            'tags' => $spec['tags'] ?? [],
            'paths' => $spec['paths'] ?? [],
        ];

        File::put($outputPath, json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $registry->clearCache();

        $pathCount = count($normalized['paths']);
        $this->info("Wrote {$outputPath} ({$pathCount} path(s)). Registry cache cleared.");

        return self::SUCCESS;
    }

    private function readSource(string $source): ?string
    {
        if (is_file($source)) {
            return File::get($source);
        }

        if (filter_var($source, FILTER_VALIDATE_URL)) {
            $response = Http::timeout(60)->get($source);

            if (! $response->ok()) {
                $this->error("HTTP {$response->status()} fetching {$source}");

                return null;
            }

            $body = $response->body();

            if ($this->option('extract-json')) {
                return $this->extractJsonFromMarkdown($body);
            }

            return $body;
        }

        $this->error("Source not found: {$source}");

        return null;
    }

    private function extractJsonFromMarkdown(string $markdown): ?string
    {
        if (preg_match('/```json\s*(\{.*?\})\s*```/s', $markdown, $matches) !== 1) {
            $this->error('No ```json block found in response.');

            return null;
        }

        return $matches[1];
    }
}
