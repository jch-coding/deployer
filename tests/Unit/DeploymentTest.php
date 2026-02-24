<?php

use App\Models\Client;
use App\Models\Device;
use App\Models\Deployment;
use App\Models\Task;
use App\Models\User;

it('can be associated with many devices', function () {
    $deployment = Deployment::factory()->create();
    Device::factory(2)->for($deployment)->create();
    expect($deployment->refresh()->devices)->toHaveCount(2);
});

it('has tasks through it\'s devices', function () {
    $deployment = Deployment::factory()->create();
    $device1 = Device::factory()->for($deployment)->create();
    $device2 = Device::factory()->for($deployment)->create();
    $task1 = Task::factory()->create();
    $task2 = Task::factory()->create();
    $task1->devices()->attach($device1);
    $task2->devices()->attach($device2);
    $tasks = $deployment->getTasks();
    expect($tasks)
        ->toHaveCount(2)
        ->and($tasks->first()->id)->toBe($task1->id)
        ->and($tasks->last()->id)->toBe($task2->id);
});

it('has a unique collection of tasks through it\'s devices', function () {
   $deployment = Deployment::factory()->create();
   $devices = Device::factory(2)->for($deployment)->create();
   $task = Task::factory()->create();
   $task->devices()->attach([$devices[0]->id, $devices[1]->id]);
   expect($deployment
       ->getTasks())
       ->toHaveCount(1)
       ->and($deployment->getTasks()->first()->id)->toBe($task->id);
});

it('belongs to a client', function () {
    $client = Client::factory()->create();
    Deployment::factory()->for($client)->create();
    $this->assertDatabaseHas('deployments', ['client_id' => $client->id]);
});
