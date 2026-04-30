<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FinalizeExpiredTasksService
{
    /**
     * Finalize expired in-progress tasks (FAILED, TIMED_OUT, or logs) for all deployments,
     * or only for the given deployment id when non-null.
     */
    public function run(?int $deploymentId = null): int
    {
        $now = now();
        $finalizedCount = 0;

        $query = Task::query()
            ->where('status', 'IN_PROGRESS');

        if ($deploymentId !== null) {
            $query->where('deployment_id', $deploymentId);
        }

        $query->chunkById(100, function ($tasks) use (&$finalizedCount, $now) {
            foreach ($tasks as $task) {
                $expiresAt = $task->expiresAt();
                if ($expiresAt === null || $expiresAt->isFuture()) {
                    continue;
                }

                if ($task->allTrackedItemsCompleted()) {
                    continue;
                }

                $totals = $task->trackedItemTotals();

                if ($task->allTrackedItemsFailed()) {
                    $message = 'Task failed: all devices or interfaces failed before the deployment window ended.';
                    $task->update(['status' => 'FAILED']);
                    $task->processTaskStatusLog($message, true);
                    $finalizedCount++;

                    continue;
                }

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
                $finalizedCount++;
            }
        });

        if ($finalizedCount > 0 && $deploymentId === null) {
            Log::info('tasks.finalize_expired', ['finalized' => $finalizedCount]);
        }

        return $finalizedCount;
    }
}
