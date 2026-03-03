<?php

namespace App\Jobs;

use App\Events\TestEvent;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class TestJob implements ShouldQueue
{
    use Queueable, Batchable;

    /**
     * Create a new job instance.
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
        TestEvent::dispatch($this->data);
        $response = Http::get('https://swapi.info/api/people/1');
        if(!$response->ok())
            $this->fail();
        else
            TestEvent::dispatch($response->json());
    }
}
