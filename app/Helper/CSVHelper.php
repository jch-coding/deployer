<?php

namespace App\Helper;

class CSVHelper
{
    public static function processCSVFile($handle)
    {
        if (($file = fopen($handle, 'r')) !== false) {
            $data = [];
            while (($row = fgetcsv($file)) !== false) {
                $hasValue = array_filter($row, fn ($cell) => strlen(trim((string) $cell)) > 0);
                if ($hasValue !== []) {
                    array_push($data, $row);
                }
            }
            fclose($file);
            $data = self::fillInDeviceSerialAndDeviceFunction($data);

            return $data;
        } else {
            return [];
        }
    }

    public static function fillInDeviceSerialAndDeviceFunction($CSVData)
    {
        if (empty($CSVData) || ! is_array($CSVData[0] ?? null)) {
            return $CSVData;
        }

        $headers = array_values($CSVData[0]);
        $headerIndex = array_flip($headers);

        foreach (['name', 'serial', 'device_function'] as $column) {
            if (! array_key_exists($column, $headerIndex)) {
                return $CSVData;
            }
        }

        $nameCol = $headerIndex['name'];
        $serialCol = $headerIndex['serial'];
        $deviceFunctionCol = $headerIndex['device_function'];

        $nameToSerialAndFunction = [];

        foreach (array_slice($CSVData, 1) as $row) {
            $row = array_values(is_array($row) ? $row : []);
            $row = array_pad($row, count($headers), '');
            $name = trim((string) ($row[$nameCol] ?? ''));
            $serial = trim((string) ($row[$serialCol] ?? ''));
            $deviceFunction = trim((string) ($row[$deviceFunctionCol] ?? ''));

            if ($name === '' || $serial === '' || $deviceFunction === '') {
                continue;
            }

            $nameToSerialAndFunction[$name] = [
                'serial' => $serial,
                'device_function' => $deviceFunction,
            ];
        }

        for ($i = 1, $n = count($CSVData); $i < $n; $i++) {
            $row = array_values(is_array($CSVData[$i]) ? $CSVData[$i] : []);
            $row = array_pad($row, count($headers), '');
            $name = trim((string) ($row[$nameCol] ?? ''));

            if ($name === '' || ! isset($nameToSerialAndFunction[$name])) {
                continue;
            }

            $from = $nameToSerialAndFunction[$name];

            if (trim((string) ($row[$serialCol] ?? '')) === '') {
                $row[$serialCol] = $from['serial'];
            }
            if (trim((string) ($row[$deviceFunctionCol] ?? '')) === '') {
                $row[$deviceFunctionCol] = $from['device_function'];
            }

            $CSVData[$i] = $row;
        }

        return $CSVData;
    }

    public static function createDeviceArrays($CSVData)
    {
        if (empty($CSVData) || count($CSVData) == 1) {
            return [];
        }
        $headers = array_map(function ($header) {
            $normalized = str_replace('-', '_', trim((string) $header));

            return match ($normalized) {
                'interface_description' => 'description',
                'lag_id' => 'lacp_port_id',
                default => $normalized,
            };
        }, $CSVData[0]);
        $deviceArrays = [];
        foreach (array_slice($CSVData, 1) as $row) {
            $mappedRow = [];
            foreach (array_map(null, $headers, $row) as $key => [$header, $value]) {
                $mappedRow[$header] = $value;
            }
            array_push($deviceArrays, $mappedRow);
        }

        return $deviceArrays;
    }
}
