<?php

namespace App\Services\Ekahau;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

class InstallerWorkbookReader
{
    /**
     * Normalize header names that may use literal "\n" escape sequences from form input.
     */
    public function normalizeHeader(string $header): string
    {
        return str_replace('\\n', "\n", $header);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function readRows(string $path, ?string $sheetName = null): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'csv') {
            return $this->readCsv($path);
        }

        return $this->readSpreadsheet($path, $sheetName);
    }

    /**
     * @return array{unique: bool, names: list<string>}
     */
    public function esxApNamesAreUnique(string $path, string $sheetName, string $esxApColumn): array
    {
        $esxApColumn = $this->normalizeHeader($esxApColumn);
        $rows = $this->readRows($path, $sheetName);
        $names = [];

        foreach ($rows as $row) {
            $value = $this->cellString($row, $esxApColumn);
            if ($value === '') {
                continue;
            }
            $names[] = $value;
        }

        $unique = count($names) === count(array_unique($names));

        return ['unique' => $unique, 'names' => $names];
    }

    /**
     * @return array<string, string>|array<string, array<string, string>>
     */
    public function createApNamingDict(
        string $path,
        string $sheetName,
        string $esxApColumn,
        string $apNameColumn,
        string $siteNameColumn = '',
        bool $lowercase = false,
        bool $floorDependent = false,
    ): array {
        $esxApColumn = $this->normalizeHeader($esxApColumn);
        $apNameColumn = $this->normalizeHeader($apNameColumn);
        $siteNameColumn = $this->normalizeHeader($siteNameColumn);
        $rows = $this->readRows($path, $sheetName);
        $dict = [];

        foreach ($rows as $row) {
            $finalName = $this->cellString($row, $apNameColumn);
            if ($finalName === '') {
                continue;
            }
            if ($lowercase) {
                $finalName = mb_strtolower($finalName);
            }

            $esxName = $this->cellString($row, $esxApColumn);
            if ($esxName === '') {
                continue;
            }

            if ($floorDependent) {
                $site = $this->cellString($row, $siteNameColumn);
                if ($site === '') {
                    continue;
                }
                $dict[$site] ??= [];
                $dict[$site][$esxName] = $finalName;
            } else {
                $dict[$esxName] = $finalName;
            }
        }

        return $dict;
    }

    /**
     * @return array{0: array<string, string>, 1: list<string>}
     */
    public function createMacSuffixToNameDict(
        string $path,
        ?string $sheetName,
        string $macColumn,
        string $nameColumn,
        bool $lowercase = false,
    ): array {
        $macColumn = $this->normalizeHeader($macColumn);
        $nameColumn = $this->normalizeHeader($nameColumn);
        $rows = $this->readRows($path, $sheetName);
        $suffixToName = [];
        $ambiguous = [];

        foreach ($rows as $row) {
            $name = $this->cellString($row, $nameColumn);
            if ($name === '') {
                continue;
            }
            if ($lowercase) {
                $name = mb_strtolower($name);
            }

            $suffix = $this->normalizeMacSuffix($this->cellString($row, $macColumn));
            if ($suffix === '') {
                continue;
            }

            if (isset($suffixToName[$suffix]) && $suffixToName[$suffix] !== $name) {
                $ambiguous[$suffix] = true;
            } else {
                $suffixToName[$suffix] = $name;
            }
        }

        foreach (array_keys($ambiguous) as $suffix) {
            unset($suffixToName[$suffix]);
        }

        return [$suffixToName, array_keys($ambiguous)];
    }

    public function normalizeMacSuffix(string $value): string
    {
        $hexOnly = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $value) ?? '');

        return strlen($hexOnly) >= 4 ? substr($hexOnly, -4) : '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readSpreadsheet(string $path, ?string $sheetName): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $sheetName !== null && $sheetName !== ''
            ? $spreadsheet->getSheetByName($sheetName)
            : $spreadsheet->getSheet(0);

        if (! $sheet instanceof Worksheet) {
            throw new RuntimeException($sheetName
                ? "Sheet \"{$sheetName}\" not found in workbook."
                : 'Workbook has no sheets.');
        }

        $rows = $sheet->toArray(null, true, true, false);
        if ($rows === []) {
            return [];
        }

        $headers = array_map(fn ($h) => $this->stringifyHeader($h), array_shift($rows) ?? []);
        $result = [];

        foreach ($rows as $row) {
            $assoc = [];
            $empty = true;
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $value = $row[$index] ?? null;
                if ($value !== null && $value !== '') {
                    $empty = false;
                }
                $assoc[$header] = $value;
            }
            if (! $empty) {
                $result[] = $assoc;
            }
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Unable to open CSV at {$path}.");
        }

        try {
            $headers = fgetcsv($handle);
            if ($headers === false) {
                return [];
            }
            $headers = array_map(fn ($h) => $this->stringifyHeader($h), $headers);
            $result = [];

            while (($row = fgetcsv($handle)) !== false) {
                $assoc = [];
                $empty = true;
                foreach ($headers as $index => $header) {
                    if ($header === '') {
                        continue;
                    }
                    $value = $row[$index] ?? null;
                    if ($value !== null && $value !== '') {
                        $empty = false;
                    }
                    $assoc[$header] = $value;
                }
                if (! $empty) {
                    $result[] = $assoc;
                }
            }

            return $result;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function cellString(array $row, string $column): string
    {
        if (! array_key_exists($column, $row) || $row[$column] === null) {
            return '';
        }

        return trim((string) $row[$column]);
    }

    private function stringifyHeader(mixed $header): string
    {
        if ($header === null) {
            return '';
        }

        return (string) $header;
    }
}
