<?php

namespace App\Services;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\Site;
use App\Models\Task;
use Illuminate\Support\Collection;

class DeviceCentralVerifier
{
    /**
     * @return array{
     *     device_errors: array<int, array{device_id: int, device_name: string, message: string}>,
     *     results: array<int, array{
     *         device_id: int,
     *         device_name: string,
     *         serial: string,
     *         ok: bool,
     *         missing_in_central: bool,
     *         diff: array<int, array{path: string, expected: mixed, actual: mixed}>,
     *         details: array<int, array{path: string, expected: mixed, actual: mixed}>
     *     }>
     * }
     */
    public function verify(Task $task, CentralAPIHelper $helper): array
    {
        $devices = $task->devices()->with('site')->get();

        return $this->verifyDevices($devices, $task->task_type, $helper);
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @return array{
     *     device_errors: array<int, array{device_id: int, device_name: string, message: string}>,
     *     results: array<int, array{
     *         device_id: int,
     *         device_name: string,
     *         serial: string,
     *         ok: bool,
     *         missing_in_central: bool,
     *         diff: array<int, array{path: string, expected: mixed, actual: mixed}>,
     *         details: array<int, array{path: string, expected: mixed, actual: mixed}>
     *     }>
     * }
     */
    public function verifyDevices(Collection $devices, string $taskType, CentralAPIHelper $helper): array
    {
        $deviceErrors = [];
        $results = [];
        $centralBySite = [];

        foreach ($devices->groupBy('site_id') as $siteId => $siteDevices) {
            /** @var Device $first */
            $first = $siteDevices->first();
            $site = $first->site;

            if ($site === null) {
                foreach ($siteDevices as $device) {
                    $deviceErrors[] = [
                        'device_id' => $device->id,
                        'device_name' => $device->name,
                        'message' => 'No site assigned to this device.',
                    ];
                    $results[] = $this->errorResult($device, $taskType, missingInCentral: true);
                }

                continue;
            }

            if (blank($site->scope_id)) {
                foreach ($siteDevices as $device) {
                    $deviceErrors[] = [
                        'device_id' => $device->id,
                        'device_name' => $device->name,
                        'message' => 'Site scope ID not available for site '.$site->name.'.',
                    ];
                    $results[] = $this->errorResult($device, $taskType, missingInCentral: true);
                }

                continue;
            }

            if (! array_key_exists((int) $siteId, $centralBySite)) {
                $fetch = $this->fetchDevicesBySite($helper, $site);
                if (isset($fetch['error'])) {
                    foreach ($siteDevices as $device) {
                        $deviceErrors[] = [
                            'device_id' => $device->id,
                            'device_name' => $device->name,
                            'message' => $fetch['error'],
                        ];
                        $results[] = $this->errorResult($device, $taskType, missingInCentral: true);
                    }

                    continue;
                }
                $centralBySite[(int) $siteId] = $fetch['items'];
            }

            $centralItems = $centralBySite[(int) $siteId];

            foreach ($siteDevices as $device) {
                $expected = $this->buildExpectedFields($device, $site, $taskType);
                $centralItem = $centralItems[$device->serial] ?? null;

                if ($centralItem === null) {
                    $results[] = $this->buildResult(
                        $device,
                        ok: false,
                        missingInCentral: true,
                        expected: $expected,
                        central: [],
                    );

                    continue;
                }

                $results[] = $this->buildResult(
                    $device,
                    ok: $this->diffExpectedAgainstActual($expected, $centralItem) === [],
                    missingInCentral: false,
                    expected: $expected,
                    central: $centralItem,
                );
            }
        }

        return [
            'device_errors' => $deviceErrors,
            'results' => $results,
        ];
    }

    /**
     * @return array{items: array<string, array<string, mixed>>}|array{error: string}
     */
    protected function fetchDevicesBySite(CentralAPIHelper $helper, Site $site): array
    {
        $items = $helper->get_all_devices([
            'filter' => 'siteId eq '.$site->scope_id,
        ]);

        if (array_key_exists('error', $items)) {
            return ['error' => (string) $items['error']];
        }

        $indexed = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $serial = (string) ($item['serialNumber'] ?? '');
            if ($serial !== '') {
                $indexed[$serial] = $item;
            }
        }

        return ['items' => $indexed];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildExpectedFields(Device $device, Site $site, string $taskType): array
    {
        $expected = [
            'serialNumber' => $device->serial,
        ];

        if (in_array($taskType, ['ASSOCIATE_DEVICE_TO_SITE', 'ASSOCIATE_SITE_AND_NAME'], true)) {
            $expected['siteId'] = $site->scope_id;
        }

        if (in_array($taskType, ['ASSOCIATE_SITE_AND_NAME', 'UPDATE_SYSTEM_INFO'], true)) {
            $expected['deviceName'] = $device->name;
        }

        return $expected;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     * @return list<array{path: string, expected: mixed, actual: mixed}>
     */
    public function diffExpectedAgainstActual(array $expected, array $actual): array
    {
        $diffs = [];

        foreach ($expected as $key => $expectedValue) {
            $actualValue = $actual[$key] ?? null;

            if (! $this->valuesMatch($expectedValue, $actualValue)) {
                $diffs[] = [
                    'path' => (string) $key,
                    'expected' => $expectedValue,
                    'actual' => $actualValue,
                ];
            }
        }

        return $diffs;
    }

    protected function valuesMatch(mixed $expected, mixed $actual): bool
    {
        return $this->normalizeValue($expected) === $this->normalizeValue($actual);
    }

    protected function normalizeValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return is_float($value + 0) && floor($value + 0) != ($value + 0)
                ? (float) $value
                : (int) $value;
        }

        if (is_string($value)) {
            return $value;
        }

        return $value;
    }

    /**
     * @return array{
     *     device_id: int,
     *     device_name: string,
     *     serial: string,
     *     ok: bool,
     *     missing_in_central: bool,
     *     diff: list<array{path: string, expected: mixed, actual: mixed}>,
     *     details: list<array{path: string, expected: mixed, actual: mixed}>
     * }
     */
    protected function errorResult(Device $device, string $taskType, bool $missingInCentral): array
    {
        $site = $device->site;
        $expected = $this->buildExpectedFields($device, $site ?? new Site, $taskType);
        $central = [];
        $diff = $this->diffExpectedAgainstActual($expected, $central);

        return [
            'device_id' => $device->id,
            'device_name' => $device->name,
            'serial' => $device->serial,
            'ok' => false,
            'missing_in_central' => $missingInCentral,
            'diff' => $diff,
            'details' => ConfigurationDetailRowsBuilder::fromExpectedAndActual($expected, $central),
        ];
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $central
     * @return array{
     *     device_id: int,
     *     device_name: string,
     *     serial: string,
     *     ok: bool,
     *     missing_in_central: bool,
     *     diff: list<array{path: string, expected: mixed, actual: mixed}>,
     *     details: list<array{path: string, expected: mixed, actual: mixed}>
     * }
     */
    protected function buildResult(
        Device $device,
        bool $ok,
        bool $missingInCentral,
        array $expected,
        array $central,
    ): array {
        $diff = $this->diffExpectedAgainstActual($expected, $central);

        return [
            'device_id' => $device->id,
            'device_name' => $device->name,
            'serial' => $device->serial,
            'ok' => $ok,
            'missing_in_central' => $missingInCentral,
            'diff' => $diff,
            'details' => ConfigurationDetailRowsBuilder::fromExpectedAndActual($expected, $central),
        ];
    }
}
