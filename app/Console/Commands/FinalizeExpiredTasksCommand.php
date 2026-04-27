<?php

namespace App\Console\Commands;

use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FinalizeExpiredTasksCommand extends Command
{
    protected $signature = 'tasks:finalize-expired';

    protected $description = 'Mark expired in-progress tasks as failed when items remain incomplete';

    public function handle(): int
    {
        $now = now();
        $failedCount = 0;

        Task::query()
            ->where('status', 'IN_PROGRESS')
            ->chunkById(100, function ($tasks) use (&$failedCount, $now) {
                foreach ($tasks as $task) {
                    $expiresAt = $task->expiresAt();
                    if ($expiresAt === null || $expiresAt->isFuture()) {
                        continue;
                    }

                    if ($task->allTrackedItemsCompleted()) {
                        continue;
                    }

                    $totals = $task->trackedItemTotals();
                    $message = sprintf(
                        'Task expired before completion (%d/%d completed).',
                        $totals['completed'],
                        $totals['total']
                    );

                    $task->update(['status' => 'FAILED']);
                    $task->processTaskStatusLog($message, true);
                    $failedCount++;
                }
            });

        if ($failedCount > 0) {
            Log::info('tasks.finalize_expired', ['failed' => $failedCount]);
        }

        $this->info("Finalized {$failedCount} expired task(s).");

        return self::SUCCESS;
    }
}
