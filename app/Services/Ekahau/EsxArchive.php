<?php

namespace App\Services\Ekahau;

use RuntimeException;
use ZipArchive;

class EsxArchive
{
    /**
     * @return list<array{name: string, model: string, floor: string, location?: array<string, mixed>}>
     */
    public function readAccessPoints(string $esxPath): array
    {
        $payload = $this->readJson($esxPath, 'accessPoints.json');
        $floorplanNames = $this->floorPlanIdToName($esxPath);
        $aps = [];

        foreach ($payload['accessPoints'] ?? [] as $ap) {
            $floor = '';
            if (isset($ap['location']['floorPlanId'])) {
                $floor = $floorplanNames[$ap['location']['floorPlanId']] ?? '';
            }

            $aps[] = [
                'name' => (string) ($ap['name'] ?? ''),
                'model' => (string) ($ap['model'] ?? ''),
                'floor' => $floor,
                'location' => $ap['location'] ?? null,
            ];
        }

        return $aps;
    }

    /**
     * @return array<string, string>
     */
    public function floorPlanIdToName(string $esxPath): array
    {
        if (! $this->hasMember($esxPath, 'floorPlans.json')) {
            return [];
        }

        $payload = $this->readJson($esxPath, 'floorPlans.json');
        $map = [];
        foreach ($payload['floorPlans'] ?? [] as $floorPlan) {
            if (! isset($floorPlan['id'])) {
                continue;
            }
            $map[(string) $floorPlan['id']] = (string) ($floorPlan['name'] ?? '');
        }

        return $map;
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutator
     */
    public function updateAccessPoints(string $esxPath, callable $mutator): void
    {
        $payload = $this->readJson($esxPath, 'accessPoints.json');
        $payload = $mutator($payload);
        $this->replaceJsonMember($esxPath, 'accessPoints.json', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function readJson(string $esxPath, string $member): array
    {
        $zip = $this->open($esxPath, ZipArchive::RDONLY);
        try {
            $contents = $zip->getFromName($member);
            if ($contents === false) {
                throw new RuntimeException("Missing {$member} in ESX archive.");
            }

            $decoded = json_decode($contents, true);
            if (! is_array($decoded)) {
                throw new RuntimeException("Invalid JSON in {$member}.");
            }

            return $decoded;
        } finally {
            $zip->close();
        }
    }

    public function hasMember(string $esxPath, string $member): bool
    {
        $zip = $this->open($esxPath, ZipArchive::RDONLY);
        try {
            return $zip->locateName($member) !== false;
        } finally {
            $zip->close();
        }
    }

    /**
     * Rebuild the ESX ZIP with a replaced JSON member (avoids ZIP-append duplicates).
     *
     * @param  array<string, mixed>  $payload
     */
    public function replaceJsonMember(string $esxPath, string $member, array $payload): void
    {
        $tempPath = $esxPath.'.tmp-'.bin2hex(random_bytes(4));
        $source = $this->open($esxPath, ZipArchive::RDONLY);
        $target = new ZipArchive;

        if ($target->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $source->close();
            throw new RuntimeException("Unable to create temporary ESX archive at {$tempPath}.");
        }

        try {
            $replaced = false;
            for ($i = 0; $i < $source->numFiles; $i++) {
                $name = $source->getNameIndex($i);
                if ($name === false) {
                    continue;
                }

                if ($name === $member) {
                    $target->addFromString($member, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
                    $replaced = true;

                    continue;
                }

                $contents = $source->getFromIndex($i);
                if ($contents === false) {
                    continue;
                }
                $target->addFromString($name, $contents);
            }

            if (! $replaced) {
                $target->addFromString($member, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
            }
        } finally {
            $source->close();
            $target->close();
        }

        if (! rename($tempPath, $esxPath)) {
            @unlink($tempPath);
            throw new RuntimeException("Unable to replace ESX archive at {$esxPath}.");
        }
    }

    public function copy(string $sourcePath, string $destinationPath): string
    {
        $dir = dirname($destinationPath);
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Unable to create directory {$dir}.");
        }

        if (! copy($sourcePath, $destinationPath)) {
            throw new RuntimeException("Unable to copy ESX from {$sourcePath} to {$destinationPath}.");
        }

        return $destinationPath;
    }

    private function open(string $esxPath, int $flags): ZipArchive
    {
        $zip = new ZipArchive;
        $result = $zip->open($esxPath, $flags);
        if ($result !== true) {
            throw new RuntimeException("Unable to open ESX archive at {$esxPath} (code {$result}).");
        }

        return $zip;
    }
}
