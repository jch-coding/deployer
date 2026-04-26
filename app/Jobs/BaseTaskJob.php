<?php

namespace App\Jobs;

use App\Jobs\Concerns\HandlesUncaughtTaskExceptions;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

abstract class BaseTaskJob implements ShouldQueue
{
    use Batchable, HandlesUncaughtTaskExceptions, Queueable;

    public int $tries = 1;

    protected function handleSafely(callable $callback, string $context = ''): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            $this->failTaskOnUnhandledException($exception, $context);
        }
    }

    protected function logFailedException(?Throwable $exception): void
    {
        if ($exception !== null) {
            Log::error($exception);
        }
    }

    protected function markDeviceFailed(mixed $device): void
    {
        $this->task->devices()->find($device)?->pivot?->update(['status' => 'FAILED']);
    }

    protected function markInterfaceFailed(mixed $deviceInterface): void
    {
        $this->task->deviceInterfaces()->find($deviceInterface)?->pivot?->update(['status' => 'FAILED']);
    }

    protected function markAllDevicesFailed(): void
    {
        $this->task->devices->each(fn ($device) => $device->pivot?->update(['status' => 'FAILED']));
    }

    protected function allTaskDevicesFailed(): bool
    {
        $devices = $this->task->devices;
        $total = $devices->count();
        if ($total === 0) {
            return false;
        }

        return $devices->filter(fn ($device) => $device->pivot->status === 'FAILED')->count() === $total;
    }

    protected function allTaskInterfacesFailed(?callable $totalFilter = null, ?callable $failedFilter = null): bool
    {
        /** @var Collection<int, mixed> $interfaces */
        $interfaces = $this->task->deviceInterfaces;
        $totalInterfaces = $totalFilter ? $interfaces->filter($totalFilter) : $interfaces;
        $failedInterfaces = $failedFilter
            ? $interfaces->filter($failedFilter)
            : $interfaces->filter(fn ($interface) => $interface->pivot->status === 'FAILED');

        $total = $totalInterfaces->count();
        if ($total === 0) {
            return false;
        }

        return $failedInterfaces->count() === $total;
    }

    protected function failTask(string $message = 'Task timed out or failed.', bool $withTimestamp = false): void
    {
        $this->task->update(['status' => 'FAILED']);
        $this->task->processTaskStatusLog($message, $withTimestamp);
    }

    protected function failDeviceAndTaskIfNeeded(mixed $device, string $taskMessage = 'Task timed out or failed.', bool $withTimestamp = false): void
    {
        $this->markDeviceFailed($device);

        if ($this->allTaskDevicesFailed()) {
            $this->failTask($taskMessage, $withTimestamp);
        }
    }

    protected function failInterfaceAndTaskIfNeeded(
        mixed $deviceInterface,
        ?callable $totalFilter = null,
        ?callable $failedFilter = null,
        string $taskMessage = 'Task timed out or failed.',
        bool $withTimestamp = false
    ): void {
        $this->markInterfaceFailed($deviceInterface);

        if ($this->allTaskInterfacesFailed($totalFilter, $failedFilter)) {
            $this->failTask($taskMessage, $withTimestamp);
        }
    }
}
