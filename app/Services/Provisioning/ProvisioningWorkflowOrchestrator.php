<?php

namespace App\Services\Provisioning;

use App\Enums\ProvisioningStep;
use App\JobQueueShard;
use App\Jobs\FailWaitForOnlineOnTimeoutJob;
use App\Jobs\PollClassicDeviceOnlineJob;
use App\Jobs\RunProvisioningWorkflowStepJob;
use App\Models\ProvisioningWorkflow;
use App\Models\ProvisioningWorkflowDevice;
use App\Models\ProvisioningWorkflowDeviceStep;
use Illuminate\Support\Facades\Log;

class ProvisioningWorkflowOrchestrator
{
    public function __construct(
        private readonly ProvisioningStepRunner $stepRunner,
        private readonly ProvisioningWorkflowTaskSync $taskSync,
    ) {}

    public function dispatchStep(
        ProvisioningWorkflowDevice $workflowDevice,
        ProvisioningWorkflowDeviceStep $stepRow,
    ): void {
        $workflow = $workflowDevice->workflow;
        if ($workflow->isHalted() || $workflowDevice->isTerminal()) {
            return;
        }

        RunProvisioningWorkflowStepJob::dispatch($workflowDevice->id, $stepRow->id, $stepRow->step_key)
            ->onQueue(JobQueueShard::resolve($workflow->job_queue));
    }

    public function processStepResult(
        ProvisioningWorkflowDevice $workflowDevice,
        ProvisioningWorkflowDeviceStep $stepRow,
        ProvisioningStepResult $result,
    ): void {
        $workflowDevice->loadMissing('workflow', 'steps', 'device');
        $stepRow = $workflowDevice->steps->firstWhere('id', $stepRow->id) ?? $stepRow;
        $step = ProvisioningStep::from($stepRow->step_key);

        if ($workflowDevice->workflow->isHalted()) {
            return;
        }

        if (in_array($stepRow->status, ['completed', 'skipped'], true)) {
            return;
        }

        if ($result->isWaitingPeer()) {
            $workflowDevice->update([
                'current_step_key' => $step->value,
                'status_message' => $result->message,
            ]);
            $stepRow->markInProgress($result->message);

            if ($step === ProvisioningStep::WaitForOnline
                && $workflowDevice->workflow->onlineDetectionMode()->waitsForExternalWake()) {
                $this->scheduleWaitForOnlineTimeout($workflowDevice);
            }

            return;
        }

        if ($result->isDelegated()) {
            return;
        }

        if ($result->isSkipped()) {
            $this->completeStep($workflowDevice, $stepRow, $step, $result->message !== '' ? $result->message : 'Skipped');
            $this->advanceToNextStep($workflowDevice, $stepRow);

            return;
        }

        if ($result->isCompleted()) {
            if ($step === ProvisioningStep::CreateStackProfile && $workflowDevice->device->sku) {
                $previousScope = (string) ($workflowDevice->device->scope_id ?? '');
                $message = $result->message.($previousScope !== '' ? '|scope_before:'.$previousScope : '');
                $this->completeStep($workflowDevice, $stepRow, $step, $message);
            } else {
                $this->completeStep($workflowDevice, $stepRow, $step, $result->message);
            }
            $this->advanceToNextStep($workflowDevice, $stepRow);

            return;
        }

        if ($result->isFailed()) {
            $this->failStep($workflowDevice, $stepRow, $step, $result->message);

            return;
        }

        if ($result->isRetry()) {
            $workflowDevice->update([
                'current_step_key' => $step->value,
                'status_message' => $result->message,
            ]);
            $stepRow->markInProgress($result->message);

            if ($step === ProvisioningStep::WaitForOnline) {
                $this->ensureClassicPollerRunning($workflowDevice->workflow);
            }
        }
    }

    public function advanceToNextStep(
        ProvisioningWorkflowDevice $workflowDevice,
        ProvisioningWorkflowDeviceStep $completedStepRow,
    ): void {
        $workflowDevice->refresh();
        $workflowDevice->loadMissing('steps', 'device', 'workflow');

        if ($workflowDevice->isTerminal() || $workflowDevice->workflow->isHalted()) {
            return;
        }

        $nextStepRow = $this->nextApplicableStepRow($workflowDevice, (int) $completedStepRow->step_order);
        if ($nextStepRow === null) {
            $workflowDevice->update([
                'overall_status' => 'completed',
                'current_step_key' => null,
                'status_message' => 'Workflow completed successfully.',
            ]);
            $workflowDevice->workflow->refreshOverallStatus();
            $this->syncLinkedTask($workflowDevice->workflow);

            return;
        }

        $nextStep = ProvisioningStep::from($nextStepRow->step_key);
        $nextStepRow->markInProgress($nextStep->label().'...');

        $workflowDevice->update([
            'current_step_key' => $nextStep->value,
            'status_message' => $nextStep->label().'...',
            'failed_step_key' => null,
        ]);

        if ($nextStep === ProvisioningStep::WaitForOnline) {
            $this->ensureClassicPollerRunning($workflowDevice->workflow);
        }

        $this->dispatchStep($workflowDevice, $nextStepRow);
    }

    public function ensureClassicPollerRunning(ProvisioningWorkflow $workflow): void
    {
        if (! $workflow->onlineDetectionMode()->usesPoller()) {
            return;
        }

        if ($workflow->classic_poller_active || $workflow->isHalted()) {
            return;
        }

        $workflow->update(['classic_poller_active' => true]);
        PollClassicDeviceOnlineJob::dispatch($workflow->id)
            ->onQueue(JobQueueShard::resolve($workflow->job_queue));
    }

    private function scheduleWaitForOnlineTimeout(ProvisioningWorkflowDevice $workflowDevice): void
    {
        $workflow = $workflowDevice->workflow;
        $delayMinutes = max(1, (int) $workflow->deployment_time);

        FailWaitForOnlineOnTimeoutJob::dispatch($workflowDevice->id)
            ->delay(now()->addMinutes($delayMinutes))
            ->onQueue(JobQueueShard::resolve($workflow->job_queue));
    }

    private function completeStep(
        ProvisioningWorkflowDevice $workflowDevice,
        ProvisioningWorkflowDeviceStep $stepRow,
        ProvisioningStep $step,
        string $message,
    ): void {
        $stepRow->markCompleted($message !== '' ? $message : $step->label().' completed.');
        $workflowDevice->update(['status_message' => $message !== '' ? $message : $step->label().' completed.']);
    }

    private function failStep(
        ProvisioningWorkflowDevice $workflowDevice,
        ProvisioningWorkflowDeviceStep $stepRow,
        ProvisioningStep $step,
        string $message,
    ): void {
        $stepRow->markFailed($message);
        $workflowDevice->update([
            'overall_status' => 'failed',
            'failed_step_key' => $step->value,
            'current_step_key' => $step->value,
            'status_message' => $message,
        ]);
        $workflowDevice->workflow->refreshOverallStatus();

        if ($step === ProvisioningStep::VerifyLicensing) {
            Log::info('Provisioning licensing gate failed for device '.$workflowDevice->device_id.': '.$message);
        }

        $this->syncLinkedTask($workflowDevice->workflow);
    }

    private function syncLinkedTask(ProvisioningWorkflow $workflow): void
    {
        $workflow->refresh();
        $this->taskSync->syncFromWorkflow($workflow->load('workflowDevices'));
    }

    private function nextApplicableStepRow(
        ProvisioningWorkflowDevice $workflowDevice,
        int $afterOrder,
    ): ?ProvisioningWorkflowDeviceStep {
        $device = $workflowDevice->device;
        $context = ProvisioningStepContext::forWorkflow($workflowDevice->workflow);

        foreach ($workflowDevice->steps->sortBy('step_order')->values() as $stepRow) {
            if ((int) $stepRow->step_order <= $afterOrder) {
                continue;
            }

            $step = ProvisioningStep::tryFrom($stepRow->step_key);
            if ($step === null) {
                continue;
            }

            if ($stepRow->status === 'skipped') {
                continue;
            }

            if (in_array($stepRow->status, ['completed'], true)) {
                continue;
            }

            if ($step->shouldSkipForDevice($device, $context)) {
                if ($stepRow->status === 'pending') {
                    $stepRow->markSkipped('Not applicable for this device.');
                }

                continue;
            }

            return $stepRow;
        }

        return null;
    }
}
