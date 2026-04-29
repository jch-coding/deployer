<?php

use App\Models\DeviceInterface;
use App\Models\LacpProfile;

it('can be associated to many interfaces', function () {
    $lacp_profile = LacpProfile::factory()->create();
    $interface1 = DeviceInterface::factory()->create(['lacp_profile_id' => $lacp_profile->id]);
    $interface2 = DeviceInterface::factory()->create(['lacp_profile_id' => $lacp_profile->id]);
    expect($lacp_profile->deviceInterfaces)->toHaveCount(2)
        ->and($lacp_profile->deviceInterfaces->contains($interface1))->toBeTrue()
        ->and($lacp_profile->deviceInterfaces->contains($interface2))->toBeTrue();
});
