<?php

namespace App\Services;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\Task;
use Illuminate\Http\Client\Response;

class LagInterfaceCentralVerifier
{
    /**
     * @return array{
     *     device_errors: array<int, array{device_id: int, device_name: string, message: string}>,
     *     results: array<int, array{
     *         device_interface_id: int,
     *         device_name: string,
     *         interface: string,
     *         ok: bool,
     *         missing_in_central: bool,
     *         diff: array<int, array{path: string, expected: mixed, actual: mixed}>
     *     }>
     * }
     */
    public function verify(Task $task, CentralAPIHelper $helper): array
    {
        $interfaces = $task->deviceInterfaces()
            ->with(['device', 'lacp_profile', 'switch_port', 'stp_profile'])
            ->get();

        $deviceErrors = [];
        $results = [];
        $centralByDevice = [];

        foreach ($interfaces->groupBy('device_id') as $deviceId => $deviceInterfaces) {
            /** @var DeviceInterface $first */
            $first = $deviceInterfaces->first();
            $device = $first->device;

            if ($device === null) {
                foreach ($deviceInterfaces as $deviceInterface) {
                    $results[] = $this->errorResult($deviceInterface, 'Device not found', missingInCentral: true);
                }

                continue;
            }

            if (! $device->scope_id || ! $device->device_function) {
                $deviceErrors[] = [
                    'device_id' => $device->id,
                    'device_name' => $device->name,
                    'message' => 'Scope ID or device function not available for this device.',
                ];
                foreach ($deviceInterfaces as $deviceInterface) {
                    $results[] = $this->errorResult($deviceInterface, $device->name, missingInCentral: true);
                }

                continue;
            }

            if (! array_key_exists($device->id, $centralByDevice)) {
                $fetch = $this->fetchPortchannelsByName($helper, $device);
                if (isset($fetch['error'])) {
                    $deviceErrors[] = [
                        'device_id' => $device->id,
                        'device_name' => $device->name,
                        'message' => $fetch['error'],
                    ];
                    foreach ($deviceInterfaces as $deviceInterface) {
                        $results[] = $this->errorResult($deviceInterface, $device->name, missingInCentral: true);
                    }

                    continue;
                }
                $centralByDevice[$device->id] = $fetch['items'];
            }

            $centralItems = $centralByDevice[$device->id];

            foreach ($deviceInterfaces as $deviceInterface) {
                $expected = $this->buildExpectedPayload($deviceInterface);
                $centralItem = $centralItems[$deviceInterface->interface] ?? null;

                if ($centralItem === null) {
                    $results[] = [
                        'device_interface_id' => $deviceInterface->id,
                        'device_name' => $device->name,
                        'interface' => $deviceInterface->interface,
                        'ok' => false,
                        'missing_in_central' => true,
                        'diff' => $this->diffExpectedAgainstActual($expected, []),
                    ];

                    continue;
                }

                $diff = $this->diffExpectedAgainstActual($expected, $centralItem);
                $results[] = [
                    'device_interface_id' => $deviceInterface->id,
                    'device_name' => $device->name,
                    'interface' => $deviceInterface->interface,
                    'ok' => $diff === [],
                    'missing_in_central' => false,
                    'diff' => $diff,
                ];
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
    protected function fetchPortchannelsByName(CentralAPIHelper $helper, Device $device): array
    {
        $response = $helper->get_interface_portchannels([
            'object-type' => 'LOCAL',
            'view-type' => 'LOCAL',
            'scope-id' => $device->scope_id,
            'device-function' => $this->deviceFunctionQueryValue($device),
        ]);

        if (is_array($response) && array_key_exists('error', $response)) {
            return ['error' => (string) $response['error']];
        }

        if (! $response instanceof Response || ! $response->ok()) {
            $message = $response instanceof Response
                ? (string) ($response->json('message') ?? $response->body())
                : 'Failed to fetch portchannels from Central.';

            return ['error' => $message !== '' ? $message : 'Failed to fetch portchannels from Central.'];
        }

        $items = $this->collectItemsFromResponse($response);
        $indexed = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = (string) ($item['name'] ?? '');
            if ($name !== '') {
                $indexed[$name] = $item;
            }
        }

        return ['items' => $indexed];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function collectItemsFromResponse(Response $response): array
    {
        $items = $response->json('items', []);
        if (! is_array($items)) {
            return [];
        }

        $all = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $all[] = $item;
            }
        }

        return $all;
    }

    protected function deviceFunctionQueryValue(Device $device): string
    {
        $value = $device->device_function;

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        return (string) $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildExpectedPayload(DeviceInterface $deviceInterface): array
    {
        $expected = CentralAPIHelper::build_portchannel_from_device_interface($deviceInterface, true);
        if ($deviceInterface->sw_profile) {
            $expected = array_merge(
                $expected,
                CentralAPIHelper::build_portchannel_from_device_interface($deviceInterface, false)
            );
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
        $this->compareNodes($expected, $actual, '', $diffs);

        return $diffs;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     * @param  list<array{path: string, expected: mixed, actual: mixed}>  $diffs
     */
    protected function compareNodes(array $expected, array $actual, string $prefix, array &$diffs): void
    {
        foreach ($expected as $key => $expectedValue) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $actualValue = $actual[$key] ?? null;

            if (is_array($expectedValue) && $this->isAssociativeArray($expectedValue)) {
                $actualNested = is_array($actualValue) ? $actualValue : [];
                $this->compareNodes($expectedValue, $actualNested, $path, $diffs);

                continue;
            }

            if (! $this->valuesMatch($expectedValue, $actualValue)) {
                $diffs[] = [
                    'path' => $path,
                    'expected' => $expectedValue,
                    'actual' => $actualValue,
                ];
            }
        }
    }

    protected function isAssociativeArray(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }

    protected function valuesMatch(mixed $expected, mixed $actual): bool
    {
        $normalizedExpected = $this->normalizeValue($expected);
        $normalizedActual = $this->normalizeValue($actual);

        if (is_array($normalizedExpected) && is_array($normalizedActual)) {
            if ($this->isListArray($normalizedExpected) && $this->isListArray($normalizedActual)) {
                $a = $normalizedExpected;
                $b = $normalizedActual;
                sort($a);
                sort($b);

                return $a === $b;
            }
        }

        return $normalizedExpected === $normalizedActual;
    }

    protected function isListArray(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
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
            $lower = strtolower($value);
            if ($lower === 'true') {
                return true;
            }
            if ($lower === 'false') {
                return false;
            }

            return $value;
        }

        if (is_array($value)) {
            if ($this->isListArray($value)) {
                return array_map(fn ($item) => $this->normalizeValue($item), $value);
            }

            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeValue($item);
            }

            return $normalized;
        }

        return $value;
    }

    /**
     * @return array{
     *     device_interface_id: int,
     *     device_name: string,
     *     interface: string,
     *     ok: bool,
     *     missing_in_central: bool,
     *     diff: list<array{path: string, expected: mixed, actual: mixed}>
     * }
     */
    protected function errorResult(DeviceInterface $deviceInterface, string $deviceName, bool $missingInCentral): array
    {
        $expected = $this->buildExpectedPayload($deviceInterface);

        return [
            'device_interface_id' => $deviceInterface->id,
            'device_name' => $deviceName,
            'interface' => $deviceInterface->interface,
            'ok' => false,
            'missing_in_central' => $missingInCentral,
            'diff' => $this->diffExpectedAgainstActual($expected, []),
        ];
    }
}
