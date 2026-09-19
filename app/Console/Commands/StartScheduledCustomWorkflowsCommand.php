<?php

namespace App\Console\Commands;

use App\Services\StartScheduledCustomWorkflowsService;
use Illuminate\Console\Command;

class StartScheduledCustomWorkflowsCommand extends Command
{
    protected $signature = 'tasks:start-scheduled';

    protected $description = 'Launch custom workflows whose scheduled start time has arrived';

    public function handle(StartScheduledCustomWorkflowsService $startScheduled): int
    {
        $result = $startScheduled->run();

        $this->info(sprintf(
            'Launched %d scheduled workflow(s); timed out %d missed window(s).',
            $result['launched'],
            $result['timed_out'],
        ));

        return self::SUCCESS;
    }
}
