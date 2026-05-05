<?php

namespace App;

enum InterfaceKind: string
{
    case ETHERNET = 'ETHERNET';
    case LAG = 'LAG';
    case VLAN = 'VLAN';
}
