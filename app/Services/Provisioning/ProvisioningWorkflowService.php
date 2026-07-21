<?php

namespace App\Services\Provisioning;

use App\Enums\OnlineDetectionMode;
use App\Enums\ProvisioningStep;
use App\Helper\CentralAPIHelper;
use App\JobQueueShard;
use App\LicenseType;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\ProvisioningWorkflow;
use App\Models\ProvisioningWorkflowDevice;
use App\Models\ProvisioningWorkflowDeviceStep;
use App\Models\User;
use App\Services\LicensingInventoryService;
use App\Services\LicensingPoolResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProvisioningWorkflowService
{
    public function __construct(
        private readonly ProvisioningWorkflowOrchestrator $orchestrator,
        private readonly LicensingInventoryService $licensingInventoryService,
        private readonly LicensingPoolResolver $licensingPoolResolver,
    ) {}

    /**
     * @param  array<int, int>  $deviceIds
     * @param  array<string, mixed>  $options
     */
    public function start(Deployment $deployment, User $user, array $deviceIds, array $options): ProvisioningWorkflow
    {
        $deviceIds = array_values(array_unique(array_map('intval', $deviceIds)));
        if ($deviceIds === []) {
            throw ValidationException::withMessages(['devices' => 'Select at least one device.']);
        }

        $devices = Device::query()
            ->where('deployment_id', $deployment->id)
            ->whereIn('id', $deviceIds)
            ->get();

        if ($devices->count() !== count($deviceIds)) {
            throw ValidationException::withMessages(['devices' => 'One or more selected devices are invalid for this deployment.']);
        }

        $deployment->loadMissing('client');
        $licensingConfig = $this->buildLicensingConfig($deployment, $devices, $options);
        $provisioningNames = $this->applyProvisioningDeviceNames($devices, $options);
        $licensingConfig['naming'] = ['per_device' => $provisioningNames];

        $deploymentTime = max(1, (int) ($options['deployment_time'] ?? 10));
        $waitTime = max(1, (int) ($options['wait_time'] ?? 1));
        $onlineDetectionMode = OnlineDetectionMode::tryFrom((string) ($options['online_detection_mode'] ?? ''))
            ?? OnlineDetectionMode::Poll;
        $jobQueue = JobQueueShard::fromUserEntropy((int) $user->id, (string) Str::uuid());
        $stepContext = new ProvisioningStepContext(
            CentralAPIHelper::deploymentUsesVsxFallbackMode($devices),
            CentralAPIHelper::deploymentUsesMirrorFallbackMode($devices),
            $provisioningNames,
        );

        return DB::transaction(function () use ($deployment, $user, $devices, $licensingConfig, $deploymentTime, $waitTime, $onlineDetectionMode, $jobQueue, $stepContext): ProvisioningWorkflow {
            $workflow = ProvisioningWorkflow::query()->create([
                'deployment_id' => $deployment->id,
                'user_id' => $user->id,
                'status' => 'running',
                'job_queue' => $jobQueue,
                'deployment_time' => $deploymentTime,
                'wait_time' => $waitTime,
                'online_detection_mode' => $onlineDetectionMode,
                'licensing_config' => $licensingConfig,
                'started_at' => now(),
            ]);

            foreach ($devices as $device) {
                $workflowDevice = ProvisioningWorkflowDevice::query()->create([
                    'provisioning_workflow_id' => $workflow->id,
                    'device_id' => $device->id,
                    'overall_status' => 'in_progress',
                    'current_step_key' => ProvisioningStep::VerifyLicensing->value,
                    'status_message' => 'Starting licensing verification...',
                ]);

                foreach (ProvisioningStep::ordered() as $step) {
                    $status = $step->shouldSkipForDevice($device, $stepContext) ? 'skipped' : 'pending';
                    ProvisioningWorkflowDeviceStep::query()->create([
                        'provisioning_workflow_device_id' => $workflowDevice->id,
                        'step_key' => $step->value,
                        'step_order' => $step->order(),
                        'status' => $status,
                        'message' => $status === 'skipped' ? 'Not applicable for this device.' : null,
                        'completed_at' => $status === 'skipped' ? now() : null,
                    ]);
                }

                $firstStep = $this->firstRunnableStep($device, $stepContext);
                if ($firstStep !== null) {
                    $firstStepRow = $workflowDevice->steps()->where('step_key', $firstStep->value)->first();
                    $firstStepRow?->markInProgress($firstStep->label().'...');
                    $workflowDevice->update([
                        'current_step_key' => $firstStep->value,
                        'status_message' => $firstStep->label().'...',
                    ]);
                    $this->orchestrator->dispatchStep($workflowDevice, $firstStep);
                } else {
                    $workflowDevice->update([
                        'overall_status' => 'completed',
                        'current_step_key' => null,
                        'status_message' => 'No applicable steps for this device.',
                    ]);
                }
            }

            $workflow->refreshOverallStatus();

            return $workflow->load(['workflowDevices.device', 'workflowDevices.steps']);
        });
    }

    public function cancel(ProvisioningWorkflow $workflow): void
    {
        if ($workflow->isTerminal()) {
            return;
        }

        $workflow->update([
            'status' => 'cancelled',
            'completed_at' => now(),
            'classic_poller_active' => false,
        ]);
    }

    public function restartFromStep(ProvisioningWorkflowDevice $workflowDevice, ProvisioningStep $fromStep): void
    {
        $workflowDevice->loadMissing('device', 'workflow', 'steps');
        $workflow = $workflowDevice->workflow;

        if ($workflow->isTerminal()) {
            return;
        }

        $fromOrder = $fromStep->order();
        $stepContext = ProvisioningStepContext::forWorkflow($workflow);

        foreach ($workflowDevice->steps as $stepRow) {
            $stepEnum = ProvisioningStep::from($stepRow->step_key);
            if ($stepEnum->order() >= $fromOrder) {
                if ($stepEnum->shouldSkipForDevice($workflowDevice->device, $stepContext)) {
                    $stepRow->markSkipped('Not applicable for this device.');
                } else {
                    $stepRow->resetToPending();
                }
            }
        }

        $workflowDevice->update([
            'overall_status' => 'in_progress',
            'failed_step_key' => null,
            'vsx_wait_state' => null,
            'current_step_key' => $fromStep->value,
            'status_message' => 'Restarting from '.$fromStep->label().'...',
        ]);

        if ($workflow->status !== 'running') {
            $workflow->update(['status' => 'running', 'completed_at' => null]);
        }

        $stepRow = $workflowDevice->steps->firstWhere('step_key', $fromStep->value);
        $stepRow?->markInProgress('Restarting...');
        $this->orchestrator->dispatchStep($workflowDevice, $fromStep);
    }

    public function latestForDeployment(Deployment $deployment): ?ProvisioningWorkflow
    {
        return ProvisioningWorkflow::query()
            ->where('deployment_id', $deployment->id)
            ->latest('id')
            ->with(['workflowDevices.device', 'workflowDevices.steps'])
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeForUi(ProvisioningWorkflow $workflow): array
    {
        $workflow->loadMissing(['workflowDevices.device', 'workflowDevices.steps']);

        $summary = $workflow->summaryCounts();
        $licensingFailures = [];
        $deviceCards = [];

        foreach ($workflow->workflowDevices as $workflowDevice) {
            $device = $workflowDevice->device;
            $steps = $workflowDevice->steps->sortBy('step_order')->values()->map(fn (ProvisioningWorkflowDeviceStep $row) => [
                'step_key' => $row->step_key,
                'label' => ProvisioningStep::from($row->step_key)->label(),
                'status' => $row->status,
                'message' => $row->message,
                'order' => $row->step_order,
            ])->all();

            if ($workflowDevice->failed_step_key === ProvisioningStep::VerifyLicensing->value) {
                $licensingFailures[] = [
                    'device_id' => $device->id,
                    'name' => $device->name,
                    'serial' => $device->serial,
                    'message' => $workflowDevice->status_message,
                ];
            }

            $deviceCards[] = [
                'id' => $workflowDevice->id,
                'device_id' => $device->id,
                'name' => $device->name,
                'serial' => $device->serial,
                'overall_status' => $workflowDevice->overall_status,
                'current_step_key' => $workflowDevice->current_step_key,
                'current_step_label' => $workflowDevice->current_step_key
                    ? ProvisioningStep::from($workflowDevice->current_step_key)->label()
                    : null,
                'failed_step_key' => $workflowDevice->failed_step_key,
                'status_message' => $workflowDevice->status_message,
                'progress_percent' => $workflowDevice->progressPercent(),
                'completed_steps' => $workflowDevice->completedStepsCount(),
                'applicable_steps' => $workflowDevice->applicableStepsCount(),
                'steps' => $steps,
                'restartable_steps' => $this->restartableSteps($workflowDevice),
            ];
        }

        return [
            'id' => $workflow->id,
            'status' => $workflow->status,
            'deployment_time' => $workflow->deployment_time,
            'wait_time' => $workflow->wait_time,
            'online_detection_mode' => $workflow->onlineDetectionMode()->value,
            'started_at' => $workflow->started_at?->toIso8601String(),
            'completed_at' => $workflow->completed_at?->toIso8601String(),
            'summary' => $summary,
            'licensing_failures' => $licensingFailures,
            'devices' => $deviceCards,
            'is_terminal' => $workflow->isTerminal(),
        ];
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function buildLicensingConfig(Deployment $deployment, Collection $devices, array $options): array
    {
        $mode = (string) ($options['licensing_mode'] ?? 'uniform');
        $needsDialog = $devices->contains(
            fn (Device $device) => trim((string) $device->license_tag) === '' || trim((string) $device->license_type) === ''
        );

        if (! $needsDialog) {
            return [
                'mode' => 'device_csv',
            ];
        }

        if ($mode === 'per_device') {
            $perDevice = [];
            foreach (($options['devices'] ?? []) as $row) {
                $deviceId = (int) ($row['id'] ?? 0);
                $tag = trim((string) ($row['license_tag'] ?? ''));
                $type = trim((string) ($row['license_type'] ?? ''));
                if ($deviceId <= 0 || $tag === '' || $type === '') {
                    continue;
                }
                if (LicenseType::tryFromValue($type) === null) {
                    throw ValidationException::withMessages(['license_type' => 'Invalid license type for one or more devices.']);
                }
                $perDevice[$deviceId] = [
                    'license_tag' => $tag,
                    'license_type' => $type,
                ];
            }

            foreach ($devices as $device) {
                if ((trim((string) $device->license_tag) === '' || trim((string) $device->license_type) === '')
                    && ! isset($perDevice[$device->id])) {
                    throw ValidationException::withMessages(['devices' => 'Each device without CSV licensing fields needs a license tag and type.']);
                }
            }

            return [
                'mode' => 'per_device',
                'per_device' => $perDevice,
            ];
        }

        $licenseTag = trim((string) ($options['license_tag'] ?? ''));
        $licenseTypeValue = trim((string) ($options['license_type'] ?? ''));
        if ($licenseTag === '' || $licenseTypeValue === '') {
            throw ValidationException::withMessages(['license_tag' => 'License tag and type are required when devices lack CSV licensing columns.']);
        }

        $licenseType = LicenseType::tryFromValue($licenseTypeValue);
        if ($licenseType === null) {
            throw ValidationException::withMessages(['license_type' => 'Selected license type is not valid.']);
        }

        $licensingOptions = $this->licensingInventoryService->buildLicensingOptionsFromCache($deployment->client);
        if (($licensingOptions['central_error'] ?? null) !== null) {
            throw ValidationException::withMessages(['license_tag' => (string) $licensingOptions['central_error']]);
        }

        $missingCount = $devices->filter(
            fn (Device $device) => trim((string) $device->license_tag) === '' || trim((string) $device->license_type) === ''
        )->count();

        $capacityError = $this->licensingPoolResolver->validatePoolCapacity(
            $licenseTag,
            $licenseType,
            $missingCount,
            $licensingOptions['available_subscriptions'],
        );
        if ($capacityError !== null) {
            throw ValidationException::withMessages(['license_tag' => $capacityError['error']]);
        }

        return [
            'mode' => 'uniform',
            'license_tag' => $licenseTag,
            'license_type' => $licenseType->value,
        ];
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @param  array<string, mixed>  $options
     * @return array<int, string>
     */
    private function applyProvisioningDeviceNames(Collection $devices, array $options): array
    {
        $provisioningNames = [];

        foreach (($options['devices'] ?? []) as $row) {
            $deviceId = (int) ($row['id'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));

            if ($deviceId <= 0 || $name === '') {
                continue;
            }

            if (strlen($name) < 3) {
                throw ValidationException::withMessages([
                    'devices' => 'Device names must be at least 3 characters when provided.',
                ]);
            }

            $device = $devices->firstWhere('id', $deviceId);
            if ($device === null || ! ProvisioningStep::isApDevice($device)) {
                continue;
            }

            $device->update(['name' => $name]);
            $provisioningNames[$deviceId] = $name;
        }

        return $provisioningNames;
    }

    private function firstRunnableStep(Device $device, ProvisioningStepContext $context): ?ProvisioningStep
    {
        foreach (ProvisioningStep::ordered() as $step) {
            if (! $step->shouldSkipForDevice($device, $context)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * @return list<array{step_key: string, label: string}>
     */
    private function restartableSteps(ProvisioningWorkflowDevice $workflowDevice): array
    {
        if ($workflowDevice->overall_status !== 'failed' || $workflowDevice->failed_step_key === null) {
            return [];
        }

        $failedOrder = ProvisioningStep::from($workflowDevice->failed_step_key)->order();
        $steps = [];

        foreach ($workflowDevice->steps->sortBy('step_order') as $stepRow) {
            $step = ProvisioningStep::from($stepRow->step_key);
            if ($step->order() >= $failedOrder && $stepRow->status !== 'skipped') {
                $steps[] = [
                    'step_key' => $step->value,
                    'label' => $step->label(),
                ];
            }
        }

        return $steps;
    }
}
