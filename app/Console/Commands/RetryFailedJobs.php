<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class RetryFailedJobs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:retry {failedJobs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retry the failed job(s) that are passed to the artisan queue:retry command';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Artisan::call('queue:retry', ['failedJobs' => $this->argument('failedJobs')]);
    }
}
