<?php

namespace App\Services\Ekahau;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class EkahauWorkspace
{
    private const CACHE_PREFIX = 'ekahau_download:';

    private const TTL_SECONDS = 3600;

    public function create(): string
    {
        $id = (string) Str::uuid();
        $path = storage_path('app/ekahau/'.$id);
        if (! mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new RuntimeException("Unable to create Ekahau workspace at {$path}.");
        }

        return $path;
    }

    public function storeUpload(UploadedFile $file, string $workspace, string $subdirectory = 'uploads'): string
    {
        $dir = $workspace.'/'.$subdirectory;
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Unable to create upload directory at {$dir}.");
        }

        $name = $file->getClientOriginalName();
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'upload.bin';
        $target = $dir.'/'.$safeName;
        $file->move($dir, $safeName);

        return $target;
    }

    /**
     * @param  list<UploadedFile>  $files
     * @return list<string>
     */
    public function storeUploads(array $files, string $workspace, string $subdirectory = 'uploads'): array
    {
        $paths = [];
        foreach ($files as $index => $file) {
            $paths[] = $this->storeUpload($file, $workspace, $subdirectory.'/'.$index);
        }

        return $paths;
    }

    /**
     * @param  list<string>  $paths
     * @param  array<string, mixed>  $results
     */
    public function registerDownload(string $workspace, array $paths, array $results, string $downloadName): string
    {
        $token = (string) Str::uuid();
        $outputDir = $workspace.'/output';
        if (! is_dir($outputDir) && ! mkdir($outputDir, 0755, true) && ! is_dir($outputDir)) {
            throw new RuntimeException("Unable to create output directory at {$outputDir}.");
        }

        if (count($paths) === 1) {
            $downloadPath = $paths[0];
            $filename = basename($downloadPath);
        } else {
            $filename = $downloadName;
            $downloadPath = $outputDir.'/'.$filename;
            $this->zipPaths($paths, $downloadPath);
        }

        Cache::put(self::CACHE_PREFIX.$token, [
            'workspace' => $workspace,
            'path' => $downloadPath,
            'filename' => $filename,
            'results' => $results,
        ], self::TTL_SECONDS);

        return $token;
    }

    /**
     * @return array{workspace: string, path: string, filename: string, results: array<string, mixed>}|null
     */
    public function resolveDownload(string $token): ?array
    {
        $payload = Cache::get(self::CACHE_PREFIX.$token);
        if (! is_array($payload) || ! isset($payload['path'], $payload['filename'], $payload['workspace'])) {
            return null;
        }

        return $payload;
    }

    public function forgetDownload(string $token): void
    {
        $payload = $this->resolveDownload($token);
        Cache::forget(self::CACHE_PREFIX.$token);

        if ($payload !== null) {
            $this->cleanup($payload['workspace']);
        }
    }

    public function cleanup(string $workspace): void
    {
        if (is_dir($workspace) && str_contains($workspace, storage_path('app/ekahau/'))) {
            File::deleteDirectory($workspace);
        }
    }

    /**
     * @param  list<string>  $paths
     */
    private function zipPaths(array $paths, string $zipPath): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Unable to create zip at {$zipPath}.");
        }

        foreach ($paths as $path) {
            $zip->addFile($path, basename($path));
        }

        $zip->close();
    }
}
