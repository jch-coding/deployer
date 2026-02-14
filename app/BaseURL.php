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
}
