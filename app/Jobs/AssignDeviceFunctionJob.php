<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Task;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class AssignDeviceFunctionJob extends BaseTaskJob
{
    /**
     * Create a new job instance.
     */
    public function __construct(public array $devices, public string $device_function, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->initTaskTiming($task, defaultWaitMinutes: 1);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->handleSafely(function (): void {
            $device_serials = array_map(fn ($device) => $device['serial'], $this->devices);
            $response = $this->centralAPIHelper->assignDeviceFunction($device_serials, $this->device_function);

            if (is_array($response) || ! $response instanceof Response || ! $response->ok()) {
                $detail = $this->formatAssignDeviceFunctionError($response);
                Log::error($detail);
                $this->fail(is_string($detail) && $detail !== '' ? $detail : 'Assign device function failed');

                return;
            }

            foreach ($this->devices as $device) {
                $this->task->devices()->find($device['id'])?->pivot?->update(['status' => 'COMPLETED']);
            }

            $this->task->refresh();
            $status_log = $this->task->status_log;
            $new_log = array_reduce(
                $this->devices,
                fn (string $carry, array $device) => $carry."\nDevice ".$device['name'].' assigned to '.$this->device_function,
                $status_log
            );

            $this->task->load('devices');
            $payload = ['status_log' => $new_log];
            if ($this->task->allTrackedItemsCompleted()) {
                $payload['status'] = 'COMPLETED';
            }
            $this->task->update($payload);
        }, 'Assign device function');
    }

    private function formatAssignDeviceFunctionError(mixed $response): string
    {
        if (is_array($response)) {
            return $response['error'] ?? json_encode($response);
        }

        if ($response instanceof Response) {
            $json = $response->json();
            if (is_array($json) && isset($json['message'])) {
                return (string) $json['message'];
            }

            return $response->body();
        }

        return 'unknown error';
    }

    public function failed(?Throwable $exception): void
    {
        $this->logFailedException($exception);
        if ($this->devices !== [] && isset($this->devices[0]['id'])) {
            $this->failDeviceAndTaskIfNeeded($this->devices[0]['id']);
        }
    }
}
