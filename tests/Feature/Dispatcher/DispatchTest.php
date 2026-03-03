<?php

use App\Models\Client;
use App\Models\Device;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use App\Jobs\UpdateSystemInfo;

beforeEach(function () {
    $this->user = User::factory()
        ->has(Client::factory())
        ->create();
    $this->client = $this->user->clients()->first();
    $this->client->update(['current' => true]);
});

test('a dispatcher can dispatch a job multiple times', function () {
    $this->withoutExceptionHandling();
    $task = Task::factory()->create(['name' => 'name_device', 'task_type' => 'UPDATE_SYSTEM_INFO']);
    $devices = Device::factory()->count(3)->create();
    $task->devices()->attach($devices);

    Queue::fake([UpdateSystemInfo::class]);
    $this->actingAs($this->user);
    $this->patch(route('dispatcher.dispatch', $task));
    Queue::assertPushedTimes(UpdateSystemInfo::class, 3);
});
