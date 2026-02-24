<?php

namespace App\Helper;

class CSVHelper
{
    public static function processCSVFile($handle)
    {
        if(($file = fopen($handle, 'r')) !== false)
        {
            $data = [];
            while (($row = fgetcsv($file)) !== false) {
                array_push($data, $row);
            }
            fclose($file);
            return $data;
        }
        else
            return [];
    }

    public static function createDeviceArrays($CSVData)
    {
        if(empty($CSVData)) return [];
        $headers = $CSVData[0];
        $deviceArrays = [];
        foreach (array_slice($CSVData, 1) as $row) {
            $mappedRow = [];
            foreach (array_map(null, $headers, $row) as $key => [$header, $value])
                $mappedRow[$header] = $value;
            array_push($deviceArrays, $mappedRow);
        }
        return $deviceArrays;
    }
}
