<?php

namespace App\Console\Commands;

use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FinalizeExpiredTasksCommand extends Command
{
    protected $signature = 'tasks:finalize-expired';

    protected $description = 'Mark expired in-progress tasks as timed out when items remain incomplete';

    public function handle(): int
    {
        $now = now();
        $timedOutCount = 0;

        Task::query()
            ->where('status', 'IN_PROGRESS')
            ->chunkById(100, function ($tasks) use (&$timedOutCount, $now) {
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

                    if ($totals['category'] === 'DEVICE') {
                        DB::table('device_task')
                            ->where('task_id', $task->id)
                            ->where('status', '!=', 'COMPLETED')
                            ->update(['status' => 'TIMED_OUT', 'updated_at' => $now]);
                    } elseif ($totals['category'] === 'INTERFACE') {
                        DB::table('device_interface_task')
                            ->where('task_id', $task->id)
                            ->where('status', '!=', 'COMPLETED')
                            ->update(['status' => 'TIMED_OUT', 'updated_at' => $now]);
                    }

                    $task->update(['status' => 'TIMED_OUT']);
                    $task->processTaskStatusLog($message, true);
                    $timedOutCount++;
                }
            });

        if ($timedOutCount > 0) {
            Log::info('tasks.finalize_expired', ['timed_out' => $timedOutCount]);
        }

        $this->info("Finalized {$timedOutCount} expired task(s).");

        return self::SUCCESS;
    }
}
