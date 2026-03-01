<?php

use App\Http\Controllers\TaskController;
use App\Models\Device;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

it('queries Central for the device scope-id if the scope-id isn\'t found in the database', function () {
    $deviceController = new TaskController();
    $device = Device::factory()->create();

    Event::fake();
    Http::fake([
        '*.api.central.arubanetworks.com/*' => Http::response(['scope_id' => '1234567890'], 200),
    ]);

    $deviceController->config_system_info($device);
    Event::assertDispatched('devices.scope_id.' . $device->name);
    $this->assertDatabaseHas('devices', ['scope_id' => '1234567890']);
});

it("fires an event when retreiving the scope-id from Central", function () {

});

it('fires an even when pushing the system information to Central', function () {

});

it('updates the device status to complete after receiving an ok status from Central', function () {

});

it('updates the device status to failed after receiving a non-ok status from Central', function () {

});

