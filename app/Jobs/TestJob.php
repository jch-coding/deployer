<?php

namespace App\Jobs;

use App\Events\DeploymentEvent;
use App\Events\TestEvent;
use App\Models\Task;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use DateTime;

class TestJob implements ShouldQueue
{
    use Queueable, Batchable;

    private $deployment_time;
    private $wait_time;

    /**
     * Create a new job instance.
     * $data = ['deployment_name', 'task_id', 'item_name', 'message', 'task_type', 'retry_until']
     */
    public function __construct(public string|array $data)
    {
        if (is_array($data) && array_key_exists('deployment_time', $data)) {
            $this->deployment_time = $data['retry_until'];
        }
        $this->deployment_time= $this->deployment_time > 0 ? $this->deployment_time : 3;
        $this->wait_time = $data['wait_time'] ?? 2;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        sleep(random_int(1,10));
        $this->fail();
    }

    public function retryUntil(): DateTime
    {
        return now()->addMinutes($this->deployment_time)->toDateTime();
    }

    public function failed(?Throwable $exception): void
    {
        echo $exception;
        Artisan::call('queue:clear');
    }
}
