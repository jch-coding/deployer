<?php

use App\BaseURL;
use App\ClassicBaseUrl;

it('maps each base url region to the correct classic central gateway', function (BaseURL $baseUrl, ClassicBaseUrl $classicBaseUrl) {
    expect($baseUrl->toClassicBaseUrl())->toBe($classicBaseUrl);
})->with([
    'us1' => [BaseURL::US1, ClassicBaseUrl::US1],
    'us2' => [BaseURL::US2, ClassicBaseUrl::US2],
    'us4' => [BaseURL::US4, ClassicBaseUrl::US_WEST4],
    'us5' => [BaseURL::US5, ClassicBaseUrl::US_WEST5],
    'us6' => [BaseURL::US6, ClassicBaseUrl::US_EAST1],
    'ca1' => [BaseURL::CA1, ClassicBaseUrl::CANADA1],
    'de1' => [BaseURL::DE1, ClassicBaseUrl::EU1],
    'de2' => [BaseURL::DE2, ClassicBaseUrl::EU_CENTRAL2],
    'de3' => [BaseURL::DE3, ClassicBaseUrl::EU_CENTRAL3],
    'gb1' => [BaseURL::GB1, ClassicBaseUrl::UK_WEST2],
    'in' => [BaseURL::IN, ClassicBaseUrl::APAC1],
    'jp1' => [BaseURL::JP1, ClassicBaseUrl::APAC_EAST1],
    'au1' => [BaseURL::AU1, ClassicBaseUrl::APAC_SOUTH1],
    'ae1' => [BaseURL::AE1, ClassicBaseUrl::UAE_NORTH1],
]);
