<?php

namespace App\Console\Commands;

use App\Services\FinalizeExpiredTasksService;
use Illuminate\Console\Command;

class FinalizeExpiredTasksCommand extends Command
{
    protected $signature = 'tasks:finalize-expired';

    protected $description = 'Mark expired in-progress tasks as timed out when items remain incomplete';

    public function handle(FinalizeExpiredTasksService $finalizeExpiredTasks): int
    {
        $finalizedCount = $finalizeExpiredTasks->run();

        $this->info("Finalized {$finalizedCount} expired task(s).");

        return self::SUCCESS;
    }
}
