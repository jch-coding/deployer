<?php

namespace App\Jobs\Concerns;

use Illuminate\Support\Facades\Log;
use Throwable;

trait HandlesUncaughtTaskExceptions
{
    protected function failTaskOnUnhandledException(Throwable $exception, string $context = ''): void
    {
        $message = sprintf(
            '%sUnhandled exception in %s%s: %s',
            $context !== '' ? $context.' - ' : '',
            static::class,
            property_exists($this, 'task') && isset($this->task?->id) ? ' for task '.$this->task->id : '',
            $exception->getMessage()
        );

        Log::error($message, ['exception' => $exception]);

        if (property_exists($this, 'task') && isset($this->task) && method_exists($this->task, 'processTaskStatusLog')) {
            $this->task->processTaskStatusLog($message, true);
            $this->task->update(['status' => 'FAILED']);
        }

        $this->fail($exception);
    }
}
