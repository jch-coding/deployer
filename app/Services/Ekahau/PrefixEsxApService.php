<?php

namespace App\Services\Ekahau;

class PrefixEsxApService
{
    public function __construct(
        private EsxArchive $esxArchive,
        private ApNamePrefix $apNamePrefix,
    ) {}

    /**
     * @param  list<string>  $esxPaths
     * @return array{results: array<string, mixed>, output_paths: list<string>}
     */
    public function prefix(
        array $esxPaths,
        string $outputDir,
        string $prefixTemplate = '',
        string $prefixCustom = '',
        string $legacyPrefix = '',
    ): array {
        if ($prefixTemplate === '' && $legacyPrefix === '') {
            throw new \InvalidArgumentException('Provide either a prefix template or a flat prefix.');
        }

        $results = ['task' => 'prefix esx ap'];
        $outputPaths = [];

        foreach ($esxPaths as $esxPath) {
            $stem = pathinfo($esxPath, PATHINFO_FILENAME);
            $workingPath = rtrim($outputDir, '/').'/'.$stem.'.esx';
            $this->esxArchive->copy($esxPath, $workingPath);
            $fileResult = $this->prefixFile(
                $workingPath,
                $stem,
                $prefixTemplate,
                $prefixCustom,
                $legacyPrefix,
            );
            foreach ($fileResult as $key => $value) {
                if ($key === 'task') {
                    continue;
                }
                $results[$key] = $value;
            }
            $outputPaths[] = $workingPath;
        }

        return ['results' => $results, 'output_paths' => $outputPaths];
    }

    /**
     * @return array<string, mixed>
     */
    private function prefixFile(
        string $esxPath,
        string $siteName,
        string $prefixTemplate,
        string $prefixCustom,
        string $legacyPrefix,
    ): array {
        $results = [
            'task' => 'prefix esx ap',
            $siteName => ['success' => [], 'error' => [], 'skipped' => []],
        ];
        $floorPlans = $this->esxArchive->floorPlanIdToName($esxPath);

        $this->esxArchive->updateAccessPoints($esxPath, function (array $payload) use (
            $prefixTemplate,
            $prefixCustom,
            $legacyPrefix,
            $siteName,
            $floorPlans,
            &$results,
        ): array {
            foreach ($payload['accessPoints'] ?? [] as $index => $ap) {
                $name = (string) ($ap['name'] ?? '');
                $floor = '';
                if (isset($ap['location']['floorPlanId'])) {
                    $floor = $floorPlans[(string) $ap['location']['floorPlanId']] ?? '';
                }

                $prefix = $this->apNamePrefix->compute(
                    ['name' => $name, 'floor' => $floor],
                    $prefixTemplate,
                    $prefixCustom,
                    $siteName,
                    $legacyPrefix,
                );

                if ($prefix !== '' && str_starts_with($name, $prefix)) {
                    $results[$siteName]['skipped'][] = $name;

                    continue;
                }

                $newName = $prefix.$name;
                $payload['accessPoints'][$index]['name'] = $newName;
                $results[$siteName]['success'][] = $newName;
            }

            return $payload;
        });

        return $results;
    }
}
