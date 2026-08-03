<?php

namespace App\Services\Ekahau;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExportEkahauApsService
{
    public function __construct(
        private EsxArchive $esxArchive,
        private ApNamePrefix $apNamePrefix,
    ) {}

    /**
     * @param  list<string>  $esxPaths
     * @return array{results: array<string, mixed>, output_paths: list<string>}
     */
    public function export(
        array $esxPaths,
        string $outputDir,
        string $prefixTemplate = '',
        string $prefixCustom = '',
        string $legacyPrefix = '',
    ): array {
        $results = ['task' => 'export ekahau aps'];
        $outputPaths = [];

        foreach ($esxPaths as $esxPath) {
            $stem = pathinfo($esxPath, PATHINFO_FILENAME);
            $outputPath = rtrim($outputDir, '/').'/'.$stem.'_aps.xlsx';
            $this->exportFile(
                $esxPath,
                $outputPath,
                $stem,
                $prefixTemplate,
                $prefixCustom,
                $legacyPrefix,
            );
            $outputPaths[] = $outputPath;
            $results[$stem] = ['success' => [$outputPath], 'error' => []];
        }

        return ['results' => $results, 'output_paths' => $outputPaths];
    }

    private function exportFile(
        string $esxPath,
        string $outputPath,
        string $filenameStem,
        string $prefixTemplate,
        string $prefixCustom,
        string $legacyPrefix,
    ): void {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->apNamePrefix->sanitizeExcelSheetName($filenameStem));
        $sheet->fromArray(['AP Name', 'Model', 'Serial'], null, 'A1');

        $rowIndex = 2;
        foreach ($this->esxArchive->readAccessPoints($esxPath) as $ap) {
            $name = $this->apNamePrefix->buildPrefixedName(
                $ap,
                $prefixTemplate,
                $prefixCustom,
                $filenameStem,
                $legacyPrefix,
            );
            $sheet->fromArray([
                $name,
                $this->apNamePrefix->normalizeExportApModel($ap['model']),
                '',
            ], null, 'A'.$rowIndex);
            $rowIndex++;
        }

        foreach (range('A', 'C') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $dir = dirname($outputPath);
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Unable to create directory {$dir}.");
        }

        (new Xlsx($spreadsheet))->save($outputPath);
        $spreadsheet->disconnectWorksheets();
    }
}
