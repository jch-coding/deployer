<?php

use App\Support\CsvImportMergeHelper;

test('mergeCsvValue preserves existing when incoming is empty', function () {
    expect(CsvImportMergeHelper::mergeCsvValue('existing', null))->toBe('existing')
        ->and(CsvImportMergeHelper::mergeCsvValue('existing', ''))->toBe('existing')
        ->and(CsvImportMergeHelper::mergeCsvValue('existing', '   '))->toBe('existing');
});

test('mergeCsvValue allows null to non-null updates', function () {
    expect(CsvImportMergeHelper::mergeCsvValue(null, 'new'))->toBe('new');
});

test('mergeCsvValue allows non-null to non-null updates', function () {
    expect(CsvImportMergeHelper::mergeCsvValue('old', 'new'))->toBe('new');
});

test('mergeCsvValue is a no-op when values are equal', function () {
    expect(CsvImportMergeHelper::mergeCsvValue('same', 'same'))->toBe('same');
});

test('mergeOptionalFields applies null-safe rules per field', function () {
    $merged = CsvImportMergeHelper::mergeOptionalFields(
        ['group' => 'Keep-Me', 'sku' => null, 'vsx_profile' => 'pair-1'],
        ['group' => '', 'sku' => 'JL660A', 'vsx_profile' => 'pair-2'],
        ['group', 'sku', 'vsx_profile']
    );

    expect($merged['group'])->toBe('Keep-Me')
        ->and($merged['sku'])->toBe('JL660A')
        ->and($merged['vsx_profile'])->toBe('pair-2');
});
