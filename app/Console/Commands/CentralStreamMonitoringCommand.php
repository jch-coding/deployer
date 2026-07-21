<?php

namespace App\Console\Commands;

use App\Services\Central\ClassicMonitoringStreamManager;
use Illuminate\Console\Command;

class CentralStreamMonitoringCommand extends Command
{
    protected $signature = 'central:stream-monitoring';

    protected $description = 'Connect to Classic Central Streaming API (monitoring) for stream-mode online detection';

    public function handle(ClassicMonitoringStreamManager $manager): int
    {
        $this->info('Starting Classic Central monitoring stream worker...');
        $manager->run();

        return self::SUCCESS;
    }
}
