<?php

namespace App\Services\Provisioning;

use App\Actions\Provisioning\AssignDeviceFunctionAction;
use App\Actions\Provisioning\AssociateDeviceToSiteAction;
use App\Actions\Provisioning\ClearLocalOverridesAction;
use App\Actions\Provisioning\ConfigureDeviceEthernetInterfacesAction;
use App\Actions\Provisioning\ConfigureDeviceLagInterfacesAction;
use App\Actions\Provisioning\ConfigureDeviceVlanInterfacesAction;
use App\Actions\Provisioning\ConfigureMirrorSessionAction;
use App\Actions\Provisioning\CreateVsfProfileAction;
use App\Actions\Provisioning\CreateVsxProfilePairAction;
use App\Actions\Provisioning\NameDeviceAction;
use App\Actions\Provisioning\PreprovisionDeviceToGroupAction;
use App\Actions\Provisioning\ResolveDeviceScopeIdAction;
use App\Actions\Provisioning\VerifyAndAssignLicensingAction;
use App\Actions\Provisioning\WaitForVsfStackScopeAction;
use App\Enums\ProvisioningStep;
use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\ProvisioningWorkflowDevice;

class ProvisioningStepRunner
{
    public function __construct(
        private readonly VerifyAndAssignLicensingAction $verifyAndAssignLicensingAction,
        private readonly PreprovisionDeviceToGroupAction $preprovisionDeviceToGroupAction,
        private readonly AssignDeviceFunctionAction $assignDeviceFunctionAction,
        private readonly AssociateDeviceToSiteAction $associateDeviceToSiteAction,
        private readonly ResolveDeviceScopeIdAction $resolveDeviceScopeIdAction,
        private readonly NameDeviceAction $nameDeviceAction,
        private readonly ConfigureDeviceVlanInterfacesAction $configureDeviceVlanInterfacesAction,
        private readonly CreateVsfProfileAction $createVsfProfileAction,
        private readonly CreateVsxProfilePairAction $createVsxProfilePairAction,
        private readonly WaitForVsfStackScopeAction $waitForVsfStackScopeAction,
        private readonly ConfigureDeviceLagInterfacesAction $configureDeviceLagInterfacesAction,
        private readonly ConfigureDeviceEthernetInterfacesAction $configureDeviceEthernetInterfacesAction,
        private readonly ConfigureMirrorSessionAction $configureMirrorSessionAction,
        private readonly ClearLocalOverridesAction $clearLocalOverridesAction,
        private readonly ClassicDeviceOnlineService $classicDeviceOnlineService,
        private readonly ProvisioningVsxCoordinator $vsxCoordinator,
    ) {}

    public function run(
        ProvisioningWorkflowDevice $workflowDevice,
        ProvisioningStep $step,
        CentralAPIHelper $centralAPIHelper,
    ): ProvisioningStepResult {
        $workflowDevice->loadMissing('device.site', 'device.interfaces', 'workflow.deployment.client', 'workflow.workflowDevices.device', 'steps');
        $device = $workflowDevice->device;
        $client = $workflowDevice->workflow->deployment->client;
        $context = ProvisioningStepContext::forWorkflow($workflowDevice->workflow);

        if ($step->shouldSkipForDevice($device, $context)) {
            return ProvisioningStepResult::skipped($step->label().' not applicable for this device.');
        }

        $resolvedLicense = $this->resolveLicenseForDevice($workflowDevice);

        return match ($step) {
            ProvisioningStep::VerifyLicensing => $this->verifyAndAssignLicensingAction->execute($device, $client, $resolvedLicense),
            ProvisioningStep::PreprovisionGroup => $this->preprovisionDeviceToGroupAction->execute($device, $centralAPIHelper),
            ProvisioningStep::AssignDeviceFunction => $this->assignDeviceFunctionAction->execute($device, $centralAPIHelper),
            ProvisioningStep::WaitForOnline => $this->handleWaitForOnline($workflowDevice, $device, $centralAPIHelper),
            ProvisioningStep::AssociateSite => $this->associateDeviceToSiteAction->execute($device, $centralAPIHelper),
            ProvisioningStep::ResolveScopeId => $this->resolveDeviceScopeIdAction->execute($device, $centralAPIHelper),
            ProvisioningStep::NameDevice => $this->nameDeviceAction->execute($device, $centralAPIHelper),
            ProvisioningStep::ConfigureVlanInterfaces => $this->configureDeviceVlanInterfacesAction->execute($device, $centralAPIHelper),
            ProvisioningStep::CreateStackProfile => $this->handleCreateStackProfile($workflowDevice, $device, $centralAPIHelper),
            ProvisioningStep::WaitForVsfStackScope => $this->waitForVsfStackScopeAction->execute(
                $device,
                $centralAPIHelper,
                $this->previousScopeIdBeforeVsf($workflowDevice),
            ),
            ProvisioningStep::ConfigureLagInterfaces => $this->configureDeviceLagInterfacesAction->execute($device, $centralAPIHelper),
            ProvisioningStep::ConfigureEthernetInterfaces => $this->configureDeviceEthernetInterfacesAction->execute($device, $centralAPIHelper),
            ProvisioningStep::ConfigureMirrorSessions => $this->configureMirrorSessionAction->execute(
                $device,
                $centralAPIHelper,
                $context->mirrorFallbackMode,
            ),
            ProvisioningStep::ClearLocalOverrides => $this->clearLocalOverridesAction->execute($device, $centralAPIHelper),
        };
    }

    private function handleWaitForOnline(
        ProvisioningWorkflowDevice $workflowDevice,
        Device $device,
        CentralAPIHelper $centralAPIHelper,
    ): ProvisioningStepResult {
        $workflow = $workflowDevice->workflow;
        $switchStatuses = $this->classicDeviceOnlineService->workflowNeedsSwitchPoll($workflow)
            ? $this->classicDeviceOnlineService->fetchSwitchStatuses($centralAPIHelper)
            : [];
        $apStatuses = $this->classicDeviceOnlineService->workflowNeedsApPoll($workflow)
            ? $this->classicDeviceOnlineService->fetchApStatuses($centralAPIHelper)
            : [];

        if ($this->classicDeviceOnlineService->isDeviceUp($device, $switchStatuses, $apStatuses)) {
            return ProvisioningStepResult::completed('Device is online (Up).');
        }

        $status = $this->classicDeviceOnlineService->currentStatus($device, $switchStatuses, $apStatuses);

        return ProvisioningStepResult::retry("Waiting for device to come online (status: {$status}).");
    }

    private function handleCreateStackProfile(
        ProvisioningWorkflowDevice $workflowDevice,
        Device $device,
        CentralAPIHelper $centralAPIHelper,
    ): ProvisioningStepResult {
        if ($device->sku) {
            return $this->createVsfProfileAction->execute($device, $centralAPIHelper);
        }

        $context = ProvisioningStepContext::forWorkflow($workflowDevice->workflow);

        if (filled($device->vsx_profile) || ($context->vsxFallbackMode && CentralAPIHelper::deviceMatchesVsxNamePattern($device))) {
            return $this->vsxCoordinator->attemptVsxProfileCreation($workflowDevice, $centralAPIHelper, $context);
        }

        return ProvisioningStepResult::skipped('No stack profile required.');
    }

    /**
     * @return array{license_tag?: string, license_type?: string}
     */
    private function resolveLicenseForDevice(ProvisioningWorkflowDevice $workflowDevice): array
    {
        $config = $workflowDevice->workflow->licensing_config ?? [];
        $device = $workflowDevice->device;

        if (! empty($device->license_tag) && ! empty($device->license_type)) {
            return [
                'license_tag' => (string) $device->license_tag,
                'license_type' => (string) $device->license_type,
            ];
        }

        $perDevice = $config['per_device'] ?? [];
        if (isset($perDevice[$device->id])) {
            return [
                'license_tag' => (string) ($perDevice[$device->id]['license_tag'] ?? ''),
                'license_type' => (string) ($perDevice[$device->id]['license_type'] ?? ''),
            ];
        }

        return [
            'license_tag' => (string) ($config['license_tag'] ?? ''),
            'license_type' => (string) ($config['license_type'] ?? ''),
        ];
    }

    private function previousScopeIdBeforeVsf(ProvisioningWorkflowDevice $workflowDevice): ?string
    {
        $createStep = $workflowDevice->steps
            ->firstWhere('step_key', ProvisioningStep::CreateStackProfile->value);

        if ($createStep === null) {
            return null;
        }

        return $createStep->message !== null && str_contains($createStep->message, 'scope_before:')
            ? trim(str_replace('scope_before:', '', explode('|', $createStep->message)[0]))
            : null;
    }
}
