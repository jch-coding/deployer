<?php

use App\Services\Ekahau\ApNamePrefix;
use App\Services\Ekahau\EsxArchive;
use App\Services\Ekahau\ExportEkahauApsService;
use App\Services\Ekahau\InstallerWorkbookReader;
use App\Services\Ekahau\PrefixEsxApService;
use App\Services\Ekahau\RenameEsxApByMacService;
use App\Services\Ekahau\RenameEsxApService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function ekahauFixturePath(string $name = 'sample.esx'): string
{
    return base_path('tests/Fixtures/ekahau/'.$name);
}

function writeInstallerWorkbook(string $path, array $rows, string $sheetName = 'Install'): void
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle($sheetName);
    $sheet->fromArray($rows, null, 'A1');
    (new Xlsx($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();
}

test('ap name prefix applies template placeholders', function () {
    $prefix = new ApNamePrefix;

    expect($prefix->applyTemplate('{filename}-{floor}-{custom}', 'Building', 'Floor 1', 'HQ'))
        ->toBe('Building-Floor 1-HQ');

    expect($prefix->normalizeExportApModel('AP-765 Omni'))->toBe('AP-765');
    expect($prefix->sanitizeExcelSheetName('a/b?c*d[e]f'))->toBe('a_b_c_d_e_f');
});

test('esx archive reads and rewrites access points cleanly', function () {
    $archive = new EsxArchive;
    $working = sys_get_temp_dir().'/ekahau-unit-'.uniqid().'.esx';
    copy(ekahauFixturePath(), $working);

    $aps = $archive->readAccessPoints($working);
    expect($aps)->toHaveCount(3);
    expect($aps[0]['floor'])->toBe('Floor 1');

    $archive->updateAccessPoints($working, function (array $payload): array {
        $payload['accessPoints'][0]['name'] = 'RENAMED-AP';

        return $payload;
    });

    $updated = $archive->readAccessPoints($working);
    expect($updated[0]['name'])->toBe('RENAMED-AP');

    // Ensure only one accessPoints.json member exists after rewrite
    $zip = new ZipArchive;
    $zip->open($working);
    $count = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        if ($zip->getNameIndex($i) === 'accessPoints.json') {
            $count++;
        }
    }
    $zip->close();
    expect($count)->toBe(1);

    unlink($working);
});

test('rename esx ap by unique excel mapping', function () {
    $excel = sys_get_temp_dir().'/installer-'.uniqid().'.xlsx';
    writeInstallerWorkbook($excel, [
        ['WAP location # on Drawing', 'New WAP Name', 'Site Bld Floor'],
        ['AP-A', 'FINAL-A', 'Floor 1'],
    ]);

    $workingEsx = sys_get_temp_dir().'/rename-src-'.uniqid().'.esx';
    $outputDir = sys_get_temp_dir().'/ekahau-out-'.uniqid();
    mkdir($outputDir);
    copy(ekahauFixturePath(), $workingEsx);

    $service = new RenameEsxApService(new EsxArchive, new InstallerWorkbookReader);
    $outcome = $service->rename(
        $workingEsx,
        $excel,
        'Install',
        'WAP location # on Drawing',
        'New WAP Name',
        'Site Bld Floor',
        false,
        $outputDir,
    );

    $stem = pathinfo($workingEsx, PATHINFO_FILENAME);
    expect($outcome['results'][$stem]['success'])->toContain('FINAL-A');

    $renamed = (new EsxArchive)->readAccessPoints($outcome['output_path']);
    expect(collect($renamed)->pluck('name'))->toContain('FINAL-A');

    unlink($excel);
    unlink($workingEsx);
    @unlink($outcome['output_path']);
    @rmdir($outputDir);
});

test('rename esx ap by mac suffix', function () {
    $excel = sys_get_temp_dir().'/bssid-'.uniqid().'.xlsx';
    writeInstallerWorkbook($excel, [
        ['raw_mac', 'ap_name'],
        ['aa:bb:cc:dd:ab:cd', 'MATCHED-1'],
        ['11:22:33:44:11:22', 'MATCHED-2'],
    ], 'Sheet1');

    $workingEsx = sys_get_temp_dir().'/mac-src-'.uniqid().'.esx';
    $outputDir = sys_get_temp_dir().'/ekahau-mac-out-'.uniqid();
    mkdir($outputDir);
    copy(ekahauFixturePath(), $workingEsx);

    $service = new RenameEsxApByMacService(new EsxArchive, new InstallerWorkbookReader);
    $outcome = $service->rename(
        [$workingEsx],
        $excel,
        null,
        'raw_mac',
        'ap_name',
        false,
        $outputDir,
    );

    $stem = pathinfo($workingEsx, PATHINFO_FILENAME);
    expect($outcome['results'][$stem]['success'])->toContain('MATCHED-1', 'MATCHED-2');

    unlink($excel);
    unlink($workingEsx);
    foreach ($outcome['output_paths'] as $path) {
        @unlink($path);
    }
    @rmdir($outputDir);
});

test('export and prefix services produce expected outputs', function () {
    $workingEsx = sys_get_temp_dir().'/export-src-'.uniqid().'.esx';
    $outputDir = sys_get_temp_dir().'/ekahau-export-out-'.uniqid();
    mkdir($outputDir);
    copy(ekahauFixturePath(), $workingEsx);

    $export = new ExportEkahauApsService(new EsxArchive, new ApNamePrefix);
    $exported = $export->export([$workingEsx], $outputDir, legacyPrefix: 'SITE-');
    expect($exported['output_paths'][0])->toEndWith('_aps.xlsx');
    expect(is_file($exported['output_paths'][0]))->toBeTrue();

    $prefix = new PrefixEsxApService(new EsxArchive, new ApNamePrefix);
    $prefixed = $prefix->prefix([$workingEsx], $outputDir, legacyPrefix: 'HQ-');
    $aps = (new EsxArchive)->readAccessPoints($prefixed['output_paths'][0]);
    expect($aps[0]['name'])->toStartWith('HQ-');

    // Idempotent skip on second run
    $again = $prefix->prefix([$prefixed['output_paths'][0]], $outputDir.'/again', legacyPrefix: 'HQ-');
    $stem = pathinfo($prefixed['output_paths'][0], PATHINFO_FILENAME);
    expect($again['results'][$stem]['skipped'])->not->toBeEmpty();

    unlink($workingEsx);
    @unlink($exported['output_paths'][0]);
    foreach ($prefixed['output_paths'] as $path) {
        @unlink($path);
    }
    foreach ($again['output_paths'] as $path) {
        @unlink($path);
    }
    @rmdir($outputDir.'/again');
    @rmdir($outputDir);
});

test('mac suffix normalization uses last four hex digits', function () {
    $reader = new InstallerWorkbookReader;
    expect($reader->normalizeMacSuffix('aa:bb:cc:dd:ab:cd'))->toBe('abcd');
    expect($reader->normalizeMacSuffix('ABCD'))->toBe('abcd');
});
