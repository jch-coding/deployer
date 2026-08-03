<?php

namespace App\Services\Ekahau;

use InvalidArgumentException;

class ApNamePrefix
{
    private const PLACEHOLDERS = ['filename', 'floor', 'custom'];

    /**
     * @param  array{name: string, floor?: string}  $ap
     */
    public function compute(
        array $ap,
        string $prefixTemplate = '',
        string $prefixCustom = '',
        string $filenameStem = '',
        string $legacyPrefix = '',
    ): string {
        if ($prefixTemplate !== '') {
            return $this->applyTemplate(
                $prefixTemplate,
                filename: $filenameStem,
                floor: $ap['floor'] ?? '',
                custom: $prefixCustom,
            );
        }

        return $legacyPrefix;
    }

    /**
     * @param  array{name: string, floor?: string}  $ap
     */
    public function buildPrefixedName(
        array $ap,
        string $prefixTemplate = '',
        string $prefixCustom = '',
        string $filenameStem = '',
        string $legacyPrefix = '',
    ): string {
        return $this->compute($ap, $prefixTemplate, $prefixCustom, $filenameStem, $legacyPrefix)
            .$ap['name'];
    }

    public function applyTemplate(string $template, string $filename, string $floor, string $custom): string
    {
        preg_match_all('/\{(\w+)\}/', $template, $matches);
        $unknown = array_diff($matches[1] ?? [], self::PLACEHOLDERS);
        if ($unknown !== []) {
            $list = implode(', ', array_unique($unknown));
            throw new InvalidArgumentException("Unknown placeholder(s) in ap_name_prefix_template: {$list}");
        }

        return preg_replace_callback(
            '/\{(filename|floor|custom)\}/',
            function (array $match) use ($filename, $floor, $custom): string {
                return match ($match[1]) {
                    'filename' => $filename,
                    'floor' => $floor,
                    'custom' => $custom,
                    default => $match[0],
                };
            },
            $template
        ) ?? $template;
    }

    public function sanitizeExcelSheetName(string $name): string
    {
        $sanitized = str_replace(['\\', '/', '?', '*', '[', ']'], '_', $name);

        return mb_substr($sanitized, 0, 31);
    }

    public function normalizeExportApModel(string $model): string
    {
        if (preg_match('/AP-\d+[^\s]*/i', $model, $matches) === 1) {
            return $matches[0];
        }

        return $model;
    }
}
