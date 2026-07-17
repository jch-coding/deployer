<?php

namespace App;

enum DeviceFunction
{
    case BRANCH_GW;
    case CAMPUS_AP;
    case MICROBRANCH_AP;
    case VPNC;
    case MOBILITY_GW;
    case ACCESS_SWITCH;
    case CORE_SWITCH;
    case AGG_SWITCH;
    case AOSS_ACCESS_SWITCH;
    case AOSS_CORE_SWITCH;
    case AOSS_AGG_SWITCH;
    case SERVICE_PERSONA;
    case ALL;
}
