<?php

namespace App\Models;

use App\Enums\ProvisioningStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProvisioningWorkflowDeviceStep extends Model
{
    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function workflowDevice(): BelongsTo
    {
        return $this->belongsTo(ProvisioningWorkflowDevice::class, 'provisioning_workflow_device_id');
    }

    public function enumStep(): ProvisioningStep
    {
        return ProvisioningStep::from($this->step_key);
    }

    public function markInProgress(string $message = ''): void
    {
        $this->update([
            'status' => 'in_progress',
            'message' => $message !== '' ? $message : $this->message,
            'started_at' => $this->started_at ?? now(),
            'attempts' => $this->attempts + 1,
        ]);
    }

    public function markCompleted(string $message = ''): void
    {
        $this->update([
            'status' => 'completed',
            'message' => $message !== '' ? $message : $this->message,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $message): void
    {
        $this->update([
            'status' => 'failed',
            'message' => $message,
            'completed_at' => now(),
        ]);
    }

    public function markSkipped(string $message = ''): void
    {
        $this->update([
            'status' => 'skipped',
            'message' => $message !== '' ? $message : 'Skipped',
            'completed_at' => now(),
        ]);
    }

    public function resetToPending(): void
    {
        $this->update([
            'status' => 'pending',
            'message' => null,
            'attempts' => 0,
            'started_at' => null,
            'completed_at' => null,
        ]);
    }
}
