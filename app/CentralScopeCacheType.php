<?php

namespace App;

enum CentralScopeCacheType: string
{
    case Sites = 'sites';
    case Groups = 'groups';
    case MacRegistrations = 'mac_registrations';
}
