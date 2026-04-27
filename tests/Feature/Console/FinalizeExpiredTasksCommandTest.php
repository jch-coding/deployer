<?php

use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\Task;
use Illuminate\Support\Facades\Artisan;

function expireTask(Task $task, int $minutesAgo = 10): void
{
    $task->timestamps = false;
    $task->update(['created_at' => now()->subMinutes($minutesAgo)]);
}

it('marks expired in-progress device task as failed when partially completed', function () {
    $task = Task::factory()->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 1,
    ]);
    expireTask($task);

    $deviceOne = Device::factory()->create();
    $deviceTwo = Device::factory()->create();
    $task->devices()->attach($deviceOne->id, ['status' => 'COMPLETED']);
    $task->devices()->attach($deviceTwo->id, ['status' => 'PENDING']);

    Artisan::call('tasks:finalize-expired');

    expect($task->fresh()->status)->toBe('FAILED');
});

it('marks expired in-progress interface task as failed when partially completed', function () {
    $task = Task::factory()->create([
        'task_type' => 'CONFIGURE_VLAN_INTERFACE',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 1,
    ]);
    expireTask($task);

    $interfaceOne = DeviceInterface::factory()->create();
    $interfaceTwo = DeviceInterface::factory()->create();
    $task->deviceInterfaces()->attach($interfaceOne->id, ['status' => 'COMPLETED']);
    $task->deviceInterfaces()->attach($interfaceTwo->id, ['status' => 'PENDING']);

    Artisan::call('tasks:finalize-expired');

    expect($task->fresh()->status)->toBe('FAILED');
});

it('keeps expired in-progress task unchanged when all tracked items are completed', function () {
    $task = Task::factory()->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 1,
    ]);
    expireTask($task);

    $deviceOne = Device::factory()->create();
    $deviceTwo = Device::factory()->create();
    $task->devices()->attach($deviceOne->id, ['status' => 'COMPLETED']);
    $task->devices()->attach($deviceTwo->id, ['status' => 'COMPLETED']);

    Artisan::call('tasks:finalize-expired');

    expect($task->fresh()->status)->toBe('IN_PROGRESS');
});

it('keeps non-expired in-progress tasks unchanged', function () {
    $task = Task::factory()->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 60,
    ]);

    $device = Device::factory()->create();
    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    Artisan::call('tasks:finalize-expired');

    expect($task->fresh()->status)->toBe('IN_PROGRESS');
});

it('ignores tasks already in terminal states', function () {
    $taskIdsByStatus = [];

    foreach (['FAILED', 'CANCELLED', 'COMPLETED'] as $status) {
        $task = Task::factory()->create([
            'task_type' => 'UPDATE_SYSTEM_INFO',
            'status' => $status,
            'deployment_time' => 1,
        ]);
        expireTask($task);
        $taskIdsByStatus[$status] = $task->id;

        $device = Device::factory()->create();
        $task->devices()->attach($device->id, ['status' => 'PENDING']);
    }

    Artisan::call('tasks:finalize-expired');

    foreach ($taskIdsByStatus as $expectedStatus => $taskId) {
        expect(Task::query()->findOrFail($taskId)->status)->toBe($expectedStatus);
    }
});
