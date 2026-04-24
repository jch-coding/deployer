<?php

namespace App\Console\Commands;

use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PruneStaleTasksCommand extends Command
{
    protected $signature = 'tasks:prune-stale';

    protected $description = 'Delete tasks that have not been updated in the last month';

    public function handle(): int
    {
        $threshold = now()->subMonth();
        $deleted = 0;

        Task::query()
            ->where('updated_at', '<', $threshold)
            ->chunkById(100, function ($tasks) use (&$deleted) {
                foreach ($tasks as $task) {
                    $task->devices()->detach();
                    $task->deviceInterfaces()->detach();
                    $task->users()->detach();
                    $task->delete();
                    $deleted++;
                }
            });

        if ($deleted > 0) {
            Log::info('tasks.prune_stale', ['deleted' => $deleted]);
        }

        $this->info("Pruned {$deleted} stale task(s).");

        return self::SUCCESS;
    }
}
