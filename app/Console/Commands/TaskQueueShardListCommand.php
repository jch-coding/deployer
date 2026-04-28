<?php

namespace App\Console\Commands;

use App\JobQueueShard;
use Illuminate\Console\Command;

class TaskQueueShardListCommand extends Command
{
    protected $signature = 'task:queue-shard-list';

    protected $description = 'Print comma-separated task job queue shard names (for queue:work --queue=...)';

    public function handle(): int
    {
        $this->line(JobQueueShard::commaSeparatedList());

        return self::SUCCESS;
    }
}
