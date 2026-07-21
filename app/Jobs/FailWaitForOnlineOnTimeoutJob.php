<?php

namespace App\Jobs;

use App\Enums\ProvisioningStep;
use App\Models\ProvisioningWorkflowDevice;
use App\Services\Provisioning\ProvisioningStepResult;
use App\Services\Provisioning\ProvisioningWorkflowOrchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FailWaitForOnlineOnTimeoutJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $workflowDeviceId,
    ) {}

    public function handle(ProvisioningWorkflowOrchestrator $orchestrator): void
    {
        $workflowDevice = ProvisioningWorkflowDevice::query()
            ->with(['steps', 'workflow'])
            ->find($this->workflowDeviceId);

        if ($workflowDevice === null || $workflowDevice->isTerminal()) {
            return;
        }

        $workflow = $workflowDevice->workflow;
        if ($workflow === null || $workflow->isTerminal()) {
            return;
        }

        $stepRow = $workflowDevice->steps->firstWhere('step_key', ProvisioningStep::WaitForOnline->value);
        if ($stepRow === null || $stepRow->status !== 'in_progress') {
            return;
        }

        $orchestrator->processStepResult(
            $workflowDevice,
            ProvisioningStep::WaitForOnline,
            ProvisioningStepResult::failed('Timed out waiting for device to come online via webhook.'),
        );
    }
}
