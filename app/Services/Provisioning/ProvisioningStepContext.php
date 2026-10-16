<?php

namespace App\Services\Provisioning;

use App\Helper\CentralAPIHelper;
use App\Models\ProvisioningWorkflow;
use Illuminate\Support\Collection;

class ProvisioningStepContext
{
    /**
     * @param  array<int, string>  $provisioningNames
     * @param  array{
     *     location_id?: string|null,
     *     location_name?: string|null,
     *     application_id?: string|null,
     *     application_region?: string|null,
     *     application_name?: string|null,
     *     tags?: array<string, string>|null
     * }  $greenlakeConfig
     * @param  list<string>  $centralStaticTags
     * @param  array{
     *     site_name?: string|null,
     *     site_scope_id?: string|null,
     *     network_ipv4_address?: string|null,
     *     alias_name?: string|null,
     *     status?: string|null,
     *     message?: string|null
     * }  $networkAliasConfig
     */
    public function __construct(
        public readonly bool $vsxFallbackMode = false,
        public readonly bool $mirrorFallbackMode = false,
        public readonly array $provisioningNames = [],
        public readonly bool $onlyUpdateDifferentNames = false,
        public readonly array $greenlakeConfig = [],
        public readonly array $centralStaticTags = [],
        public readonly array $networkAliasConfig = [],
    ) {}

    public static function forWorkflow(ProvisioningWorkflow $workflow): self
    {
        $workflow->loadMissing('workflowDevices.device');
        /** @var Collection<int, \App\Models\Device> $devices */
        $devices = $workflow->workflowDevices->map(fn ($row) => $row->device);

        $licensingConfig = is_array($workflow->licensing_config) ? $workflow->licensing_config : [];
        $namingConfig = is_array($licensingConfig['naming'] ?? null) ? $licensingConfig['naming'] : [];
        $provisioningNames = is_array($namingConfig['per_device'] ?? null) ? $namingConfig['per_device'] : [];
        $onlyUpdateDifferentNames = (bool) ($namingConfig['only_update_different_names'] ?? false);

        $greenlakeConfig = is_array($licensingConfig['greenlake'] ?? null) ? $licensingConfig['greenlake'] : [];
        $centralStaticTags = is_array($licensingConfig['central_static_tags'] ?? null)
            ? array_values(array_filter(
                array_map(fn ($tag) => trim((string) $tag), $licensingConfig['central_static_tags']),
                fn (string $tag): bool => $tag !== ''
            ))
            : [];
        $networkAliasConfig = is_array($licensingConfig['network_alias'] ?? null)
            ? $licensingConfig['network_alias']
            : [];

        return new self(
            CentralAPIHelper::deploymentUsesVsxFallbackMode($devices),
            CentralAPIHelper::deploymentUsesMirrorFallbackMode($devices),
            $provisioningNames,
            $onlyUpdateDifferentNames,
            $greenlakeConfig,
            $centralStaticTags,
            $networkAliasConfig,
        );
    }
}
