<?php

namespace App\Models;

use App\Enums\ProvisioningStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProvisioningWorkflowDevice extends Model
{
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ProvisioningWorkflow::class, 'provisioning_workflow_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ProvisioningWorkflowDeviceStep::class);
    }

    public function stepFor(ProvisioningStep $step): ?ProvisioningWorkflowDeviceStep
    {
        return $this->steps->firstWhere('step_key', $step->value);
    }

    public function applicableStepsCount(): int
    {
        $this->loadMissing('device', 'steps');

        return $this->steps
            ->filter(fn (ProvisioningWorkflowDeviceStep $row) => $row->status !== 'skipped')
            ->count();
    }

    public function completedStepsCount(): int
    {
        return $this->steps()->where('status', 'completed')->count();
    }

    public function progressPercent(): int
    {
        $total = $this->applicableStepsCount();
        if ($total === 0) {
            return 0;
        }

        return (int) round(($this->completedStepsCount() / $total) * 100);
    }

    public function isTerminal(): bool
    {
        return in_array($this->overall_status, ['completed', 'failed'], true);
    }

    public function updateStatusMessage(string $message): void
    {
        $this->update(['status_message' => $message]);
    }
}
