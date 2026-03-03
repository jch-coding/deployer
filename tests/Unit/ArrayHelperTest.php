<?php

use App\Helper\ArrayHelper;

test('it returns a subset of keys from an array when using the take_only_keys method', function () {
   $array = ['a' => 1, 'b' => 2, 'c' => 3];
   $only = ['a', 'c'];
   $expected = ['a' => 1, 'c' => 3];
   $result = ArrayHelper::take_only_keys($only, $array);
   expect($result)->toBe($expected);
});

test('it replaces underscores with dashes when using the replace_underscores_with_dashes method', function () {
   $keys = ['a_b_c', 'd_e_f'];
   $expected = ['a-b-c', 'd-e-f'];
   $result = ArrayHelper::replace_underscores_with_dashes($keys);
   expect($result)->toBe($expected);
});

test('it replaces keys in an array with new keys when using the replace_keys method', function () {
   $new_keys = ['a', 'b'];
   $array = ['a_b_c' => 1, 'd_e_f' => 2];
   $expected = ['a' => 1, 'b' => 2];
   $result = ArrayHelper::replace_keys($new_keys, $array);
   expect($result)->toBe($expected);
});
