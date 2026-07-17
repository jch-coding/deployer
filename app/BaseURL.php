<?php

namespace App;

enum BaseURL : string
{
    case AE1 = 'ae1';
    case AU1 = 'au1';
    case CA1 = 'ca1';
    case DE1 = 'de1';
    case DE2 = 'de2';
    case DE3 = 'de3';
    case GB1 = 'gb1';
    case IN = 'in';
    case JP1 = 'jp1';
    case US1 = 'us1';
    case US2 = 'us2';
    case US4 = 'us4';
    case US5 = 'us5';
    case US6 = 'us6';

    public function toURL() : string
    {
        return "https://{$this->value}.api.central.arubanetworks.com/";
    }

    public function toClassicBaseUrl(): ?ClassicBaseUrl
    {
        return match ($this) {
            self::US1 => ClassicBaseUrl::US1,
            self::US2 => ClassicBaseUrl::US2,
            self::US4 => ClassicBaseUrl::US_WEST4,
            self::US5 => ClassicBaseUrl::US_WEST5,
            self::US6 => ClassicBaseUrl::US_EAST1,
            self::CA1 => ClassicBaseUrl::CANADA1,
            self::DE1 => ClassicBaseUrl::EU1,
            self::DE2 => ClassicBaseUrl::EU_CENTRAL2,
            self::DE3 => ClassicBaseUrl::EU_CENTRAL3,
            self::GB1 => ClassicBaseUrl::UK_WEST2,
            self::IN => ClassicBaseUrl::APAC1,
            self::JP1 => ClassicBaseUrl::APAC_EAST1,
            self::AU1 => ClassicBaseUrl::APAC_SOUTH1,
            self::AE1 => ClassicBaseUrl::UAE_NORTH1,
        };
    }
}
