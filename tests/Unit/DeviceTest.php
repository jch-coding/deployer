<?php

use App\Models\Client;
use App\Models\Device;
use App\Models\Task;

it('is associated with a client', function () {
    $device = Device::factory()->create();
    $client = Client::factory()->create();

    $device->client()->associate($client);
    $device->save();

    expect($device->client_id)->toBe($client->id);
});

it('can belong to many tasks', function () {
    $device = Device::factory()->create();
    $task1 = Task::factory()->create();
    $task2 = Task::factory()->create();

    $device->tasks()->attach([$task1, $task2]);

    expect($device->tasks)->toHaveCount(2);
});
