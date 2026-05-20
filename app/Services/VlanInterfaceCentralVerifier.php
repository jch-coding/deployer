<?php

namespace App\Services;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\DeviceInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;

class VlanInterfaceCentralVerifier
{
    /**
     * @param  Collection<int, DeviceInterface>  $interfaces
     * @return array{
     *     device_errors: array<int, array{device_id: int, device_name: string, message: string}>,
     *     results: array<int, array{
     *         device_interface_id: int,
     *         device_id: int,
     *         device_name: string,
     *         interface: string,
     *         ok: bool,
     *         missing_in_central: bool,
     *         diff: array<int, array{path: string, expected: mixed, actual: mixed}>
     *     }>
     * }
     */
    public function verifyInterfaces(Collection $interfaces, CentralAPIHelper $helper): array
    {
        $deviceErrors = [];
        $results = [];
        $centralByDevice = [];

        foreach ($interfaces->groupBy('device_id') as $deviceInterfaces) {
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
                $fetch = $this->fetchVlanInterfacesById($helper, $device);
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
                $vlanId = (string) $deviceInterface->interface;
                $centralItem = $centralItems[$vlanId] ?? null;

                if ($centralItem === null) {
                    $results[] = $this->buildResult(
                        $deviceInterface,
                        $device,
                        ok: false,
                        missingInCentral: true,
                        expected: $expected,
                        central: [],
                    );

                    continue;
                }

                $results[] = $this->buildResult(
                    $deviceInterface,
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
    protected function fetchVlanInterfacesById(CentralAPIHelper $helper, Device $device): array
    {
        $response = $helper->get_vlan_interfaces($device);

        if ($response instanceof Response && ! $response->ok()) {
            $message = (string) ($response->json('message') ?? $response->body());

            return ['error' => $message !== '' ? $message : 'Failed to fetch VLAN interfaces from Central.'];
        }

        if (is_array($response) && array_key_exists('error', $response)) {
            return ['error' => (string) $response['error']];
        }

        $items = $response instanceof Response ? $response->json('interface', []) : [];
        if (! is_array($items)) {
            $items = [];
        }

        $indexed = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = (string) ($item['id'] ?? '');
            if ($id !== '') {
                $indexed[$id] = $item;
            }
        }

        return ['items' => $indexed];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildExpectedPayload(DeviceInterface $deviceInterface): array
    {
        return [
            'id' => $deviceInterface->interface,
            'ipv4' => [
                'address' => $deviceInterface->ip_address,
            ],
            'enable' => $deviceInterface->enable,
            'is-valid' => true,
        ];
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
     *     device_id: int,
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
        $central = [];
        $diff = $this->diffExpectedAgainstActual($expected, $central);

        return [
            'device_interface_id' => $deviceInterface->id,
            'device_id' => (int) ($deviceInterface->device_id ?? 0),
            'device_name' => $deviceName,
            'interface' => $deviceInterface->interface,
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
     *     device_interface_id: int,
     *     device_id: int,
     *     device_name: string,
     *     interface: string,
     *     ok: bool,
     *     missing_in_central: bool,
     *     diff: list<array{path: string, expected: mixed, actual: mixed}>,
     *     details: list<array{path: string, expected: mixed, actual: mixed}>
     * }
     */
    protected function buildResult(
        DeviceInterface $deviceInterface,
        Device $device,
        bool $ok,
        bool $missingInCentral,
        array $expected,
        array $central,
    ): array {
        $diff = $this->diffExpectedAgainstActual($expected, $central);

        return [
            'device_interface_id' => $deviceInterface->id,
            'device_id' => $device->id,
            'device_name' => $device->name,
            'interface' => $deviceInterface->interface,
            'ok' => $ok,
            'missing_in_central' => $missingInCentral,
            'diff' => $diff,
            'details' => ConfigurationDetailRowsBuilder::fromExpectedAndActual($expected, $central),
        ];
    }
}
