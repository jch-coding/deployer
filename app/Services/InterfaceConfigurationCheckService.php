<?php

namespace App\Services;

use App\Helper\InterfaceHelper;
use App\InterfaceKind;
use App\Models\Deployment;
use App\Models\DeviceInterface;

class InterfaceConfigurationCheckService
{
    /**
     * @return array{ok: bool, message: string}
     */
    public function checkLagPortListUniqueness(Deployment $deployment): array
    {
        $interfaces = DeviceInterface::query()
            ->where('interface_kind', InterfaceKind::LAG)
            ->whereHas('device', fn ($query) => $query->where('deployment_id', $deployment->id))
            ->with(['device', 'lacp_profile'])
            ->get();

        $conflicts = [];

        foreach ($interfaces->groupBy('device_id') as $deviceInterfaces) {
            /** @var DeviceInterface $first */
            $first = $deviceInterfaces->first();
            $deviceName = $first->device?->name ?? 'Unknown device';

            /** @var array<string, list<string>> $portToLags */
            $portToLags = [];

            foreach ($deviceInterfaces as $deviceInterface) {
                $lacpProfile = $deviceInterface->lacp_profile;
                if ($lacpProfile === null) {
                    continue;
                }

                $portList = $lacpProfile->getRawOriginal('port_list');
                if ($portList === null || trim((string) $portList) === '') {
                    continue;
                }

                $lagName = (string) $deviceInterface->interface;

                foreach (InterfaceHelper::normalizePortListMembers($portList) as $port) {
                    if (! in_array($lagName, $portToLags[$port] ?? [], true)) {
                        $portToLags[$port][] = $lagName;
                    }
                }
            }

            foreach ($portToLags as $port => $lags) {
                if (count($lags) > 1) {
                    sort($lags);
                    $conflicts[] = sprintf(
                        'On %s, port %s is shared by LAG interfaces %s.',
                        $deviceName,
                        $port,
                        implode(' and ', $lags),
                    );
                }
            }
        }

        if ($conflicts === []) {
            return [
                'ok' => true,
                'message' => 'All LAG port lists are unique on each device.',
            ];
        }

        return [
            'ok' => false,
            'message' => implode(' ', $conflicts),
        ];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function checkVlanIpAddressUniqueness(Deployment $deployment): array
    {
        $interfaces = DeviceInterface::query()
            ->where('interface_kind', InterfaceKind::VLAN)
            ->whereHas('device', fn ($query) => $query->where('deployment_id', $deployment->id))
            ->with('device')
            ->get();

        /** @var array<string, list<string>> $byIp */
        $byIp = [];

        foreach ($interfaces as $deviceInterface) {
            $ip = trim((string) ($deviceInterface->ip_address ?? ''));
            if ($ip === '') {
                continue;
            }

            $deviceName = $deviceInterface->device?->name ?? 'Unknown device';
            $byIp[$ip][] = sprintf('%s (VLAN %s)', $deviceName, $deviceInterface->interface);
        }

        $duplicates = [];
        foreach ($byIp as $ip => $locations) {
            if (count($locations) > 1) {
                $duplicates[] = sprintf(
                    'Duplicate VLAN IP %s: %s.',
                    $ip,
                    implode(', ', $locations),
                );
            }
        }

        if ($duplicates === []) {
            return [
                'ok' => true,
                'message' => 'All VLAN IP addresses are unique across devices.',
            ];
        }

        return [
            'ok' => false,
            'message' => implode(' ', $duplicates),
        ];
    }
}
