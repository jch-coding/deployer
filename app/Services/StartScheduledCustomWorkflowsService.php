<?php

namespace App\Services;

use App\Models\ProvisioningWorkflow;
use App\Models\Task;
use App\Services\Provisioning\ProvisioningWorkflowService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StartScheduledCustomWorkflowsService
{
    public function __construct(
        private readonly ProvisioningWorkflowService $workflowService,
    ) {}

    /**
     * Start due scheduled custom-provision workflows (or mark missed windows as timed out).
     *
     * @return array{launched: int, timed_out: int}
     */
    public function run(?int $deploymentId = null): array
    {
        $launched = 0;
        $timedOut = 0;
        $now = now();

        $query = Task::query()
            ->where('task_type', 'CUSTOM_PROVISION')
            ->where('status', 'SCHEDULED')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', $now);

        if ($deploymentId !== null) {
            $query->where('deployment_id', $deploymentId);
        }

        $taskIds = $query->orderBy('id')->pluck('id');

        foreach ($taskIds as $taskId) {
            $result = DB::transaction(function () use ($taskId) {
                $task = Task::query()
                    ->whereKey($taskId)
                    ->lockForUpdate()
                    ->first();

                if ($task === null || $task->status !== 'SCHEDULED') {
                    return null;
                }

                $workflow = ProvisioningWorkflow::query()
                    ->whereKey($task->provisioning_workflow_id)
                    ->lockForUpdate()
                    ->first();

                if ($workflow === null || $workflow->status !== 'scheduled') {
                    return null;
                }

                $expiresAt = $task->expiresAt();
                if ($expiresAt !== null && ! $expiresAt->isFuture()) {
                    $this->workflowService->markScheduledMissed($workflow);

                    return 'timed_out';
                }

                $this->workflowService->launchPreparedWorkflow($workflow);

                return 'launched';
            });

            if ($result === 'launched') {
                $launched++;
            } elseif ($result === 'timed_out') {
                $timedOut++;
            }
        }

        if (($launched > 0 || $timedOut > 0) && $deploymentId === null) {
            Log::info('tasks.start_scheduled', [
                'launched' => $launched,
                'timed_out' => $timedOut,
            ]);
        }

        return [
            'launched' => $launched,
            'timed_out' => $timedOut,
        ];
    }
}
