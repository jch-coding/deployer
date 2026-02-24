<?php

use App\Models\Deployment;
use App\Models\Device;

it('shows a list of devices associated with the deployment', function () {
    $deployment = Deployment::factory()->create();
    $devices = Device::factory(2)->for($deployment)->create();
    $this->actingAs($deployment->user);
    $this->get(route('deployments.show', $deployment))
        ->assertOk()
        ->assertSeeHtml($devices->first()->name)
        ->assertSeeHtml($devices->last()->name);
});

