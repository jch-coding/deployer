<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Task;
use App\Support\ClassicSiteTaskPayload;
use Illuminate\Http\Client\Response;

class UpdateSiteJob extends BaseTaskJob
{
    /**
     * @param  array<string, mixed>  $siteDetail
     */
    public function __construct(
        public array $siteDetail,
        public Task $task,
        public CentralAPIHelper $centralAPIHelper,
    ) {
        $this->initTaskTiming($task, defaultWaitMinutes: 1);
    }

    public function handle(): void
    {
        $this->handleSafely(function (): void {
            $siteName = (string) ($this->siteDetail['site_name'] ?? '');
            $siteId = $this->siteDetail['site_id'] ?? null;

            if ($siteId === null || trim((string) $siteId) === '') {
                $this->markSiteFailed($siteName, 'Failed to update site '.$siteName.': missing Classic site_id.');

                return;
            }

            $body = ClassicSiteTaskPayload::buildClassicSiteBody($this->siteDetail);
            $response = $this->centralAPIHelper->classic_update_site($siteId, $body);

            if (is_array($response) || ! $response instanceof Response || ! $response->successful()) {
                $detail = $this->formatClassicError($response);
                $this->markSiteFailed($siteName, 'Failed to update site '.$siteName.': '.$detail);

                return;
            }

            $this->markSiteCompleted($siteName, 'Updated site '.$siteName);
        }, 'Update site');
    }

    private function markSiteCompleted(string $siteName, string $message): void
    {
        $this->updateSiteStatus($siteName, 'COMPLETED');
        $this->task->processTaskStatusLog($message);
        $this->finalizeTaskIfDone();
    }

    private function markSiteFailed(string $siteName, string $message): void
    {
        $this->updateSiteStatus($siteName, 'FAILED');
        $this->task->processTaskStatusLog($message, true);
        $this->finalizeTaskIfDone();
    }

    private function updateSiteStatus(string $siteName, string $status): void
    {
        $details = $this->task->site_details ?? [];
        if (! is_array($details)) {
            return;
        }

        foreach ($details as $index => $site) {
            if (! is_array($site)) {
                continue;
            }
            if ((string) ($site['site_name'] ?? '') === $siteName) {
                $details[$index]['status'] = $status;
            }
        }

        $this->task->update(['site_details' => $details]);
    }

    private function finalizeTaskIfDone(): void
    {
        $this->task->refresh();
        $details = $this->task->site_details ?? [];
        if (! is_array($details)) {
            return;
        }

        $derivedStatus = ClassicSiteTaskPayload::deriveTaskStatusFromSiteDetails($details);
        if ($derivedStatus !== null) {
            $this->task->update(['status' => $derivedStatus]);
        }
    }

    private function formatClassicError(mixed $response): string
    {
        if (is_array($response)) {
            return $response['error'] ?? json_encode($response);
        }

        if ($response instanceof Response) {
            $json = $response->json();
            if (is_array($json) && isset($json['description'])) {
                return (string) $json['description'];
            }
            if (is_array($json) && isset($json['message'])) {
                return (string) $json['message'];
            }

            return $response->body();
        }

        return 'unknown error';
    }
}
