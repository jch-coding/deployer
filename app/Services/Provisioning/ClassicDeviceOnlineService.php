<?php

namespace App\Services\Provisioning;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\ProvisioningWorkflow;

class ClassicDeviceOnlineService
{
    /**
     * @return array<string, string> serial => status
     */
    public function fetchSwitchStatuses(CentralAPIHelper $centralAPIHelper): array
    {
        $result = $centralAPIHelper->classic_collect_all_switches();
        if (array_key_exists('error', $result)) {
            return [];
        }

        $switches = $result['switches'] ?? [];

        return $this->indexBySerial($switches);
    }

    /**
     * @return array<string, string> serial => status
     */
    public function fetchApStatuses(CentralAPIHelper $centralAPIHelper): array
    {
        $result = $centralAPIHelper->classic_collect_all_aps();
        if (array_key_exists('error', $result)) {
            return [];
        }

        $aps = $result['aps'] ?? [];

        return $this->indexBySerial($aps);
    }

    public function isDeviceUp(Device $device, array $switchStatuses, array $apStatuses): bool
    {
        $serial = (string) $device->serial;
        $function = (string) $device->device_function;

        if (str_contains($function, 'SWITCH')) {
            return ($switchStatuses[$serial] ?? '') === 'Up';
        }

        if (str_contains($function, 'AP')) {
            return ($apStatuses[$serial] ?? '') === 'Up';
        }

        return ($switchStatuses[$serial] ?? $apStatuses[$serial] ?? '') === 'Up';
    }

    public function currentStatus(Device $device, array $switchStatuses, array $apStatuses): string
    {
        $serial = (string) $device->serial;
        $function = (string) $device->device_function;

        if (str_contains($function, 'SWITCH')) {
            return (string) ($switchStatuses[$serial] ?? 'Unknown');
        }

        if (str_contains($function, 'AP')) {
            return (string) ($apStatuses[$serial] ?? 'Unknown');
        }

        return (string) ($switchStatuses[$serial] ?? $apStatuses[$serial] ?? 'Unknown');
    }

    public function workflowNeedsSwitchPoll(ProvisioningWorkflow $workflow): bool
    {
        return $workflow->workflowDevices()
            ->where('overall_status', 'in_progress')
            ->whereHas('steps', fn ($query) => $query
                ->where('step_key', 'wait_for_online')
                ->where('status', 'in_progress'))
            ->whereHas('device', fn ($query) => $query->where('device_function', 'like', '%SWITCH%'))
            ->exists();
    }

    public function workflowNeedsApPoll(ProvisioningWorkflow $workflow): bool
    {
        return $workflow->workflowDevices()
            ->where('overall_status', 'in_progress')
            ->whereHas('steps', fn ($query) => $query
                ->where('step_key', 'wait_for_online')
                ->where('status', 'in_progress'))
            ->whereHas('device', fn ($query) => $query->where('device_function', 'like', '%AP%'))
            ->exists();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, string>
     */
    private function indexBySerial(array $items): array
    {
        $indexed = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $serial = (string) ($item['serial'] ?? '');
            if ($serial === '') {
                continue;
            }
            $indexed[$serial] = (string) ($item['status'] ?? 'Unknown');
        }

        return $indexed;
    }
}
