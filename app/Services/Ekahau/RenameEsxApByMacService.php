<?php

namespace App\Services\Ekahau;

class RenameEsxApByMacService
{
    public function __construct(
        private EsxArchive $esxArchive,
        private InstallerWorkbookReader $workbookReader,
    ) {}

    /**
     * @param  list<string>  $esxPaths
     * @return array{results: array<string, mixed>, output_paths: list<string>}
     */
    public function rename(
        array $esxPaths,
        string $mappingPath,
        ?string $sheetName,
        string $macColumn,
        string $nameColumn,
        bool $lowercase = false,
        string $outputDir = '',
    ): array {
        [$suffixToName, $ambiguous] = $this->workbookReader->createMacSuffixToNameDict(
            $mappingPath,
            $sheetName,
            $macColumn,
            $nameColumn,
            $lowercase,
        );

        return $this->renameWithSuffixMap($esxPaths, $suffixToName, $ambiguous, $outputDir);
    }

    /**
     * @param  list<string>  $esxPaths
     * @param  list<array{mac: string, name: string}>  $rows
     * @return array{results: array<string, mixed>, output_paths: list<string>}
     */
    public function renameFromMacNameRows(
        array $esxPaths,
        array $rows,
        bool $lowercase = false,
        string $outputDir = '',
    ): array {
        [$suffixToName, $ambiguous] = $this->workbookReader->createMacSuffixToNameDictFromRows(
            $rows,
            $lowercase,
        );

        return $this->renameWithSuffixMap($esxPaths, $suffixToName, $ambiguous, $outputDir);
    }

    /**
     * @param  list<string>  $esxPaths
     * @param  array<string, string>  $suffixToName
     * @param  list<string>  $ambiguous
     * @return array{results: array<string, mixed>, output_paths: list<string>}
     */
    private function renameWithSuffixMap(
        array $esxPaths,
        array $suffixToName,
        array $ambiguous,
        string $outputDir,
    ): array {
        $ambiguousSet = array_fill_keys($ambiguous, true);
        $results = ['task' => 'rename esx ap by mac'];
        $outputPaths = [];
        $dir = $outputDir !== '' ? $outputDir : dirname($esxPaths[0] ?? '.');

        foreach ($esxPaths as $esxPath) {
            $stem = pathinfo($esxPath, PATHINFO_FILENAME);
            $workingPath = $dir.'/'.$stem.'.esx';
            $this->esxArchive->copy($esxPath, $workingPath);
            $fileResult = $this->renameFile($workingPath, $stem, $suffixToName, $ambiguousSet);
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
     * @param  array<string, string>  $suffixToName
     * @param  array<string, true>  $ambiguousSet
     * @return array<string, mixed>
     */
    private function renameFile(
        string $esxPath,
        string $siteName,
        array $suffixToName,
        array $ambiguousSet,
    ): array {
        $results = [
            'task' => 'rename esx ap by mac',
            $siteName => ['success' => [], 'error' => []],
        ];
        $matchedSuffixes = [];

        $this->esxArchive->updateAccessPoints($esxPath, function (array $payload) use (
            $suffixToName,
            $ambiguousSet,
            &$results,
            $siteName,
            &$matchedSuffixes,
        ): array {
            $esxSuffixes = [];
            foreach ($payload['accessPoints'] ?? [] as $index => $ap) {
                $name = (string) ($ap['name'] ?? '');
                if (preg_match('/([0-9a-fA-F]{2}:[0-9a-fA-F]{2})\s*$/', $name, $match) !== 1) {
                    $results[$siteName]['error'][] = $name;

                    continue;
                }
                $suffix = $this->workbookReader->normalizeMacSuffix($match[1]);
                $esxSuffixes[$suffix][] = ['index' => $index, 'name' => $name];
            }

            foreach ($esxSuffixes as $suffix => $aps) {
                if (count($aps) > 1 || isset($ambiguousSet[$suffix]) || ! isset($suffixToName[$suffix])) {
                    foreach ($aps as $ap) {
                        $results[$siteName]['error'][] = $ap['name'];
                    }

                    continue;
                }

                $ap = $aps[0];
                $newName = $suffixToName[$suffix];
                $payload['accessPoints'][$ap['index']]['name'] = $newName;
                $matchedSuffixes[$suffix] = true;
                $results[$siteName]['success'][] = $newName;
            }

            return $payload;
        });

        foreach ($suffixToName as $suffix => $name) {
            if (! isset($matchedSuffixes[$suffix])) {
                $results[$siteName]['error'][] = $name;
            }
        }

        return $results;
    }
}
