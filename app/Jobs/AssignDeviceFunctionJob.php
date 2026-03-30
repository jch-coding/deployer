<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Task;
use DateTime;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class AssignDeviceFunctionJob implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * Create a new job instance.
     */
    public int $deployment_time;
    public int $wait_time;

    public function __construct(public array $devices, public string $device_function, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->deployment_time = $task->deployment_time > 0 ? $task->deployment_time : 3;
        $this->wait_time = $task->wait_time ?? 1;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $device_serials = array_map(fn ($device) => $device['serial'], $this->devices);
        $response = $this->centralAPIHelper->assignDeviceFunction($device_serials, $this->device_function);
        if (! $response->ok()) {
            Log::error($response->json('message'));
            $this->fail();
        }
        else {
            array_map(fn($device) => $this->task->devices()->find($device['id'])->pivot->update(['status' => 'COMPLETED']), $this->devices);
            $status_log = $this->task->status_log;
            array_reduce($this->devices, fn($carry, $device) => $carry . "\nDevice " . $device['name'] . ' assigned to ' . $device['device_function'], $status_log);
            $this->task->update(['status_log' => $status_log]);
        }
    }

    public function retryUntil(): DateTime
    {
        return now()->addMinutes($this->deployment_time)->toDateTime();
    }

    public function failed(?Throwable $exception)
    {
        Log::error($exception);
        $this->task->devices()->find($this->devices[0]['id'])->pivot->update(['status' => 'FAILED']);
        $this->release($this->wait_time * 60);
    }
}
