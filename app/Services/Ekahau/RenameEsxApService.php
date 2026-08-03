<?php

namespace App\Services\Ekahau;

class RenameEsxApService
{
    public function __construct(
        private EsxArchive $esxArchive,
        private InstallerWorkbookReader $workbookReader,
    ) {}

    /**
     * @return array{results: array<string, mixed>, output_path: string}
     */
    public function rename(
        string $esxPath,
        string $excelPath,
        string $sheetName,
        string $esxApColumn,
        string $apNameColumn,
        string $siteNameColumn = '',
        bool $lowercase = false,
        string $outputDir = '',
    ): array {
        $uniqueness = $this->workbookReader->esxApNamesAreUnique($excelPath, $sheetName, $esxApColumn);

        $stem = pathinfo($esxPath, PATHINFO_FILENAME);
        $dir = $outputDir !== '' ? $outputDir : dirname($esxPath);

        if ($uniqueness['unique']) {
            $workingPath = $dir.'/'.$stem.'.esx';
            $this->esxArchive->copy($esxPath, $workingPath);
            $naming = $this->workbookReader->createApNamingDict(
                $excelPath,
                $sheetName,
                $esxApColumn,
                $apNameColumn,
                $siteNameColumn,
                $lowercase,
                floorDependent: false,
            );

            /** @var array<string, string> $naming */
            $results = $this->renameUnique($workingPath, $stem, $naming);

            return ['results' => $results, 'output_path' => $workingPath];
        }

        $workingPath = $dir.'/'.$stem.' - Copy.esx';
        $this->esxArchive->copy($esxPath, $workingPath);
        $naming = $this->workbookReader->createApNamingDict(
            $excelPath,
            $sheetName,
            $esxApColumn,
            $apNameColumn,
            $siteNameColumn,
            $lowercase,
            floorDependent: true,
        );

        /** @var array<string, array<string, string>> $naming */
        $results = $this->renameFloorDependent($workingPath, $stem, $naming);

        return ['results' => $results, 'output_path' => $workingPath];
    }

    /**
     * @param  array<string, string>  $naming
     * @return array<string, mixed>
     */
    private function renameUnique(string $esxPath, string $siteName, array $naming): array
    {
        $results = [
            'task' => 'rename esx ap',
            $siteName => ['success' => [], 'error' => []],
        ];

        $this->esxArchive->updateAccessPoints($esxPath, function (array $payload) use ($naming, &$results, $siteName): array {
            foreach ($payload['accessPoints'] ?? [] as $index => $ap) {
                $name = (string) ($ap['name'] ?? '');
                if (isset($naming[$name])) {
                    $payload['accessPoints'][$index]['name'] = $naming[$name];
                    $results[$siteName]['success'][] = $naming[$name];
                }
            }

            return $payload;
        });

        foreach ($naming as $newName) {
            if (! in_array($newName, $results[$siteName]['success'], true)) {
                $results[$siteName]['error'][] = $newName;
            }
        }

        return $results;
    }

    /**
     * @param  array<string, array<string, string>>  $naming
     * @return array<string, mixed>
     */
    private function renameFloorDependent(string $esxPath, string $siteName, array $naming): array
    {
        $results = [
            'task' => 'rename esx ap',
            $siteName => ['success' => [], 'error' => []],
        ];
        $floorPlans = $this->esxArchive->floorPlanIdToName($esxPath);

        $this->esxArchive->updateAccessPoints($esxPath, function (array $payload) use ($naming, $floorPlans, &$results, $siteName): array {
            foreach ($payload['accessPoints'] ?? [] as $index => $ap) {
                $name = (string) ($ap['name'] ?? '');
                $floorId = $ap['location']['floorPlanId'] ?? null;
                if ($floorId === null) {
                    $results[$siteName]['error'][] = $name;

                    continue;
                }

                $floorName = $floorPlans[(string) $floorId] ?? null;
                if ($floorName === null || ! isset($naming[$floorName][$name])) {
                    $results[$siteName]['error'][] = $name;

                    continue;
                }

                $newName = $naming[$floorName][$name];
                $payload['accessPoints'][$index]['name'] = $newName;
                $results[$siteName]['success'][] = $newName;
            }

            return $payload;
        });

        foreach ($naming as $floorAps) {
            foreach ($floorAps as $newName) {
                if (! in_array($newName, $results[$siteName]['success'], true)) {
                    $results[$siteName]['error'][] = $newName;
                }
            }
        }

        return $results;
    }
}
