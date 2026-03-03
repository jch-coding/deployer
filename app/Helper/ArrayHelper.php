<?php

namespace App\Helper;

class ArrayHelper
{
    public static function replace_keys(array $new_keys, array $array)
    {
        return array_combine($new_keys, array_values($array));
    }

    public static function replace_underscores_with_dashes(array $array)
    {
        return array_map(fn($v) => str_replace('_', '-', $v), $array);
    }

    public static function take_only_keys(array $only_keys, array $array)
    {
        return array_filter($array, fn($k) => in_array($k, $only_keys), ARRAY_FILTER_USE_KEY);
    }
}
