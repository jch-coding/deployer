<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Task;
use App\Support\NetworkAliasPayload;
use Illuminate\Http\Client\Response;

class CreateNetworkAliasJob extends BaseTaskJob
{
    public function __construct(
        public Task $task,
        public CentralAPIHelper $centralAPIHelper,
    ) {
        $this->initTaskTiming($task, defaultWaitMinutes: 1);
    }

    public function handle(): void
    {
        $this->handleSafely(function (): void {
            $details = is_array($this->task->site_details) ? $this->task->site_details : [];
            $siteName = trim((string) ($details['site_name'] ?? ''));
            $scopeId = trim((string) ($details['site_scope_id'] ?? ''));
            $networkIpv4 = trim((string) ($details['network_ipv4_address'] ?? ''));
            $aliasName = trim((string) ($details['alias_name'] ?? NetworkAliasPayload::ALIAS_NAME));

            if ($siteName === '' || $scopeId === '' || $networkIpv4 === '') {
                $this->task->processTaskStatusLog('Missing site name, scope id, or network address for network alias creation.', true);
                $this->task->update(['status' => 'FAILED']);

                return;
            }

            $body = NetworkAliasPayload::buildBody($networkIpv4);
            $response = $this->centralAPIHelper->create_alias(
                $aliasName,
                $body,
                $scopeId,
                'CAMPUS_AP',
            );

            if (is_array($response) || ! $response instanceof Response || ! $response->successful()) {
                $detail = $this->formatError($response);
                $this->task->processTaskStatusLog(
                    "Failed to create network alias {$aliasName} for site {$siteName}: {$detail}",
                    true,
                );
                $this->task->update(['status' => 'FAILED']);

                return;
            }

            $this->task->processTaskStatusLog(
                "Created network alias {$aliasName} ({$networkIpv4}) at site {$siteName}.",
            );
            $this->task->update(['status' => 'COMPLETED']);
        }, 'Create network alias');
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
