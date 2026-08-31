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

        $task = $deployment->tasks()->create([
            'task_type' => 'CUSTOM_PROVISION',
            'name' => 'custom_provision_'.$deployment->name.now(),
            'status' => 'IN_PROGRESS',
            'deployment_time' => $workflow->deployment_time,
            'wait_time' => $workflow->wait_time,
            'job_queue' => $workflow->job_queue,
            'provisioning_workflow_id' => $workflow->id,
        ]);

        $attachData = [];
        foreach ($workflow->workflowDevices as $workflowDevice) {
            $attachData[$workflowDevice->device_id] = [
                'status' => $this->mapDevicePivotStatus($workflowDevice),
            ];
        }
        if ($attachData !== []) {
            $task->devices()->attach($attachData);
        }

        $this->syncFromWorkflow($workflow->fresh(['workflowDevices']));

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

        $task->update([
            'status' => $this->resolveTaskStatus($workflow),
            'deployment_time' => $workflow->deployment_time,
            'wait_time' => $workflow->wait_time,
        ]);

        foreach ($workflow->workflowDevices as $workflowDevice) {
            $task->devices()->updateExistingPivot(
                $workflowDevice->device_id,
                ['status' => $this->mapDevicePivotStatus($workflowDevice)],
            );
        }
    }

    private function resolveTaskStatus(ProvisioningWorkflow $workflow): string
    {
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

    private function mapDevicePivotStatus(ProvisioningWorkflowDevice $workflowDevice): string
    {
        return match ($workflowDevice->overall_status) {
            'completed' => 'COMPLETED',
            'failed' => 'FAILED',
            default => 'IN_PROGRESS',
        };
    }
}
