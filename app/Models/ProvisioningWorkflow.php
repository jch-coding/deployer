<?php

namespace App\Models;

use App\Enums\OnlineDetectionMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProvisioningWorkflow extends Model
{
    protected $casts = [
        'licensing_config' => 'array',
        'steps' => 'array',
        'classic_poller_active' => 'boolean',
        'online_detection_mode' => OnlineDetectionMode::class,
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function onlineDetectionMode(): OnlineDetectionMode
    {
        return $this->online_detection_mode ?? OnlineDetectionMode::Poll;
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ProvisioningWorkflowTemplate::class, 'provisioning_workflow_template_id');
    }

    public function workflowDevices(): HasMany
    {
        return $this->hasMany(ProvisioningWorkflowDevice::class);
    }

    /**
     * @return list<string>|null
     */
    public function customStepKeys(): ?array
    {
        if (! is_array($this->steps) || $this->steps === []) {
            return null;
        }

        return array_values(array_map('strval', $this->steps));
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['completed', 'cancelled'], true);
    }

    /**
     * @return array{in_progress: int, completed: int, failed: int}
     */
    public function summaryCounts(): array
    {
        $counts = $this->workflowDevices()
            ->selectRaw('overall_status, count(*) as aggregate')
            ->groupBy('overall_status')
            ->pluck('aggregate', 'overall_status');

        return [
            'in_progress' => (int) ($counts['in_progress'] ?? 0),
            'completed' => (int) ($counts['completed'] ?? 0),
            'failed' => (int) ($counts['failed'] ?? 0),
        ];
    }

    public function refreshOverallStatus(): void
    {
        if ($this->isTerminal()) {
            return;
        }

        $summary = $this->summaryCounts();
        if ($summary['in_progress'] === 0 && $summary['failed'] === 0 && $summary['completed'] > 0) {
            $this->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        }
    }
}
