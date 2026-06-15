<?php

namespace App\Jobs;

use App\Enums\ProvisioningStep;
use App\Helper\CentralAPIHelper;
use App\Models\ProvisioningWorkflowDevice;
use App\Services\Provisioning\ProvisioningStepRunner;
use App\Services\Provisioning\ProvisioningWorkflowOrchestrator;
use DateTime;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunProvisioningWorkflowStepJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $workflowDeviceId,
        public string $stepKey,
    ) {}

    public function handle(
        ProvisioningStepRunner $stepRunner,
        ProvisioningWorkflowOrchestrator $orchestrator,
    ): void {
        $workflowDevice = ProvisioningWorkflowDevice::query()
            ->with(['device.site', 'device.interfaces', 'workflow.deployment.client', 'steps'])
            ->find($this->workflowDeviceId);

        if ($workflowDevice === null) {
            return;
        }

        $workflow = $workflowDevice->workflow;
        if ($workflow->isTerminal() || $workflowDevice->isTerminal()) {
            return;
        }

        $step = ProvisioningStep::from($this->stepKey);
        $stepRow = $workflowDevice->steps->firstWhere('step_key', $step->value);
        if ($stepRow === null) {
            return;
        }

        if ($stepRow->status === 'completed' || $stepRow->status === 'skipped') {
            $orchestrator->advanceToNextStep($workflowDevice, $step);

            return;
        }

        $stepRow->markInProgress($step->label().'...');
        $workflowDevice->update([
            'current_step_key' => $step->value,
            'status_message' => $step->label().'...',
        ]);

        $client = $workflow->deployment->client;
        $centralAPIHelper = new CentralAPIHelper($client);

        try {
            $result = $stepRunner->run($workflowDevice, $step, $centralAPIHelper);
            $orchestrator->processStepResult($workflowDevice, $step, $result);

            if ($result->isRetry()) {
                $this->release(max(60, $workflow->wait_time * 60));
            }
        } catch (Throwable $exception) {
            Log::error($exception);
            $orchestrator->processStepResult(
                $workflowDevice,
                $step,
                \App\Services\Provisioning\ProvisioningStepResult::failed($exception->getMessage()),
            );
        }
    }

    public function retryUntil(): DateTime
    {
        $workflowDevice = ProvisioningWorkflowDevice::query()
            ->with('workflow')
            ->find($this->workflowDeviceId);

        $minutes = $workflowDevice?->workflow?->deployment_time ?? 10;

        return now()->addMinutes($minutes)->toDateTime();
    }
}
