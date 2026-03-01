<?php

namespace App;

enum TaskType
{
    case CONFIGURE_ETHERNET_INTERFACE;
    case UPDATE_SYSTEM_INFO;
    case CONFIGURE_LAG_INTERFACE;
    case CONFIGURE_VLAN_INTERFACE;
}
