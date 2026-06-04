<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Task;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class UnassignSubscriptionJob extends BaseTaskJob
{
    private const SERIALS_PER_REQUEST = 25;

    public function __construct(public array $devices, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->initTaskTiming($task, defaultDeploymentMinutes: 3, defaultWaitMinutes: 1);
    }

    public function handle(): void
    {
        $this->handleSafely(function (): void {
            $this->unassignSubscriptions();
        }, 'Unassign subscription');
    }

    public function unassignSubscriptions(): void
    {
        $serviceName = trim((string) ($this->task->licensing_service_name ?? ''));
        if ($serviceName === '') {
            $message = 'No licensing service name configured for this task.';
            Log::error($message);
            $this->task->processTaskStatusLog($message);

            return;
        }

        $chunks = array_chunk($this->devices, self::SERIALS_PER_REQUEST);

        foreach ($chunks as $chunk) {
            $serials = array_map(fn ($device) => (string) $device['serial'], $chunk);
            $response = $this->centralAPIHelper->classic_unassign_subscription($serials, $serviceName);
            $ok = ! is_array($response) && $response instanceof Response && $response->ok();

            if ($ok) {
                foreach ($chunk as $device) {
                    $this->task->devices()->find($device['id'])?->pivot?->update(['status' => 'COMPLETED']);
                }
                $message = array_reduce(
                    $serials,
                    fn (string $carry, string $serial) => $carry."\nUnassigned subscription service {$serviceName} from device {$serial}",
                    ''
                );
                $this->task->processTaskStatusLog($message);
            } else {
                foreach ($chunk as $device) {
                    $this->markDeviceFailed($device['id']);
                }
                $errorDetail = $this->formatSubscriptionError($response);
                Log::error('Failed to unassign subscription with error '.$errorDetail);
                $this->task->processTaskStatusLog("\nFailed to unassign subscription for service {$serviceName}: {$errorDetail}");
            }
        }

        $this->task->load('devices');

        if ($this->task->allTrackedItemsCompleted()) {
            $this->task->update(['status' => 'COMPLETED']);
        } elseif ($this->allTaskDevicesFailed()) {
            $this->failTask('All devices failed subscription unassignment.');
        }
    }

    private function formatSubscriptionError(mixed $response): string
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
        $this->markAllDevicesFailed();
        $this->failTask('Failed unassigning subscriptions. Task timed out or failed.');
    }
}
