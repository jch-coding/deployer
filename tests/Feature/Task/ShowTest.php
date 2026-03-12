<?php

use App\Models\Deployment;
use App\Models\Device;
use App\Models\Task;

test('the show tasks page lists all devices that are part of the task', function () {
    $deployment = Deployment::factory()->create();
    $devices = Device::factory(3)->for($deployment)->create();
    $task = Task::factory()->for($deployment)->create();
    $task->devices()->attach($devices);

    $this->actingAs($deployment->client->user);
    $this->get(route('tasks.show', $task))
        ->assertOk()
        ->assertSee($devices->first()->name)
        ->assertSee($devices->skip(1)->first()->name)
        ->assertSee($devices->last()->name);
});
