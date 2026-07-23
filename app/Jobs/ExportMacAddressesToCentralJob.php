<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Task;
use App\Support\MacAddress;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExportMacAddressesToCentralJob extends BaseTaskJob
{
    /**
     * @param  array<int, array{id: int|string, mac_address: string, serial?: string}>  $devices
     */
    public function __construct(
        public array $devices,
        public Task $task,
        public CentralAPIHelper $centralAPIHelper,
    ) {
        $this->initTaskTiming($task, defaultWaitMinutes: 1);
    }

    public function handle(): void
    {
        $this->handleSafely(function (): void {
            $this->exportMacAddresses();
        }, 'Export MAC addresses to Central');
    }

    public function exportMacAddresses(): void
    {
        $this->task->refresh();

        $payloadDevices = [];
        foreach ($this->devices as $device) {
            $mac = MacAddress::toCentralCsvFormat((string) ($device['mac_address'] ?? ''));
            if ($mac === null) {
                $this->markDeviceFailed($device['id'] ?? null);
                $label = trim((string) ($device['serial'] ?? '')) !== ''
                    ? (string) $device['serial']
                    : (string) ($device['id'] ?? '');
                $this->task->processTaskStatusLog("\nSkipped device {$label}: missing or invalid mac_address.");

                continue;
            }

            $payloadDevices[] = [
                'id' => $device['id'],
                'mac_address' => $mac,
                'serial' => (string) ($device['serial'] ?? ''),
            ];
        }

        if ($payloadDevices === []) {
            $this->failTask('No devices with a valid mac_address available to export to Central.');

            return;
        }

        $tags = $this->task->central_static_tags;
        $tags = is_array($tags)
            ? array_values(array_filter(
                array_map(fn ($tag) => trim((string) $tag), $tags),
                fn (string $tag): bool => $tag !== ''
            ))
            : [];

        $csv = $this->buildMacRegistrationCsv($payloadDevices, $tags);
        $result = $this->centralAPIHelper->importMacCsvFile($csv);

        if (($result['success'] ?? false) !== true) {
            $detail = (string) ($result['error'] ?? 'Central MAC CSV import failed.');
            Log::error('Failed to export MAC addresses to Central: '.$detail);
            foreach ($payloadDevices as $device) {
                $this->markDeviceFailed($device['id']);
            }
            $this->task->processTaskStatusLog("\nFailed to import MAC CSV into Central: {$detail}");
            $this->failTask('Failed to export MAC addresses to Central.');

            return;
        }

        $jobIds = $result['job_ids'] ?? [];
        $jobIdLog = $jobIds !== [] ? ' Job ID(s): '.implode(', ', $jobIds).'.' : '';

        foreach ($payloadDevices as $device) {
            $this->task->devices()->find($device['id'])?->pivot?->update(['status' => 'COMPLETED']);
            $label = $device['serial'] !== '' ? $device['serial'] : $device['mac_address'];
            $this->task->processTaskStatusLog("\nExported MAC {$device['mac_address']} ({$label}) to Central NAC.");
        }

        $this->task->processTaskStatusLog("\nCentral MAC CSV import started.{$jobIdLog}");

        $this->task->load('devices');

        if ($this->task->allTrackedItemsCompleted()) {
            $this->task->update(['status' => 'COMPLETED']);
        } elseif ($this->allTaskDevicesFailed()) {
            $this->failTask('All devices failed to export MAC addresses to Central.');
        }
    }

    /**
     * @param  array<int, array{id: int|string, mac_address: string, serial: string}>  $devices
     * @param  list<string>  $tags
     */
    public function buildMacRegistrationCsv(array $devices, array $tags): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open temporary stream for MAC CSV.');
        }

        fputcsv($handle, ['MAC Address', 'Client Name', 'Enabled', 'Static Tags']);

        $staticTags = implode(', ', $tags);

        foreach ($devices as $device) {
            fputcsv($handle, [
                $device['mac_address'],
                '',
                'true',
                $staticTags,
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }

    public function failed(?Throwable $exception): void
    {
        $this->logFailedException($exception);
        $this->markAllDevicesFailed();
        $this->failTask('Failed exporting MAC addresses to Central. Task timed out or failed.');
    }
}
