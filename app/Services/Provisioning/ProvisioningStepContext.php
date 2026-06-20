<?php

namespace App\Services\Provisioning;

use App\Helper\CentralAPIHelper;
use App\Models\ProvisioningWorkflow;
use Illuminate\Support\Collection;

class ProvisioningStepContext
{
    public function __construct(
        public readonly bool $vsxFallbackMode = false,
        public readonly bool $mirrorFallbackMode = false,
        /** @var array<int, string> */
        public readonly array $provisioningNames = [],
    ) {}

    public static function forWorkflow(ProvisioningWorkflow $workflow): self
    {
        $workflow->loadMissing('workflowDevices.device');
        /** @var Collection<int, \App\Models\Device> $devices */
        $devices = $workflow->workflowDevices->map(fn ($row) => $row->device);

        $namingConfig = $workflow->licensing_config['naming'] ?? [];
        $provisioningNames = is_array($namingConfig['per_device'] ?? null) ? $namingConfig['per_device'] : [];

        return new self(
            CentralAPIHelper::deploymentUsesVsxFallbackMode($devices),
            CentralAPIHelper::deploymentUsesMirrorFallbackMode($devices),
            $provisioningNames,
        );
    }
}
