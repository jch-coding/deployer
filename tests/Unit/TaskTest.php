<?php


use App\Models\User;
use App\Models\Device;
use App\Models\Task;
use App\TaskStatus;
use App\TaskType;

it('can be associated with many users', function () {
    $task = Task::factory()->create();
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $task->users()->attach([$user1->id, $user2->id]);

    expect($task->users)->toHaveCount(2);
});

it('can be associated with many devices', function () {
    $task = Task::factory()->create();
    $device1 = Device::factory()->create();
    $device2 = Device::factory()->create();
    $task->devices()->attach([$device1, $device2]);
    expect($task->devices)->toHaveCount(2);
});

it('has a default status of in progress', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create();
    $user->tasks()->attach($task);
    expect($user->tasks()->first()->pivot->status)->toBe("IN_PROGRESS");
});

it('can be assigned to a task type', function () {
    $task = Task::factory()->make(['task_type' => 'UPDATE_SYSTEM_INFO']);
    $task->save();
    expect($task->task_type)->toBeIn(array_map(fn($taskType) => $taskType->name, TaskType::cases()));
});
