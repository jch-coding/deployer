<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Task;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CreateNewCentralCXGroup extends BaseTaskJob
{

    /**
     * Create a new job instance.
     */
    public function __construct(public string $group_name, public Task $task, public CentralAPIHelper $centralAPIHelper, public bool $switch_group = true, public bool $ap_group = false)
    {
        $this->initTaskTiming($task, defaultDeploymentMinutes: 3, defaultWaitMinutes: 1);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->handleSafely(function (): void {
            $response = $this->centralAPIHelper->classic_make_new_central_group($this->group_name, $this->switch_group, $this->ap_group);
            if ($response->status() != 201) {
                $this->task->processTaskStatusLog('Failed to create new group '.$this->group_name.' '.$response->json('description'), true);
                $this->task->update(['status' => 'FAILED']);
            } else {
                $this->task->processTaskStatusLog('Created new group '.$this->group_name);
                $this->task->update(['status' => 'COMPLETED']);
            }
        });
    }
}
