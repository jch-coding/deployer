<?php

namespace App\Actions\Provisioning;

use App\Helper\CentralAPIHelper;
use App\Models\ProvisioningWorkflow;
use App\Services\Provisioning\ProvisioningStepResult;
use App\Support\NetworkAliasPayload;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

class CreateNetworkAliasAction
{
    public function execute(ProvisioningWorkflow $workflow, CentralAPIHelper $centralAPIHelper): ProvisioningStepResult
    {
        $lock = Cache::lock("provisioning-workflow:{$workflow->id}:create-network-alias", 120);

        try {
            return $lock->block(60, function () use ($workflow, $centralAPIHelper): ProvisioningStepResult {
                $workflow->refresh();
                $config = is_array($workflow->licensing_config) ? $workflow->licensing_config : [];
                $aliasConfig = is_array($config['network_alias'] ?? null) ? $config['network_alias'] : [];

                if (($aliasConfig['status'] ?? null) === 'completed') {
                    $existingMessage = trim((string) ($aliasConfig['message'] ?? ''));

                    return ProvisioningStepResult::completed(
                        $existingMessage !== '' ? $existingMessage : 'Network alias already created.'
                    );
                }

                $siteName = trim((string) ($aliasConfig['site_name'] ?? ''));
                $scopeId = trim((string) ($aliasConfig['site_scope_id'] ?? ''));
                $networkIpv4 = trim((string) ($aliasConfig['network_ipv4_address'] ?? ''));
                $aliasName = trim((string) ($aliasConfig['alias_name'] ?? NetworkAliasPayload::ALIAS_NAME));
                if ($aliasName === '') {
                    $aliasName = NetworkAliasPayload::ALIAS_NAME;
                }

                if ($siteName === '' || $scopeId === '' || $networkIpv4 === '') {
                    return ProvisioningStepResult::failed(
                        'Missing site name, scope id, or network address for network alias creation.'
                    );
                }

                $body = NetworkAliasPayload::buildBody($networkIpv4);
                $response = $centralAPIHelper->create_alias(
                    $aliasName,
                    $body,
                    $scopeId,
                    'CAMPUS_AP',
                );

                if (is_array($response) || ! $response instanceof Response || ! $response->successful()) {
                    $detail = $this->formatError($response);
                    $message = "Failed to create network alias {$aliasName} for site {$siteName}: {$detail}";
                    $this->persistAliasStatus($workflow, $config, $aliasConfig, 'failed', $message);

                    return ProvisioningStepResult::failed($message);
                }

                $message = "Created network alias {$aliasName} ({$networkIpv4}) at site {$siteName}.";
                $this->persistAliasStatus($workflow, $config, $aliasConfig, 'completed', $message);

                return ProvisioningStepResult::completed($message);
            });
        } catch (LockTimeoutException) {
            return ProvisioningStepResult::retry('Waiting for network alias creation lock...');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $aliasConfig
     */
    private function persistAliasStatus(
        ProvisioningWorkflow $workflow,
        array $config,
        array $aliasConfig,
        string $status,
        string $message,
    ): void {
        $aliasConfig['status'] = $status;
        $aliasConfig['message'] = $message;
        $config['network_alias'] = $aliasConfig;
        $workflow->update(['licensing_config' => $config]);
    }

    private function formatError(mixed $response): string
    {
        if (is_array($response)) {
            return (string) ($response['error'] ?? json_encode($response));
        }

        if ($response instanceof Response) {
            $message = $response->json('message')
                ?? $response->json('description')
                ?? $response->body();

            return trim((string) $message) !== ''
                ? (string) $message
                : 'HTTP '.$response->status();
        }

        return 'Unknown error';
    }
}
