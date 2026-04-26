<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Task;
use Illuminate\Support\Facades\Log;
use Throwable;

class AssignDeviceFunctionJob extends BaseTaskJob
{
    /**
     * Create a new job instance.
     */
    public function __construct(public array $devices, public string $device_function, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->initTaskTiming($task, defaultDeploymentMinutes: 3, defaultWaitMinutes: 1);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->handleSafely(function (): void {
            $device_serials = array_map(fn ($device) => $device['serial'], $this->devices);
            $response = $this->centralAPIHelper->assignDeviceFunction($device_serials, $this->device_function);
            if (! $response->ok()) {
                Log::error($response->json('message'));
                $this->fail();
            } else {
                array_map(fn ($device) => $this->task->devices()->find($device['id'])->pivot->update(['status' => 'COMPLETED']), $this->devices);
                $status_log = $this->task->status_log;
                array_reduce($this->devices, fn ($carry, $device) => $carry . "\nDevice " . $device['name'] . ' assigned to ' . $device['device_function'], $status_log);
                $this->task->update(['status_log' => $status_log]);
            }
        }, 'Assign device function');
    }

    public function failed(?Throwable $exception)
    {
        $this->logFailedException($exception);
        $this->failDeviceAndTaskIfNeeded($this->devices[0]['id']);
    }
}
