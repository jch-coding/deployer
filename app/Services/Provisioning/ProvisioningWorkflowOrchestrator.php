<?php

namespace App\Services\Provisioning;

use App\Enums\ProvisioningStep;
use App\JobQueueShard;
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
    ) {}

    public function dispatchStep(ProvisioningWorkflowDevice $workflowDevice, ProvisioningStep $step): void
    {
        $workflow = $workflowDevice->workflow;
        if ($workflow->isTerminal() || $workflowDevice->isTerminal()) {
            return;
        }

        RunProvisioningWorkflowStepJob::dispatch($workflowDevice->id, $step->value)
            ->onQueue(JobQueueShard::resolve($workflow->job_queue));
    }

    public function processStepResult(
        ProvisioningWorkflowDevice $workflowDevice,
        ProvisioningStep $step,
        ProvisioningStepResult $result,
    ): void {
        $workflowDevice->loadMissing('workflow', 'steps', 'device');
        $stepRow = $workflowDevice->steps->firstWhere('step_key', $step->value);

        if ($stepRow === null) {
            return;
        }

        if ($result->isWaitingPeer()) {
            $workflowDevice->update([
                'current_step_key' => $step->value,
                'status_message' => $result->message,
            ]);
            $stepRow->markInProgress($result->message);

            return;
        }

        if ($result->isDelegated()) {
            return;
        }

        if ($result->isSkipped()) {
            $this->completeStep($workflowDevice, $stepRow, $step, $result->message !== '' ? $result->message : 'Skipped');
            $this->advanceToNextStep($workflowDevice, $step);

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
            $this->advanceToNextStep($workflowDevice, $step);

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

    public function advanceToNextStep(ProvisioningWorkflowDevice $workflowDevice, ProvisioningStep $completedStep): void
    {
        $workflowDevice->refresh();
        $workflowDevice->loadMissing('steps', 'device', 'workflow');

        if ($workflowDevice->isTerminal()) {
            return;
        }

        $nextStep = $this->nextApplicableStep($workflowDevice, $completedStep);
        if ($nextStep === null) {
            $workflowDevice->update([
                'overall_status' => 'completed',
                'current_step_key' => null,
                'status_message' => 'Workflow completed successfully.',
            ]);
            $workflowDevice->workflow->refreshOverallStatus();

            return;
        }

        $nextStepRow = $workflowDevice->steps->firstWhere('step_key', $nextStep->value);
        if ($nextStepRow instanceof ProvisioningWorkflowDeviceStep) {
            $nextStepRow->markInProgress($nextStep->label().'...');
        }

        $workflowDevice->update([
            'current_step_key' => $nextStep->value,
            'status_message' => $nextStep->label().'...',
            'failed_step_key' => null,
        ]);

        if ($nextStep === ProvisioningStep::WaitForOnline) {
            $this->ensureClassicPollerRunning($workflowDevice->workflow);
        }

        $this->dispatchStep($workflowDevice, $nextStep);
    }

    public function ensureClassicPollerRunning(ProvisioningWorkflow $workflow): void
    {
        if ($workflow->classic_poller_active || $workflow->isTerminal()) {
            return;
        }

        $workflow->update(['classic_poller_active' => true]);
        PollClassicDeviceOnlineJob::dispatch($workflow->id)
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
    }

    private function nextApplicableStep(ProvisioningWorkflowDevice $workflowDevice, ProvisioningStep $after): ?ProvisioningStep
    {
        $device = $workflowDevice->device;
        $foundCurrent = false;

        foreach (ProvisioningStep::ordered() as $step) {
            if (! $foundCurrent) {
                if ($step === $after) {
                    $foundCurrent = true;
                }

                continue;
            }

            if ($step->shouldSkipForDevice($device)) {
                $stepRow = $workflowDevice->steps->firstWhere('step_key', $step->value);
                if ($stepRow instanceof ProvisioningWorkflowDeviceStep && $stepRow->status === 'pending') {
                    $stepRow->markSkipped('Not applicable for this device.');
                }

                continue;
            }

            return $step;
        }

        return null;
    }
}
