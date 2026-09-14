<?php

namespace App\Services;

use App\Helper\CentralAPIHelper;
use App\Models\Client;
use App\Models\Device;
use App\Support\MacAddress;
use App\Support\MacAddressTableParser;
use Illuminate\Http\Client\Response;

class ClientDetailsLookupService
{
    public function __construct(
        private int $pollIntervalUs = 1_500_000,
        private int $maxPollAttempts = 80,
        private ?\Closure $sleeper = null,
    ) {}

    /**
     * @param  list<array{name: string, neighbour?: string|null, neighbourSerial?: string|null}>  $interfaces
     * @return array{rows: list<array<string, mixed>>, errors: list<array{interface: string, message: string}>}
     */
    public function lookup(Client $client, string $serial, array $interfaces): array
    {
        $serial = trim($serial);
        $helper = new CentralAPIHelper($client);

        /** @var list<array{interface: string, macAddress: string}> $macTargets */
        $macTargets = [];
        /** @var list<array{name: string, neighbour: string, neighbourSerial: string}> $pendingInterfaces */
        $pendingInterfaces = [];
        /** @var list<array{interface: string, message: string}> $errors */
        $errors = [];

        foreach ($interfaces as $interface) {
            $name = trim((string) ($interface['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $neighbour = trim((string) ($interface['neighbour'] ?? ''));
            $neighbourSerial = trim((string) ($interface['neighbourSerial'] ?? ''));

            $macs = $this->resolveMacsFromNeighbourFields($client, $neighbour, $neighbourSerial);

            if ($macs === []) {
                $pendingInterfaces[] = [
                    'name' => $name,
                    'neighbour' => $neighbour,
                    'neighbourSerial' => $neighbourSerial,
                ];

                continue;
            }

            foreach ($macs as $mac) {
                $macTargets[] = [
                    'interface' => $name,
                    'macAddress' => $mac,
                ];
            }
        }

        if ($pendingInterfaces !== []) {
            $tableResult = $this->fetchMacAddressTable($helper, $serial);
            if (array_key_exists('error', $tableResult)) {
                foreach ($pendingInterfaces as $pending) {
                    $errors[] = [
                        'interface' => $pending['name'],
                        'message' => (string) $tableResult['error'],
                    ];
                }
            } else {
                $rowsByPort = [];
                foreach ($tableResult['rows'] as $row) {
                    $port = trim((string) ($row['port'] ?? ''));
                    if ($port === '') {
                        continue;
                    }
                    $rowsByPort[$port][] = $row;
                }

                foreach ($pendingInterfaces as $pending) {
                    $portRows = $rowsByPort[$pending['name']] ?? [];
                    $found = false;

                    foreach ($portRows as $row) {
                        $mac = MacAddress::normalize((string) ($row['mac'] ?? ''));
                        if ($mac === null) {
                            continue;
                        }
                        $found = true;
                        $macTargets[] = [
                            'interface' => $pending['name'],
                            'macAddress' => $mac,
                        ];
                    }

                    if (! $found) {
                        $errors[] = [
                            'interface' => $pending['name'],
                            'message' => 'No client MAC address found for interface.',
                        ];
                    }
                }
            }
        }

        $detailsByMac = [];
        $rows = [];

        foreach ($macTargets as $target) {
            $mac = $target['macAddress'];
            $interfaceName = $target['interface'];

            if (! array_key_exists($mac, $detailsByMac)) {
                $detailsByMac[$mac] = $this->fetchClientDetailsRow($helper, $mac);
            }

            $details = $detailsByMac[$mac];
            if (array_key_exists('error', $details)) {
                $errors[] = [
                    'interface' => $interfaceName,
                    'message' => (string) $details['error'],
                ];

                continue;
            }

            $rows[] = array_merge($details['row'], [
                'macAddress' => $mac,
                'interface' => $interfaceName,
            ]);
        }

        return [
            'rows' => $rows,
            'errors' => $errors,
        ];
    }

    /**
     * @return list<string>
     */
    private function resolveMacsFromNeighbourFields(Client $client, string $neighbour, string $neighbourSerial): array
    {
        $macs = [];

        foreach ([$neighbourSerial, $neighbour] as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $normalized = MacAddress::normalize($candidate);
            if ($normalized !== null) {
                $macs[] = $normalized;
            }

            $fromTpd = MacAddress::fromTpdIdentifier($candidate);
            if ($fromTpd !== null) {
                $macs[] = $fromTpd;
            }
        }

        if ($neighbourSerial !== ''
            && MacAddress::normalize($neighbourSerial) === null
            && MacAddress::fromTpdIdentifier($neighbourSerial) === null
        ) {
            $deviceMac = Device::query()
                ->where('client_id', $client->id)
                ->where('serial', $neighbourSerial)
                ->value('mac_address');

            $normalizedDeviceMac = is_string($deviceMac) ? MacAddress::normalize($deviceMac) : null;
            if ($normalizedDeviceMac !== null) {
                $macs[] = $normalizedDeviceMac;
            }
        }

        return array_values(array_unique($macs));
    }

    /**
     * @return array{rows: list<array{mac: string, vlan: string, type: string, port: string}>}|array{error: string}
     */
    private function fetchMacAddressTable(CentralAPIHelper $helper, string $serial): array
    {
        $start = $helper->run_cx_show_commands($serial, ['show mac-address-table']);
        if (! ($start['ok'] ?? false)) {
            return ['error' => (string) ($start['error'] ?? 'failed to run show mac-address-table.')];
        }

        $taskId = (string) ($start['task_id'] ?? '');
        if ($taskId === '') {
            return ['error' => 'Central accepted show mac-address-table but did not return a task id.'];
        }

        for ($attempt = 0; $attempt < $this->maxPollAttempts; $attempt++) {
            $result = $helper->get_cx_show_commands_result($serial, $taskId);
            if (! ($result['ok'] ?? false)) {
                return ['error' => (string) ($result['error'] ?? 'failed to get show mac-address-table results.')];
            }

            $body = $result['body'] ?? [];
            $status = strtoupper((string) ($body['status'] ?? ''));

            if ($status === 'COMPLETED') {
                $output = $this->extractShowCommandOutput($body, 'show mac-address-table');
                $parsed = MacAddressTableParser::parse($output);

                return ['rows' => $parsed['rows']];
            }

            if ($status === 'FAILED') {
                $reason = trim((string) ($body['failReason'] ?? ''));

                return [
                    'error' => $reason !== ''
                        ? $reason
                        : 'show mac-address-table failed.',
                ];
            }

            if ($attempt < $this->maxPollAttempts - 1) {
                $this->sleep();
            }
        }

        return ['error' => 'Timed out waiting for show mac-address-table results.'];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function extractShowCommandOutput(array $body, string $command): string
    {
        $results = $body['output']['results'] ?? null;
        if (! is_array($results)) {
            return '';
        }

        foreach ($results as $item) {
            if (! is_array($item)) {
                continue;
            }

            $itemCommand = trim((string) ($item['command'] ?? ''));
            if ($itemCommand === $command || str_starts_with(strtolower($itemCommand), 'show mac-address-table')) {
                return (string) ($item['output'] ?? '');
            }
        }

        $first = $results[0] ?? null;
        if (is_array($first)) {
            return (string) ($first['output'] ?? '');
        }

        return '';
    }

    /**
     * @return array{row: array<string, mixed>}|array{error: string}
     */
    private function fetchClientDetailsRow(CentralAPIHelper $helper, string $macAddress): array
    {
        $result = $helper->get_client_details($macAddress);

        if (is_array($result) && array_key_exists('error', $result)) {
            return ['error' => (string) $result['error']];
        }

        if (! $result instanceof Response) {
            return ['error' => 'failed to get client details from central.'];
        }

        $payload = $result->json();
        if (! is_array($payload)) {
            return ['error' => 'failed to get client details from central.'];
        }

        return [
            'row' => [
                'ipv4' => $this->stringField($payload, 'ipv4'),
                'clientVendor' => $this->stringField($payload, 'clientVendor'),
                'port' => $this->stringField($payload, 'port'),
                'vlanId' => $this->stringField($payload, 'vlanId'),
                'clientOperatingSystem' => $this->stringField($payload, 'clientOperatingSystem'),
                'clientFunction' => $this->stringField($payload, 'clientFunction'),
                'role' => $this->stringField($payload, 'role'),
                'clientTags' => $this->stringField($payload, 'clientTags'),
                'clientManufacturer' => $this->stringField($payload, 'clientManufacturer'),
                'clientCategory' => $this->stringField($payload, 'clientCategory'),
                'authenticationType' => $this->stringField($payload, 'authenticationType'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stringField(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    private function sleep(): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($this->pollIntervalUs);

            return;
        }

        usleep($this->pollIntervalUs);
    }
}
