<?php

namespace App\Jobs;

use App\Events\DeploymentEvent;
use App\Events\TestEvent;
use App\Models\Task;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Throwable;
use DateTime;

class TestJob implements ShouldQueue
{
    use Queueable, Batchable;

    private $retryUntilMinutes;

    /**
     * Create a new job instance.
     * $data = ['deployment_id', 'task_id', 'device_id', 'task_type', 'retry_until']
     */
    public function __construct(public string|array $data)
    {
        if (is_array($data) && array_key_exists('retry_until', $data)) {
            $this->retryUntilMinutes = $data['retry_until'];
        }
        $this->retryUntilMinutes= $this->retryUntilMinutes ?? 1;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        TestEvent::dispatch($this->data);
        DeploymentEvent::dispatch($this->data);
        $task = Task::find($this->data['task_id']);
        $task->devices()->find($this->data['device_id'])->pivot->update(['status' => 'COMPLETED']);
        sleep(random_int(1,10));
    }

    public function retryUntil(): DateTime
    {
        return now()->addMinutes($this->retryUntilMinutes)->toDateTime();
    }

    public function failed(?Throwable $exception): void
    {
        echo $exception;
    }
}
