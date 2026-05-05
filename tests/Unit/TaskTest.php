<?php

use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\Task;
use App\Models\User;
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
    expect($user->tasks()->first()->pivot->status)->toBe('IN_PROGRESS');
});

it('can be assigned to a task type', function () {
    $task = Task::factory()->make(['task_type' => 'UPDATE_SYSTEM_INFO']);
    $task->save();
    expect($task->task_type)->toBeIn(array_map(fn ($taskType) => $taskType->name, TaskType::cases()));
});

it('resetIncompletePivotRowsToPending leaves completed rows and resets the rest', function () {
    $task = Task::factory()->create();
    $completedDevice = Device::factory()->create();
    $timedOutDevice = Device::factory()->create();
    $task->devices()->attach($completedDevice->id, ['status' => 'COMPLETED']);
    $task->devices()->attach($timedOutDevice->id, ['status' => 'TIMED_OUT']);

    $interface = DeviceInterface::factory()->for($completedDevice)->create();
    $task->deviceInterfaces()->attach($interface->id, ['status' => 'FAILED']);

    $task->resetIncompletePivotRowsToPending();

    expect($task->devices()->find($completedDevice->id)->pivot->status)->toBe('COMPLETED');
    expect($task->devices()->find($timedOutDevice->id)->pivot->status)->toBe('PENDING');
    expect($task->deviceInterfaces()->find($interface->id)->pivot->status)->toBe('PENDING');
});
