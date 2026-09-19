<?php

namespace App\Services\Provisioning;

use App\Models\Deployment;
use App\Models\ProvisioningWorkflow;
use App\Models\ProvisioningWorkflowDevice;
use App\Models\Task;

class ProvisioningWorkflowTaskSync
{
    public function createForWorkflow(ProvisioningWorkflow $workflow, Deployment $deployment): Task
    {
        $workflow->loadMissing('workflowDevices');

        $isScheduled = $workflow->status === 'scheduled';
        $expiresAt = null;
        if ($isScheduled && $workflow->scheduled_at !== null) {
            $expiresAt = $workflow->scheduled_at->copy()->addMinutes(
                max(1, (int) $workflow->deployment_time)
            );
        }

        $task = $deployment->tasks()->create([
            'task_type' => 'CUSTOM_PROVISION',
            'name' => 'custom_provision_'.$deployment->name.now(),
            'status' => $isScheduled ? 'SCHEDULED' : 'IN_PROGRESS',
            'deployment_time' => $workflow->deployment_time,
            'wait_time' => $workflow->wait_time,
            'job_queue' => $workflow->job_queue,
            'provisioning_workflow_id' => $workflow->id,
            'scheduled_at' => $workflow->scheduled_at,
            'expires_at' => $expiresAt,
        ]);

        $attachData = [];
        foreach ($workflow->workflowDevices as $workflowDevice) {
            $attachData[$workflowDevice->device_id] = [
                'status' => $this->mapDevicePivotStatus($workflowDevice, $isScheduled),
            ];
        }
        if ($attachData !== []) {
            $task->devices()->attach($attachData);
        }

        if (! $isScheduled) {
            $this->syncFromWorkflow($workflow->fresh(['workflowDevices']));
        }

        return $task->fresh(['provisioningWorkflow', 'devices']);
    }

    public function syncFromWorkflow(ProvisioningWorkflow $workflow): void
    {
        $task = Task::query()
            ->where('provisioning_workflow_id', $workflow->id)
            ->first();

        if ($task === null) {
            return;
        }

        $workflow->loadMissing('workflowDevices');

        // Preserve TIMED_OUT for missed scheduled windows (workflow is cancelled but task timed out).
        $resolvedStatus = $this->resolveTaskStatus($workflow);
        if ($task->status === 'TIMED_OUT' && $workflow->status === 'cancelled') {
            $resolvedStatus = 'TIMED_OUT';
        }

        $task->update([
            'status' => $resolvedStatus,
            'deployment_time' => $workflow->deployment_time,
            'wait_time' => $workflow->wait_time,
        ]);

        $isScheduled = $workflow->status === 'scheduled';
        foreach ($workflow->workflowDevices as $workflowDevice) {
            $task->devices()->updateExistingPivot(
                $workflowDevice->device_id,
                ['status' => $this->mapDevicePivotStatus($workflowDevice, $isScheduled)],
            );
        }
    }

    private function resolveTaskStatus(ProvisioningWorkflow $workflow): string
    {
        if ($workflow->status === 'scheduled') {
            return 'SCHEDULED';
        }

        if ($workflow->status === 'cancelled') {
            return 'CANCELLED';
        }

        if ($workflow->status === 'completed') {
            return 'COMPLETED';
        }

        $summary = $workflow->summaryCounts();
        if ($workflow->status === 'running' && $summary['in_progress'] === 0 && $summary['failed'] > 0) {
            return 'FAILED';
        }

        return 'IN_PROGRESS';
    }

    private function mapDevicePivotStatus(ProvisioningWorkflowDevice $workflowDevice, bool $isScheduled = false): string
    {
        if ($isScheduled) {
            return 'PENDING';
        }

        return match ($workflowDevice->overall_status) {
            'completed' => 'COMPLETED',
            'failed' => 'FAILED',
            default => 'IN_PROGRESS',
        };
    }
}
