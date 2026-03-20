<?php

namespace App\Jobs;

use App\Events\DeploymentEvent;
use App\Events\TestEvent;
use App\Models\Task;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use DateTime;

class TestJob implements ShouldQueue
{
    use Queueable, Batchable;

    private $deployment_time;

    /**
     * Create a new job instance.
     * $data = ['deployment_name', 'task_id', 'item_name', 'message', 'task_type', 'retry_until']
     */
    public function __construct(public string|array $data)
    {
        if (is_array($data) && array_key_exists('deployment_time', $data)) {
            $this->deployment_time = $data['retry_until'];
        }
        $this->deployment_time= $this->deployment_time > 0 ? $this->deployment_time : 10;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        TestEvent::dispatch($this->data);
        sleep(random_int(1,10));
        DeploymentEvent::dispatch($this->data);
        $task = Task::find($this->data['task_id']);
        $task->devices()->find($this->data['device_name'])->pivot->update(['status' => 'COMPLETED']);
    }

    public function retryUntil(): DateTime
    {
        return now()->addMinutes($this->deployment_time)->toDateTime();
    }

    public function failed(?Throwable $exception): void
    {
        echo $exception;
    }
}
