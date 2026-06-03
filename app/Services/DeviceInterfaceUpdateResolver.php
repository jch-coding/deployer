<?php

namespace App\Services;

use App\Helper\BooleanHelper;
use App\Support\TrunkVlanRanges;
use App\Models\DeviceInterface;
use App\Models\LacpProfile;
use App\Models\StpProfile;
use App\Models\SwitchPort;
use Illuminate\Validation\ValidationException;

class DeviceInterfaceUpdateResolver
{
    /**
     * @return array{
     *     description: string|null,
     *     ip_address: string|null,
     *     enable: bool,
     *     jumbo_frames: bool,
     *     routing: bool,
     *     shutdown_on_split: bool,
     *     vrf_forwarding: string,
     *     sw_profile: string|null,
     *     portchannel_lag: string|null,
     *     switch_port_id: int|null,
     *     lacp_profile_id: int|null,
     *     stp_profile_id: int|null
     * }
     */
    public function resolve(DeviceInterface $interface, array $update, int $index = 0): array
    {
        $mode = $update['interface_mode'] ?? $interface->switch_port?->interface_mode;
        $switchPortData = null;

        if ($mode !== null) {
            if ($mode === 'ACCESS') {
                $accessVlan = $update['access_vlan'] ?? $interface->switch_port?->access_vlan;
                if ($accessVlan === null) {
                    throw ValidationException::withMessages([
                        "updates.{$index}.access_vlan" => 'access_vlan is required when interface_mode is ACCESS.',
                    ]);
                }

                $switchPortData = [
                    'interface_mode' => 'ACCESS',
                    'access_vlan' => (int) $accessVlan,
                ];
            } else {
                $nativeVlan = $update['native_vlan'] ?? $interface->switch_port?->native_vlan;
                if ($nativeVlan === null) {
                    throw ValidationException::withMessages([
                        "updates.{$index}.native_vlan" => 'native_vlan is required when interface_mode is TRUNK.',
                    ]);
                }

                $trunkVlanAll = (bool) ($update['trunk_vlan_all'] ?? $interface->switch_port?->trunk_vlan_all ?? false);
                $trunkVlanRanges = null;
                if (! $trunkVlanAll) {
                    $rangesMessageKey = "updates.{$index}.trunk_vlan_ranges";
                    $existingRangesRaw = $interface->switch_port?->getRawOriginal('trunk_vlan_ranges');
                    if (array_key_exists('trunk_vlan_ranges', $update)) {
                        $trunkVlanRanges = TrunkVlanRanges::normalizeForStorage($update['trunk_vlan_ranges'], $rangesMessageKey);
                    } else {
                        $trunkVlanRanges = TrunkVlanRanges::normalizeForStorage(
                            ($existingRangesRaw !== null && $existingRangesRaw !== '') ? $existingRangesRaw : null,
                            $rangesMessageKey
                        );
                    }
                }

                $switchPortData = [
                    'interface_mode' => 'TRUNK',
                    'native_vlan' => (int) $nativeVlan,
                    'trunk_vlan_all' => $trunkVlanAll,
                    'trunk_vlan_ranges' => $trunkVlanRanges,
                ];
            }
        }

        $lacpInput = $update['lacp_port_list'] ?? $interface->lacp_profile?->port_list ?? [];
        $lacpPortList = $this->normalizeLacpPortList($lacpInput);
        if (count($lacpPortList) === 0) {
            $lacpData = null;
        } else {
            $lacpData = [
                'port_list' => implode('&', $lacpPortList),
                'lacp_mode' => $update['lacp_mode'] ?? $interface->lacp_profile?->mode ?? 'ACTIVE',
                'lacp_rate' => $update['lacp_rate'] ?? $interface->lacp_profile?->rate ?? 'SLOW',
                'trunk_type' => $update['trunk_type'] ?? $interface->lacp_profile?->trunk_type ?? 'LACP',
                'lacp_port_id' => $update['lacp_port_id'] ?? $interface->lacp_profile?->port_id,
            ];
        }

        $stpKeys = ['admin_edge_port', 'admin_edge_port_trunk', 'bpdu_guard', 'loop_guard'];
        $hasStpInput = array_any($stpKeys, fn ($key) => array_key_exists($key, $update));
        $stpData = null;
        if ($hasStpInput || $interface->stp_profile_id !== null) {
            $stpData = [
                'admin_edge_port' => $update['admin_edge_port'] ?? $interface->stp_profile?->admin_edge_port ?? false,
                'admin_edge_port_trunk' => $update['admin_edge_port_trunk'] ?? $interface->stp_profile?->admin_edge_port_trunk ?? false,
                'bpdu_guard' => $update['bpdu_guard'] ?? $interface->stp_profile?->bpdu_guard ?? false,
                'loop_guard' => $update['loop_guard'] ?? $interface->stp_profile?->loop_guard ?? false,
            ];
        }

        return [
            'description' => array_key_exists('description', $update) ? $update['description'] : $interface->description,
            'ip_address' => array_key_exists('ip_address', $update) ? $update['ip_address'] : $interface->ip_address,
            'enable' => array_key_exists('enable', $update) ? (bool) $update['enable'] : (bool) $interface->enable,
            'jumbo_frames' => array_key_exists('jumbo_frames', $update) ? (bool) $update['jumbo_frames'] : (bool) $interface->jumbo_frames,
            'routing' => array_key_exists('routing', $update) ? (bool) $update['routing'] : (bool) $interface->routing,
            'shutdown_on_split' => array_key_exists('shutdown_on_split', $update) ? (bool) $update['shutdown_on_split'] : (bool) $interface->shutdown_on_split,
            'vrf_forwarding' => array_key_exists('vrf_forwarding', $update) ? $update['vrf_forwarding'] : $interface->vrf_forwarding,
            'sw_profile' => array_key_exists('sw_profile', $update) ? $update['sw_profile'] : $interface->sw_profile,
            'portchannel_lag' => array_key_exists('portchannel_lag', $update) ? $update['portchannel_lag'] : $interface->portchannel_lag,
            'switch_port_id' => $switchPortData ? self::resolveSwitchPortId($switchPortData) : $interface->switch_port_id,
            'lacp_profile_id' => $lacpData ? self::resolveLacpProfileId($lacpData) : $interface->lacp_profile_id,
            'stp_profile_id' => $stpData ? self::resolveStpProfileId($stpData) : $interface->stp_profile_id,
        ];
    }

    public function applyResolved(DeviceInterface $interface, array $resolved): void
    {
        $interface->description = $resolved['description'];
        $interface->ip_address = $resolved['ip_address'];
        $interface->enable = $resolved['enable'];
        $interface->jumbo_frames = $resolved['jumbo_frames'];
        $interface->routing = $resolved['routing'];
        $interface->shutdown_on_split = $resolved['shutdown_on_split'];
        $interface->vrf_forwarding = $resolved['vrf_forwarding'];
        $interface->sw_profile = $resolved['sw_profile'];
        $interface->portchannel_lag = $resolved['portchannel_lag'];
        $interface->switch_port_id = $resolved['switch_port_id'];
        $interface->lacp_profile_id = $resolved['lacp_profile_id'];
        $interface->stp_profile_id = $resolved['stp_profile_id'];
        $interface->save();
    }

    /**
     * @param  array<string, mixed>  $attributes  dot-path => value from Central payload editor
     * @return array<string, mixed>
     */
    public function payloadAttributesToUpdate(string $kind, array $attributes, DeviceInterface $interface): array
    {
        $update = [];

        foreach ($attributes as $path => $value) {
            if ($path === 'id' || $path === 'name' || $path === 'is-valid') {
                continue;
            }

            if ($path === 'ipv4.address') {
                $update['ip_address'] = $value === '' ? null : (string) $value;
            } elseif ($path === 'routing') {
                $update['routing'] = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
            } elseif ($path === 'ipv4.vrf-forwarding') {
                $update['vrf_forwarding'] = $value === '' ? null : (string) $value;
            } elseif ($path === 'enable') {
                $update['enable'] = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
            } elseif ($path === 'description') {
                $update['description'] = $value === '' ? null : (string) $value;
            } elseif ($path === 'sw-profile') {
                $update['sw_profile'] = $value === '' ? null : (string) $value;
            } elseif ($path === 'portchannel-lag') {
                $update['portchannel_lag'] = $value === '' ? null : (string) $value;
            } elseif ($path === 'lacp.mode') {
                $update['lacp_mode'] = (string) $value;
            } elseif ($path === 'lacp.rate') {
                $update['lacp_rate'] = (string) $value;
            } elseif ($path === 'trunk-type') {
                $update['trunk_type'] = (string) $value;
            } elseif ($path === 'port-list') {
                $update['lacp_port_list'] = $this->normalizeLacpPortListFromPayload($value);
            } elseif ($path === 'switchport.interface-mode') {
                $update['interface_mode'] = strtoupper((string) $value);
            } elseif ($path === 'switchport.access-vlan') {
                $this->applyAccessVlan($update, $value);
            } elseif ($path === 'switchport.native-vlan') {
                $this->applyNativeVlan($update, $value);
            } elseif ($path === 'switchport.trunk-vlan-all') {
                $update['trunk_vlan_all'] = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
            } elseif ($path === 'switchport.trunk-vlan-ranges') {
                $update['trunk_vlan_ranges'] = $value === null || $value === '' ? null : (string) $value;
            } elseif ($path === 'vsx.shutdown-on-split') {
                $update['shutdown_on_split'] = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
            } elseif ($path === 'stp.admin-edge-port') {
                $update['admin_edge_port'] = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
            } elseif ($path === 'stp.admin-edge-port-trunk') {
                $update['admin_edge_port_trunk'] = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
            } elseif ($path === 'stp.bpdu-guard') {
                $update['bpdu_guard'] = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
            } elseif ($path === 'stp.loop-guard') {
                $update['loop_guard'] = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
            }
        }

        if ($kind === 'vlan' && ! array_key_exists('enable', $update) && array_key_exists('enable', $attributes)) {
            $update['enable'] = filter_var($attributes['enable'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $attributes['enable'];
        }

        if (! array_key_exists('interface_mode', $update) && $interface->switch_port?->interface_mode !== null) {
            $update['interface_mode'] = $interface->switch_port->interface_mode;
        }

        return $update;
    }

    /**
     * @param  array<string, mixed>  $update
     */
    protected function applyAccessVlan(array &$update, mixed $value): void
    {
        $update['interface_mode'] = 'ACCESS';
        $update['access_vlan'] = (int) $value;
    }

    /**
     * @param  array<string, mixed>  $update
     */
    protected function applyNativeVlan(array &$update, mixed $value): void
    {
        if (! array_key_exists('interface_mode', $update)) {
            $update['interface_mode'] = 'TRUNK';
        }
        $update['native_vlan'] = (int) $value;
    }

    /**
     * @return list<string>
     */
    protected function normalizeLacpPortListFromPayload(mixed $value): array
    {
        if (is_array($value)) {
            return $this->normalizeLacpPortList($value);
        }

        $string = (string) $value;
        if (str_contains($string, '&')) {
            return $this->normalizeLacpPortList(explode('&', $string));
        }

        return $this->normalizeLacpPortList($string);
    }

    /**
     * @return list<string>
     */
    public function normalizeLacpPortList(array|string|null $portList): array
    {
        if ($portList === null) {
            return [];
        }

        $parts = is_array($portList)
            ? $portList
            : (preg_split('/[,&]/', (string) $portList) ?: []);

        return array_values(array_filter(array_map(
            static fn ($part) => trim((string) $part),
            $parts
        )));
    }

    /**
     * @param  array<string, mixed>  $interfaceData
     */
    public static function resolveSwitchPortId(array $interfaceData): ?int
    {
        $switchPortAttributes = self::normalizeSwitchPortAttributes($interfaceData);
        if ($switchPortAttributes === null) {
            return null;
        }

        return SwitchPort::firstOrCreate($switchPortAttributes)->id;
    }

    /**
     * @param  array<string, mixed>  $interfaceData
     */
    public static function resolveStpProfileId(array $interfaceData): ?int
    {
        $stpAttributes = self::normalizeStpAttributes($interfaceData);
        if ($stpAttributes === null) {
            return null;
        }

        return StpProfile::firstOrCreate($stpAttributes)->id;
    }

    /**
     * @param  array<string, mixed>  $interfaceData
     */
    public static function resolveLacpProfileId(array $interfaceData): ?int
    {
        $lacpAttributes = self::normalizeLacpAttributes($interfaceData);
        if ($lacpAttributes === null) {
            return null;
        }

        return LacpProfile::firstOrCreate($lacpAttributes)->id;
    }

    /**
     * @param  array<string, mixed>  $interfaceData
     * @return array<string, mixed>|null
     */
    protected static function normalizeSwitchPortAttributes(array $interfaceData): ?array
    {
        if (! array_key_exists('interface_mode', $interfaceData) || $interfaceData['interface_mode'] === null) {
            return null;
        }

        $mode = $interfaceData['interface_mode'] ?? 'ACCESS';
        if ($mode === 'TRUNK') {
            $trunkVlanAll = BooleanHelper::toBoolean($interfaceData['trunk_vlan_all'] ?? false);

            return [
                'interface_mode' => 'TRUNK',
                'access_vlan' => null,
                'native_vlan' => (int) ($interfaceData['native_vlan'] ?? 1),
                'trunk_vlan_all' => $trunkVlanAll,
                'trunk_vlan_ranges' => $trunkVlanAll
                    ? null
                    : TrunkVlanRanges::normalizeForStorage($interfaceData['trunk_vlan_ranges'] ?? null),
            ];
        }

        return [
            'interface_mode' => 'ACCESS',
            'access_vlan' => (int) ($interfaceData['access_vlan'] ?? 1),
            'native_vlan' => null,
            'trunk_vlan_all' => null,
            'trunk_vlan_ranges' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $interfaceData
     * @return array<string, mixed>|null
     */
    protected static function normalizeStpAttributes(array $interfaceData): ?array
    {
        $stp_keys = ['admin_edge_port', 'admin_edge_port_trunk', 'bpdu_guard', 'loop_guard'];
        if (! array_any($interfaceData, fn ($v, $k) => in_array($k, $stp_keys, true))) {
            return null;
        }

        return [
            'admin_edge_port' => BooleanHelper::toBoolean($interfaceData['admin_edge_port'] ?? false),
            'admin_edge_port_trunk' => BooleanHelper::toBoolean($interfaceData['admin_edge_port_trunk'] ?? false),
            'bpdu_guard' => BooleanHelper::toBoolean($interfaceData['bpdu_guard'] ?? false),
            'loop_guard' => BooleanHelper::toBoolean($interfaceData['loop_guard'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $interfaceData
     * @return array<string, mixed>|null
     */
    protected static function normalizeLacpAttributes(array $interfaceData): ?array
    {
        $lacpPortList = $interfaceData['port_list'] ?? null;
        if (($lacpPortList === null || $lacpPortList === '') && ($interfaceData['lacp_port_id'] ?? null) !== null) {
            $lacpPortList = $interfaceData['interface'] ?? null;
        }

        if ($lacpPortList === null || $lacpPortList === '') {
            return null;
        }

        return [
            'mode' => $interfaceData['lacp_mode'] ?? 'ACTIVE',
            'trunk_type' => $interfaceData['trunk_type'] ?? 'LACP',
            'port_list' => $lacpPortList,
            'rate' => $interfaceData['lacp_rate'] ?? 'SLOW',
            'port_id' => $interfaceData['lacp_port_id'] ?? null,
        ];
    }
}
