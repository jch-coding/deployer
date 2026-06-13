<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Task;

class SetCxFirmwareComplianceForGroup extends BaseTaskJob
{
    public function __construct(
        public string $group_name,
        public string $firmware_compliance_version,
        public Task $task,
        public CentralAPIHelper $centralAPIHelper,
    ) {
        $this->initTaskTiming($task, defaultWaitMinutes: 1);
    }

    public function handle(): void
    {
        $this->handleSafely(function (): void {
            $version = trim($this->firmware_compliance_version);
            if ($version === '') {
                return;
            }

            $response = $this->centralAPIHelper->classic_post_firmware_compliance([
                'device_type' => 'CX',
                'group' => $this->group_name,
                'firmware_compliance_version' => $version,
            ]);

            if (is_array($response) && array_key_exists('error', $response)) {
                $message = 'Failed to set firmware compliance for '.$this->group_name.': '.$response['error'];
                $this->task->processTaskStatusLog($message, true);
                $this->task->update(['status' => 'FAILED']);

                return;
            }

            if (! $response->ok()) {
                $description = $response->json('description') ?? $response->body();
                $message = 'Failed to set firmware compliance for '.$this->group_name.' '.$description;
                $this->task->processTaskStatusLog($message, true);
                $this->task->update(['status' => 'FAILED']);

                return;
            }

            $this->task->processTaskStatusLog(
                'Set firmware compliance for '.$this->group_name.' to '.$version
            );
        });
    }
}
