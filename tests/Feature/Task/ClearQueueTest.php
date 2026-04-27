<?php

use App\Models\Client;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->user = User::factory()
        ->has(Client::factory())
        ->create();
    $this->client = $this->user->clients()->first();
    $this->client->update(['current' => true]);
    $this->deployment = $this->client->deployments()->create(['name' => 'Test Deployment']);
    $this->actingAs($this->user);
});

test('clear queue retries until output confirms queue is empty', function () {
    $task = Task::factory()->recycle($this->deployment)->create([
        'status' => 'IN_PROGRESS',
    ]);

    Artisan::shouldReceive('call')
        ->times(2)
        ->with('queue:clear')
        ->andReturn(0);

    Artisan::shouldReceive('output')
        ->times(2)
        ->andReturn(
            'Cleared [3] jobs from the [default] queue.',
            'No messages were deleted from the [default] queue.'
        );

    $this->post(route('tasks.clear_queue', $task))
        ->assertRedirect(route('tasks.show', $task))
        ->assertSessionHas('success', 'Queue is clear. No pending jobs remain.');
});

test('clear queue sets error flash when queue cannot be confirmed clear', function () {
    $task = Task::factory()->recycle($this->deployment)->create([
        'status' => 'IN_PROGRESS',
    ]);

    Artisan::shouldReceive('call')
        ->times(5)
        ->with('queue:clear')
        ->andReturn(0);

    Artisan::shouldReceive('output')
        ->times(5)
        ->andReturn('Cleared [1] jobs from the [default] queue.');

    $this->post(route('tasks.clear_queue', $task))
        ->assertRedirect(route('tasks.show', $task))
        ->assertSessionHas('error', 'Unable to confirm queue is clear after 5 attempts. Last output: Cleared [1] jobs from the [default] queue.');
});

test('clear queue succeeds when command reports cleared zero jobs', function () {
    $task = Task::factory()->recycle($this->deployment)->create([
        'status' => 'IN_PROGRESS',
    ]);

    Artisan::shouldReceive('call')
        ->once()
        ->with('queue:clear')
        ->andReturn(0);

    Artisan::shouldReceive('output')
        ->once()
        ->andReturn('Cleared [0] jobs from the [default] queue.');

    $this->post(route('tasks.clear_queue', $task))
        ->assertRedirect(route('tasks.show', $task))
        ->assertSessionHas('success', 'Queue cleared successfully.');
});

test('clear queue succeeds when command reports info cleared zero jobs format', function () {
    $task = Task::factory()->recycle($this->deployment)->create([
        'status' => 'IN_PROGRESS',
    ]);

    Artisan::shouldReceive('call')
        ->once()
        ->with('queue:clear')
        ->andReturn(0);

    Artisan::shouldReceive('output')
        ->once()
        ->andReturn('INFO  Cleared 0 jobs from the [default] queue.');

    $this->post(route('tasks.clear_queue', $task))
        ->assertRedirect(route('tasks.show', $task))
        ->assertSessionHas('success', 'Queue cleared successfully.');
});
