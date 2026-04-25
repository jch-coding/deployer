<?php

use App\Models\Client;
use App\Models\Task;
use App\Models\User;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;

class BatchCancellationTestJob implements ShouldQueue
{
    use Queueable, Batchable;

    public function handle(): void
    {
        // No-op job used to create a batch for cancellation assertions.
    }
}

beforeEach(function () {
    $this->user = User::factory()
        ->has(Client::factory())
        ->create();
    $this->client = $this->user->clients()->first();
    $this->client->update(['current' => true]);
    $this->deployment = $this->client->deployments()->for($this->client)->create(['name' => 'Test Deployment']);
    $this->actingAs($this->user);
});

test('cancelling a task cancels its dispatched batch', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'status' => 'IN_PROGRESS',
    ]);

    $batch = Bus::batch([
        new BatchCancellationTestJob(),
    ])->dispatch();

    $task->update(['batch_id' => $batch->id]);

    $this->patch(route('tasks.cancel', $task))
        ->assertRedirect();

    $task->refresh();
    expect($task->status)->toBe('CANCELLED');
    expect(Bus::findBatch($batch->id)?->cancelled())->toBeTrue();
});
