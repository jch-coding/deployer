<?php

namespace App\Services;

use App\Enums\ProvisioningStep;
use App\Http\Controllers\TaskController;
use App\JobQueueShard;
use App\Jobs\FailWaitForOnlineOnTimeoutJob;
use App\Jobs\RunProvisioningWorkflowStepJob;
use App\Models\ProvisioningWorkflow;
use App\Models\ProvisioningWorkflowDevice;
use App\Models\ProvisioningWorkflowDeviceStep;
use App\Models\Task;
use App\Services\Provisioning\ProvisioningWorkflowTaskSync;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;

class ExtendTaskDeadlineService
{
    public function __construct(
        private readonly TaskController $taskController,
        private readonly ProvisioningWorkflowTaskSync $taskSync,
    ) {}

    /**
     * @param  Collection<int, Task>  $tasks
     */
    public function extend(Collection $tasks, int $extraMinutes): void
    {
        if ($extraMinutes < 1) {
            throw ValidationException::withMessages([
                'minutes' => 'Add at least one minute to extend the task deadline.',
            ]);
        }

        $primary = $tasks->first();
        if ($primary === null) {
            throw ValidationException::withMessages([
                'task' => 'Task not found.',
            ]);
        }

        if (! $primary->canExtendDeadline()) {
            throw ValidationException::withMessages([
                'task' => 'This task cannot be extended in its current state.',
            ]);
        }

        if ($primary->task_type === 'CUSTOM_PROVISION') {
            $this->extendCustomProvision($primary, $extraMinutes);

            return;
        }

        $this->extendClassicTasks($tasks, $extraMinutes);
    }

    /**
     * @param  Collection<int, Task>  $tasks
     */
    private function extendClassicTasks(Collection $tasks, int $extraMinutes): void
    {
        foreach ($tasks as $task) {
            if ($task->status !== 'IN_PROGRESS') {
                continue;
            }

            $newDeadline = $this->computeExtendedDeadline($task, $extraMinutes);

            $task->update([
                'expires_at' => $newDeadline,
                'deployment_time' => (int) $task->effectiveDeploymentMinutes() + $extraMinutes,
            ]);

            $this->cancelBatchOnly($task);

            $batchId = $this->taskController->dispatchJob($task->fresh());
            if ($batchId !== null) {
                $task->forceFill(['batch_id' => $batchId])->save();
            }

            $task->processTaskStatusLog(
                'Task deadline extended to '.Task::formatDeadlineForLog($newDeadline).'.',
                true,
            );
        }
    }

    private function extendCustomProvision(Task $task, int $extraMinutes): void
    {
        $task->loadMissing('provisioningWorkflow');
        $workflow = $task->provisioningWorkflow;

        if ($workflow === null) {
            throw ValidationException::withMessages([
                'task' => 'Linked provisioning workflow not found.',
            ]);
        }

        $newDeadline = $this->computeExtendedDeadline($task, $extraMinutes);
        $newDeploymentMinutes = (int) $workflow->deployment_time + $extraMinutes;

        $workflow->update([
            'deployment_time' => $newDeploymentMinutes,
        ]);

        $task->update([
            'expires_at' => $newDeadline,
            'deployment_time' => $newDeploymentMinutes,
        ]);

        if ($workflow->status === 'running') {
            $this->redispatchIncompleteWorkflowSteps($workflow, $newDeadline);
        }

        $this->taskSync->syncFromWorkflow($workflow->fresh(['workflowDevices']));

        $task->processTaskStatusLog(
            'Task deadline extended to '.Task::formatDeadlineForLog($newDeadline).'.',
            true,
        );
    }

    private function computeExtendedDeadline(Task $task, int $extraMinutes): CarbonInterface
    {
        $current = $task->expiresAt();
        $base = $current !== null && $current->isFuture()
            ? $current->copy()
            : now();

        return $base->addMinutes($extraMinutes);
    }

    private function cancelBatchOnly(Task $task): void
    {
        if (! $task->batch_id) {
            return;
        }

        $batch = Bus::findBatch($task->batch_id);
        if ($batch) {
            $batch->cancel();
        }
    }

    private function redispatchIncompleteWorkflowSteps(ProvisioningWorkflow $workflow, CarbonInterface $newDeadline): void
    {
        $workflow->loadMissing(['workflowDevices.device', 'workflowDevices.steps']);

        foreach ($workflow->workflowDevices as $workflowDevice) {
            if ($workflowDevice->overall_status === 'completed') {
                continue;
            }

            $nextStepRow = $workflowDevice->steps
                ->sortBy('step_order')
                ->first(fn (ProvisioningWorkflowDeviceStep $row) => ! in_array($row->status, ['completed', 'skipped'], true));

            if ($nextStepRow === null) {
                continue;
            }

            $nextStep = ProvisioningStep::from($nextStepRow->step_key);

            if ($nextStep === ProvisioningStep::WaitForOnline && $nextStepRow->status === 'in_progress') {
                FailWaitForOnlineOnTimeoutJob::dispatch($workflowDevice->id)
                    ->delay($newDeadline)
                    ->onQueue(JobQueueShard::resolve($workflow->job_queue));

                continue;
            }

            RunProvisioningWorkflowStepJob::dispatch($workflowDevice->id, $nextStep->value)
                ->onQueue(JobQueueShard::resolve($workflow->job_queue));
        }
    }
}
