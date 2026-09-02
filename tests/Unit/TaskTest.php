<?php

use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\Task;
use App\Models\User;
use App\TaskType;
use Carbon\Carbon;

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

it('sets expires_at on create from deployment_time', function () {
    $task = Task::factory()->create([
        'deployment_time' => 25,
    ]);

    expect($task->expires_at)->not->toBeNull()
        ->and($task->expiresAt()?->equalTo($task->created_at->copy()->addMinutes(25)))->toBeTrue();
});

it('prefers stored expires_at over created_at plus deployment_time', function () {
    $deadline = now()->addHours(2);

    $task = Task::factory()->create([
        'deployment_time' => 10,
        'expires_at' => $deadline,
        'created_at' => now()->subHour(),
    ]);

    $task->refresh();

    expect($task->expiresAt()?->equalTo($task->expires_at))->toBeTrue();
});

it('falls back to created_at plus deployment_time when expires_at is null', function () {
    $task = Task::factory()->create([
        'deployment_time' => 12,
        'created_at' => now()->subMinutes(30),
    ]);

    $task->timestamps = false;
    $task->update(['expires_at' => null]);

    $fresh = $task->fresh();

    expect($fresh->expiresAt()?->equalTo($fresh->created_at->copy()->addMinutes(12)))->toBeTrue();
});

it('refreshDeadlineFromDuration resets expires_at from now', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00', 'UTC'));

    $task = Task::factory()->create([
        'deployment_time' => 40,
        'expires_at' => now()->subMinutes(10),
    ]);

    $task->refreshDeadlineFromDuration();

    expect($task->fresh()->expiresAt()?->equalTo(now()->addMinutes(40)))->toBeTrue();

    Carbon::setTestNow();
});
