<?php

namespace App\Jobs;

use App\Events\DeploymentEvent;
use App\Events\TestEvent;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Throwable;

class TestJob implements ShouldQueue
{
    use Queueable, Batchable;

    /**
     * Create a new job instance.
     * $data = ['deployment_id', 'device_id', 'task_type']
     */
    public function __construct(public string|array $data)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        sleep(random_int(1,10));
//        TestEvent::dispatch($this->data);
        DeploymentEvent::dispatch($this->data);
    }

    public function failed(?Throwable $exception): void
    {
        echo $exception;
    }
}
