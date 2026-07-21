<?php

namespace App\Jobs;

use App\Helper\GreenLakeAPIHelper;
use App\Models\Task;
use Illuminate\Support\Facades\Log;
use Throwable;

class AddDevicesToGreenLakeInventoryJob extends BaseTaskJob
{
    /**
     * @param  array<int, array{id: int|string, serial: string, mac_address: string}>  $devices
     */
    public function __construct(
        public array $devices,
        public Task $task,
        public GreenLakeAPIHelper $greenLakeAPIHelper,
    ) {
        $this->initTaskTiming($task, defaultWaitMinutes: 1);
    }

    public function handle(): void
    {
        $this->handleSafely(function (): void {
            $this->addDevices();
        }, 'Add devices to GreenLake inventory');
    }

    public function addDevices(): void
    {
        $this->task->refresh();

        $payloadDevices = [];
        foreach ($this->devices as $device) {
            $serial = trim((string) ($device['serial'] ?? ''));
            $mac = trim((string) ($device['mac_address'] ?? ''));
            if ($serial === '' || $mac === '') {
                $this->markDeviceFailed($device['id'] ?? null);
                $this->task->processTaskStatusLog(
                    "\nSkipped device ".($serial !== '' ? $serial : (string) ($device['id'] ?? '')).': missing serial or mac_address.'
                );

                continue;
            }
            $payloadDevices[] = [
                'id' => $device['id'],
                'serial' => $serial,
                'mac_address' => $mac,
            ];
        }

        if ($payloadDevices === []) {
            $this->failTask('No devices with serial and mac_address available to add to GreenLake.');

            return;
        }

        $tags = $this->task->greenlake_tags;
        $tags = is_array($tags) && $tags !== [] ? $tags : null;

        $result = $this->greenLakeAPIHelper->addNetworkDevices(
            array_map(function (array $device) use ($tags): array {
                $entry = [
                    'serial' => $device['serial'],
                    'mac_address' => $device['mac_address'],
                ];
                if ($tags !== null) {
                    $entry['tags'] = $tags;
                }

                return $entry;
            }, $payloadDevices),
        );

        $perSerial = $result['results'] ?? [];
        $succeededDevices = [];

        foreach ($payloadDevices as $device) {
            $serial = $device['serial'];
            $ok = $result['success'] === true;
            if (array_key_exists($serial, $perSerial)) {
                $ok = $perSerial[$serial] === true;
            }

            if ($ok) {
                $succeededDevices[] = $device;
                $this->task->processTaskStatusLog("\nAdded device {$serial} to GreenLake inventory.");
            } else {
                $this->markDeviceFailed($device['id']);
                $detail = $result['error'] ?? 'GreenLake add failed.';
                $this->task->processTaskStatusLog("\nFailed to add device {$serial}: {$detail}");
            }
        }

        if ($result['success'] !== true && ($result['error'] ?? null) !== null) {
            Log::error('Failed to add devices to GreenLake: '.$result['error']);
        }

        $locationId = trim((string) ($this->task->greenlake_location_id ?? ''));
        $locationName = trim((string) ($this->task->greenlake_location_name ?? ''));
        if ($locationName === '') {
            $locationName = $locationId;
        }

        if ($succeededDevices !== [] && ($tags !== null || $locationId !== '')) {
            $this->applyPostAddMetadata($succeededDevices, $tags, $locationId, $locationName);
        } else {
            foreach ($succeededDevices as $device) {
                $this->task->devices()->find($device['id'])?->pivot?->update(['status' => 'COMPLETED']);
            }
        }

        $this->task->load('devices');

        if ($this->task->allTrackedItemsCompleted()) {
            $this->task->update(['status' => 'COMPLETED']);
        } elseif ($this->allTaskDevicesFailed()) {
            $this->failTask('All devices failed to add to GreenLake inventory.');
        }
    }

    /**
     * Apply tags and/or location via v2beta1 after a successful inventory add.
     * Create-time tags are not reliably persisted by GreenLake, so tags are patched after add.
     *
     * @param  array<int, array{id: int|string, serial: string, mac_address: string}>  $succeededDevices
     * @param  array<string, string>|null  $tags
     */
    private function applyPostAddMetadata(
        array $succeededDevices,
        ?array $tags,
        string $locationId,
        string $locationName,
    ): void {
        $resolved = $this->resolveGreenLakeIds($succeededDevices, $tags !== null ? 'tag update' : 'location assignment');
        if ($resolved === null) {
            return;
        }

        ['deviceIds' => $deviceIds, 'localIdByGreenLakeId' => $localIdByGreenLakeId] = $resolved;

        if ($tags !== null) {
            $assignResult = $this->greenLakeAPIHelper->assignTagsToDevices($deviceIds, $tags);
            $perDevice = $assignResult['results'] ?? [];
            $stillOk = [];

            foreach ($localIdByGreenLakeId as $greenlakeId => $device) {
                $ok = $assignResult['success'] === true;
                if (array_key_exists($greenlakeId, $perDevice)) {
                    $ok = $perDevice[$greenlakeId] === true;
                }

                if ($ok) {
                    $stillOk[$greenlakeId] = $device;
                    $this->task->processTaskStatusLog(
                        "\nApplied GreenLake tags to device {$device['serial']}."
                    );
                } else {
                    $this->markDeviceFailed($device['id']);
                    $detail = $assignResult['error'] ?? 'GreenLake tag update failed.';
                    $this->task->processTaskStatusLog(
                        "\nDevice {$device['serial']} was added to inventory, but tag update failed: {$detail}"
                    );
                }
            }

            if ($assignResult['success'] !== true && ($assignResult['error'] ?? null) !== null) {
                Log::error('Failed to assign GreenLake tags after inventory add: '.$assignResult['error']);
            }

            $localIdByGreenLakeId = $stillOk;
            $deviceIds = array_keys($stillOk);
        }

        if ($locationId !== '' && $deviceIds !== []) {
            $assignResult = $this->greenLakeAPIHelper->assignLocationToDevices($deviceIds, $locationId);
            $perDevice = $assignResult['results'] ?? [];

            foreach ($localIdByGreenLakeId as $greenlakeId => $device) {
                $ok = $assignResult['success'] === true;
                if (array_key_exists($greenlakeId, $perDevice)) {
                    $ok = $perDevice[$greenlakeId] === true;
                }

                if ($ok) {
                    $this->task->devices()->find($device['id'])?->pivot?->update(['status' => 'COMPLETED']);
                    $this->task->processTaskStatusLog(
                        "\nAssigned location \"{$locationName}\" to device {$device['serial']}."
                    );
                } else {
                    $this->markDeviceFailed($device['id']);
                    $detail = $assignResult['error'] ?? 'GreenLake location assignment failed.';
                    $this->task->processTaskStatusLog(
                        "\nDevice {$device['serial']} was added to inventory, but location assignment failed: {$detail}"
                    );
                }
            }

            if ($assignResult['success'] !== true && ($assignResult['error'] ?? null) !== null) {
                Log::error('Failed to assign GreenLake location: '.$assignResult['error']);
            }

            return;
        }

        foreach ($localIdByGreenLakeId as $device) {
            $this->task->devices()->find($device['id'])?->pivot?->update(['status' => 'COMPLETED']);
        }
    }

    /**
     * @param  array<int, array{id: int|string, serial: string, mac_address: string}>  $succeededDevices
     * @return array{deviceIds: array<int, string>, localIdByGreenLakeId: array<string, array{id: int|string, serial: string, mac_address: string}>}|null
     */
    private function resolveGreenLakeIds(array $succeededDevices, string $purpose): ?array
    {
        $serials = array_map(fn (array $device): string => $device['serial'], $succeededDevices);
        $idsBySerial = [];

        // Newly added devices can take a moment to appear in the GreenLake device list.
        foreach ([0, 2, 5] as $delaySeconds) {
            if ($delaySeconds > 0) {
                sleep($delaySeconds);
            }

            $idsBySerial = $this->greenLakeAPIHelper->deviceIdsBySerial($serials);
            if (GreenLakeAPIHelper::isCollectError($idsBySerial)) {
                $error = (string) ($idsBySerial['error'] ?? 'Failed to resolve GreenLake device ids.');
                foreach ($succeededDevices as $device) {
                    $this->markDeviceFailed($device['id']);
                    $this->task->processTaskStatusLog(
                        "\nDevice {$device['serial']} was added to inventory, but {$purpose} failed: {$error}"
                    );
                }

                return null;
            }

            $resolvedCount = 0;
            foreach ($succeededDevices as $device) {
                $greenlakeId = $idsBySerial[$device['serial']]
                    ?? $idsBySerial[strtoupper($device['serial'])]
                    ?? null;
                if (is_string($greenlakeId) && trim($greenlakeId) !== '') {
                    $resolvedCount++;
                }
            }

            if ($resolvedCount === count($succeededDevices)) {
                break;
            }
        }

        $deviceIds = [];
        $localIdByGreenLakeId = [];
        foreach ($succeededDevices as $device) {
            $greenlakeId = trim((string) (
                $idsBySerial[$device['serial']]
                ?? $idsBySerial[strtoupper($device['serial'])]
                ?? ''
            ));
            if ($greenlakeId === '') {
                $this->markDeviceFailed($device['id']);
                $this->task->processTaskStatusLog(
                    "\nDevice {$device['serial']} was added to inventory, but its GreenLake device id could not be resolved for {$purpose}."
                );

                continue;
            }
            $deviceIds[] = $greenlakeId;
            $localIdByGreenLakeId[$greenlakeId] = $device;
        }

        if ($deviceIds === []) {
            return null;
        }

        return [
            'deviceIds' => $deviceIds,
            'localIdByGreenLakeId' => $localIdByGreenLakeId,
        ];
    }

    public function failed(?Throwable $exception): void
    {
        $this->logFailedException($exception);
        $this->markAllDevicesFailed();
        $this->failTask('Failed adding devices to GreenLake inventory. Task timed out or failed.');
    }
}
