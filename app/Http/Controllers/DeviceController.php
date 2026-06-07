<?php

namespace App\Http\Controllers;

use App\DeviceFunction;
use App\Helper\BooleanHelper;
use App\Helper\CentralAPIHelper;
use App\Helper\CSVHelper;
use App\Helper\InterfaceHelper;
use App\Http\Requests\UpdateDeviceInterfacesRequest;
use App\Http\Requests\UpdateDeviceMetadataRequest;
use App\Http\Resources\LacpProfileResource;
use App\Http\Resources\StpProfileResource;
use App\Http\Resources\SwitchPortResource;
use App\InterfaceKind;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\LacpProfile;
use App\Models\Site;
use App\Models\StpProfile;
use App\Models\SwitchPort;
use App\Services\DeviceInterfaceUpdateResolver;
use App\Support\TrunkVlanRanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DeviceController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Deployment $deployment)
    {
        $user = $request->user();
        $currentClient = $user->currentClient();

        $data = $request->validate([
            'name' => 'required|string|min:3|max:255',
            'serial' => 'required|string|min:12',
            'device_function' => [
                'required',
                Rule::in(DeviceFunction::cases()),
            ],
        ]);

        if (! $currentClient || $currentClient->id !== $deployment->client_id) {
            return redirect()->route('deployments.show', $deployment)->with('error', 'Device cannot be created for this deployment');
        }

        $device = Device::query()
            ->where('serial', $data['serial'])
            ->where('user_id', $user->id)
            ->first();

        if ($device) {
            $device->update([
                ...$data,
                'client_id' => $currentClient->id,
                'user_id' => $user->id,
                'deployment_id' => $deployment->id,
            ]);

            return redirect()->route('deployments.show', $deployment)->with('success', 'Device updated successfully');
        }
        Device::create([
            ...$data,
            'client_id' => $currentClient->id,
            'user_id' => $user->id,
            'deployment_id' => $deployment->id,
        ]);

        return to_route('deployments.show', $deployment)->with('success', 'Device created successfully');
    }

    /**
     *  Parse CSV file uploaded and store information about devices
     */
    public function storeMany(Request $request, Deployment $deployment)
    {
        $user = $request->user();
        $currentClient = $user->currentClient();

        if (! $currentClient || $currentClient->id !== $deployment->client_id) {
            return redirect()->route('deployments.show', $deployment)->with('error', 'Devices cannot be created for this deployment');
        }

        if (! $request->hasFile('devices')) {
            return back()->withErrors('No file uploaded');
        }

        $file = $request->file('devices');
        $csvData = CSVHelper::processCSVFile($file->getPathname());
        try {
            $devices = CSVHelper::createDeviceArrays($csvData);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        if (count($devices) === 0) {
            return back()->withErrors('No devices found in CSV file');
        }

        $headers = array_map(
            fn ($header) => CSVHelper::normalizeCsvHeader((string) $header),
            $csvData[0]
        );

        $unique_devices = $this->consolidateDataForDevices($devices);

        $withDeployment = array_map(
            fn ($arr) => [
                'name' => $arr['name'],
                'serial' => $arr['serial'],
                'device_function' => $arr['device_function'],
                'client_id' => $currentClient->id,
                'user_id' => $user->id,
                'deployment_id' => $deployment->id,
                'group' => $arr['group'] ?? null,
                'sku' => $arr['sku'] == '' ? null : $arr['sku'],
                'vsx_profile' => ($arr['vsx_profile'] ?? '') === '' ? null : $arr['vsx_profile'],
                'vsx_role' => ($arr['vsx_role'] ?? '') === '' ? null : $arr['vsx_role'],
                'vsx_system_mac' => ($arr['vsx_system_mac'] ?? '') === '' ? null : $arr['vsx_system_mac'],
                'vsx_isl_ports' => ($arr['vsx_isl_ports'] ?? '') === '' ? null : $arr['vsx_isl_ports'],
                'vsx_keepalive_ports' => ($arr['vsx_keepalive_ports'] ?? '') === '' ? null : $arr['vsx_keepalive_ports'],
            ],
            $unique_devices
        );

        $savedDevices = Device::query()->upsert($withDeployment, ['serial', 'user_id'], ['name', 'device_function', 'client_id', 'deployment_id', 'group', 'sku', 'vsx_profile', 'vsx_role', 'vsx_system_mac', 'vsx_isl_ports', 'vsx_keepalive_ports']);

        $errors = [];
        $unsaved_devices = [];
        $unsaved_interfaces = [];
        $unsaved_sites = [];

        if ($savedDevices !== count($devices)) {
            $unsaved_devices = array_filter($devices, fn ($device) => Device::query()
                ->where('serial', $device['serial'])
                ->where('user_id', $user->id)
                ->doesntExist());
        }

        if (in_array('site', $headers)) {
            $sites_with_devices = static::getSitesWithDeviceSerials($devices);
            $saved_sites = static::saveSitesWithDevices($sites_with_devices, $currentClient, $user->id);
            $unsaved_sites = array_filter(
                $sites_with_devices,
                fn ($site) => Site::query()
                    ->where('client_id', $currentClient->id)
                    ->where('name', $site['name'])
                    ->doesntExist()
            );
        }

        if (in_array('interface', $headers)) {

            $interfaces = static::getInterfaces($devices, $user->id);

            $savedInterfaces = static::saveInterfaces($interfaces, $user->id);

            if ($savedInterfaces !== $interfaces['total_interfaces']) {
                $unsaved_interfaces = [];
                foreach ($interfaces['devices_grouped_config'] as $device_interfaces) {
                    foreach ($device_interfaces as $row) {
                        $deviceQuery = Device::query()->where('serial', $row['serial']);
                        if ($userId !== null) {
                            $deviceQuery->where('user_id', $userId);
                        }
                        $device = $deviceQuery->first();
                        if ($device === null) {
                            $unsaved_interfaces[] = $row;

                            continue;
                        }
                        $kindValue = $row['interface_kind'] instanceof InterfaceKind
                            ? $row['interface_kind']->value
                            : $row['interface_kind'];
                        $exists = DeviceInterface::query()
                            ->where('device_id', $device->id)
                            ->where('interface', $row['interface'])
                            ->where('interface_kind', $kindValue)
                            ->exists();
                        if (! $exists) {
                            $unsaved_interfaces[] = $row;
                        }
                    }
                }
            }
        }

        return to_route('deployments.show', $deployment)
            ->with([
                'unsaved_devices' => $unsaved_devices,
                'unsaved_interfaces' => $unsaved_interfaces,
                'unsaved_sites' => $unsaved_sites,
            ]);
    }

    public function consolidateDataForDevices(array $devices)
    {
        $grouped_by_serials = collect($devices)->groupBy('serial');
        $unique_devices = [];
        foreach ($grouped_by_serials->toArray() as $device) {
            $empty = [
                'name' => '',
                'serial' => '',
                'device_function' => '',
                'group' => '',
                'sku' => '',
                'vsx_profile' => '',
                'vsx_role' => '',
                'vsx_system_mac' => '',
                'vsx_isl_ports' => '',
                'vsx_keepalive_ports' => '',
            ];
            foreach ($device as $device_info) {
                foreach (array_keys($empty) as $key) {
                    if ($empty[$key] === '') {
                        $empty[$key] = $device_info[$key] ?? '';
                    }
                }
            }
            $unique_devices[] = $empty;
        }

        return $unique_devices;
    }

    public static function expandInterfaceRange(string $range)
    {
        return InterfaceHelper::expandInterfaceRange($range);
    }

    public static function expandInterfaceRangeConfig(array $interface_config)
    {
        if (array_key_exists('lacp_port_id', $interface_config) && $interface_config['lacp_port_id'] !== null) {
            return [$interface_config];
        }

        $interface_range = static::expandInterfaceRange($interface_config['interface']);
        $interface_range_configs = array_map(fn ($range) => array_merge($interface_config, ['interface' => $range]), $interface_range);

        return $interface_range_configs;
    }

    /**
     * Determine logical interface kind for CSV/import rows (persists alongside numeric interface ids).
     *
     * @param  array<string, mixed>  $row
     */
    public static function detectInterfaceKind(array $row): InterfaceKind
    {
        $iface = isset($row['interface']) ? (string) $row['interface'] : '';
        if (str_contains($iface, '/')) {
            return InterfaceKind::ETHERNET;
        }

        $lacpPortId = $row['lacp_port_id'] ?? null;
        if ($lacpPortId !== null && trim((string) $lacpPortId) !== '') {
            return InterfaceKind::LAG;
        }

        $portList = $row['port_list'] ?? null;
        if ($portList !== null && trim((string) $portList) !== '') {
            return InterfaceKind::LAG;
        }

        $ipAddress = $row['ip_address'] ?? null;
        if ($ipAddress !== null && trim((string) $ipAddress) !== '') {
            return InterfaceKind::VLAN;
        }

        return InterfaceKind::ETHERNET;
    }

    public static function getInterfaces($devices, ?int $userId = null)
    {
        $unique_devices = array_unique(array_column($devices, 'serial'));
        $devices_with_interface_info = array_filter($devices, fn ($device) => array_key_exists('interface', $device) && $device['interface'] !== '');
        $normalized_devices = array_map(function (array $device) {
            $mapped = array_map(fn ($v) => $v === '' ? null : $v, $device);
            if (array_key_exists('interface', $mapped) && $mapped['interface'] !== null && $mapped['interface'] !== '') {
                $mapped['interface'] = InterfaceHelper::normalizeInterfaceString((string) $mapped['interface']);
            }

            return $mapped;
        }, $devices_with_interface_info);

        $normalized_devices = array_map(function (array $device) {
            $device['interface_kind'] = static::detectInterfaceKind($device);

            return $device;
        }, $normalized_devices);

        $unique_switchports = [];
        foreach ($normalized_devices as $device) {
            if (array_key_exists('interface_mode', $device) && $device['interface_mode'] !== null) {
                if (array_key_exists('port_profile', $device) && $device['port_profile'] !== null) {
                    $device['sw_profile'] = $device['port_profile'];
                }
                if ($device['interface_mode'] === 'TRUNK') {
                    $current_switchport = [
                        'interface_mode' => $device['interface_mode'],
                        'access_vlan' => null,
                        'native_vlan' => (int) ($device['native_vlan'] ?? 1),
                        'trunk_vlan_all' => BooleanHelper::toBoolean($device['trunk_vlan_all'] ?? false),
                        'trunk_vlan_ranges' => TrunkVlanRanges::normalizeForStorage($device['trunk_vlan_ranges'] ?? null),
                    ];
                } else {
                    $current_switchport = [
                        'interface_mode' => $device['interface_mode'],
                        'access_vlan' => (int) ($device['access_vlan'] ?? 1),
                        'native_vlan' => null,
                        'trunk_vlan_all' => null,
                        'trunk_vlan_ranges' => null,
                    ];
                }
                if (! in_array($current_switchport, $unique_switchports)) {
                    $unique_switchports[] = $current_switchport;
                }
            }
        }

        $unique_stp = [];
        $stp_keys = ['admin_edge_port', 'admin_edge_port_trunk', 'bpdu_guard', 'loop_guard'];
        foreach ($normalized_devices as $device) {
            if (array_any($device, fn ($v, $k) => in_array($k, $stp_keys)) && $device['interface'] !== null) {
                $current_stp = [
                    'admin_edge_port' => BooleanHelper::toBoolean($device['admin_edge_port'] ?? false),
                    'admin_edge_port_trunk' => BooleanHelper::toBoolean($device['admin_edge_port_trunk'] ?? false),
                    'bpdu_guard' => BooleanHelper::toBoolean($device['bpdu_guard'] ?? false),
                    'loop_guard' => BooleanHelper::toBoolean($device['loop_guard'] ?? false),
                ];
                if (! in_array($current_stp, $unique_stp)) {
                    $unique_stp[] = $current_stp;
                }
            }
        }

        $unique_lacp = [];
        $lacp_keys = ['lacp_mode', 'trunk_type', 'port_list', 'lacp_rate'];
        foreach ($normalized_devices as $device) {
            if (array_any($device, fn ($v, $k) => in_array($k, $lacp_keys)) && $device['interface'] !== null && $device['port_list'] !== null) {
                $current_lacp = [
                    'mode' => $device['lacp_mode'] ?? 'ACTIVE',
                    'trunk_type' => $device['trunk_type'] ?? 'LACP',
                    'port_list' => $device['port_list'] ?? null,
                    'rate' => $device['lacp_rate'] ?? 'SLOW',
                ];
                if (! in_array($current_lacp, $unique_lacp)) {
                    $unique_lacp[] = $current_lacp;
                }
            }
        }

        $devices_grouped_config_all = array_map(
            fn ($serial) => array_filter($normalized_devices, fn ($device) => $device['serial'] === $serial),
            $unique_devices
        );

        $devices_grouped_config_with_interface_ranges = array_values(array_filter($devices_grouped_config_all, fn ($device_group) => count($device_group) > 0));
        $devices_grouped_config = array_map(fn ($device_group) => array_reduce(
            array_map(fn ($device) => static::expandInterfaceRangeConfig($device), $device_group), fn ($carry, $item) => array_merge($carry, $item), []),
            $devices_grouped_config_with_interface_ranges);

        $total_interfaces = 0;
        array_map(function ($interfaces) use (&$total_interfaces) {
            $total_interfaces += count($interfaces);
        }, $devices_grouped_config);

        return [
            'unique_switchports' => $unique_switchports,
            'unique_stp' => $unique_stp,
            'unique_lacp' => $unique_lacp,
            'devices_grouped_config' => $devices_grouped_config,
            'total_interfaces' => $total_interfaces,
        ];
    }

    /**
     * Save the interfaces to the device_interfaces, swich_ports and lacp_profiles tables
     */
    public static function saveInterfaces($interfaces, ?int $userId = null)
    {
        $saved_interfaces = 0;
        foreach ($interfaces['devices_grouped_config'] as $device_interfaces) {
            foreach ($device_interfaces as $device_interface) {
                $deviceQuery = Device::query()->where('serial', $device_interface['serial']);
                if ($userId !== null) {
                    $deviceQuery->where('user_id', $userId);
                }

                $device = $deviceQuery->first();
                if (! $device) {
                    continue;
                }

                $kind = $device_interface['interface_kind'] ?? InterfaceKind::ETHERNET;
                $kindValue = $kind instanceof InterfaceKind ? $kind->value : (string) $kind;

                $isRoutedEthernet = InterfaceHelper::isRoutedEthernetRow($device_interface);
                $isRoutedLag = InterfaceHelper::isRoutedLagRow($device_interface);
                $isRouted = $isRoutedEthernet || $isRoutedLag;

                $device_interface_config = [
                    'device_id' => $device->id,
                    'interface' => $device_interface['interface'],
                    'interface_kind' => $kindValue,
                    'description' => $device_interface['description'] ?? null,
                    'ip_address' => $device_interface['ip_address'] ?? null,
                    'sw_profile' => $isRouted ? null : ($device_interface['port_profile'] ?? null),
                    'shutdown_on_split' => $isRouted ? false : BooleanHelper::toBoolean($device_interface['shutdown_on_split'] ?? false),
                    'switch_port_id' => $isRouted ? null : DeviceInterfaceUpdateResolver::resolveSwitchPortId($device_interface),
                    'stp_profile_id' => $isRouted ? null : DeviceInterfaceUpdateResolver::resolveStpProfileId($device_interface),
                    'lacp_profile_id' => $isRoutedEthernet ? null : DeviceInterfaceUpdateResolver::resolveLacpProfileId($device_interface),
                ];

                $upsertColumns = ['sw_profile', 'shutdown_on_split', 'switch_port_id', 'stp_profile_id', 'lacp_profile_id', 'description', 'ip_address', 'interface_kind'];

                if ($isRouted) {
                    $device_interface_config['routing'] = true;
                    $upsertColumns[] = 'routing';

                    if (array_key_exists('vrf_forwarding', $device_interface) && trim((string) ($device_interface['vrf_forwarding'] ?? '')) !== '') {
                        $device_interface_config['vrf_forwarding'] = trim((string) $device_interface['vrf_forwarding']);
                        $upsertColumns[] = 'vrf_forwarding';
                    }
                }

                DeviceInterface::upsert(
                    $device_interface_config,
                    ['interface', 'device_id', 'interface_kind'],
                    $upsertColumns
                );
                $saved_interfaces++;
            }
        }

        return $saved_interfaces;
    }

    protected static function normalizeSwitchPortAttributes(array $interfaceData): ?array
    {
        if (! array_key_exists('interface_mode', $interfaceData) || $interfaceData['interface_mode'] === null) {
            return null;
        }

        $mode = $interfaceData['interface_mode'] ?? 'ACCESS';
        if ($mode === 'TRUNK') {
            return [
                'interface_mode' => 'TRUNK',
                'access_vlan' => null,
                'native_vlan' => (int) ($interfaceData['native_vlan'] ?? 1),
                'trunk_vlan_all' => BooleanHelper::toBoolean($interfaceData['trunk_vlan_all'] ?? false),
                'trunk_vlan_ranges' => TrunkVlanRanges::normalizeForStorage($interfaceData['trunk_vlan_ranges'] ?? null),
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

    /**
     * Save switchport configurations in the switch_ports table
     */
    public static function saveSwitchPorts($unique_switchports)
    {
        foreach ($unique_switchports as $unique_switchport) {
            if ($unique_switchport['interface_mode'] === 'ACCESS') {
                if (SwitchPort::where('access_vlan', (int) $unique_switchport['access_vlan'])->doesntExist()) {
                    SwitchPort::create([
                        ...$unique_switchport,
                        'native_vlan' => null,
                        'trunk_vlan_all' => null,
                    ]);
                }
            } else {
                if ((bool) $unique_switchport['trunk_vlan_all'] === true) {
                    if (SwitchPort::where('native_vlan', (int) $unique_switchport['native_vlan'])
                        ->where('trunk_vlan_all', true)->doesntExist()) {
                        SwitchPort::create([
                            ...$unique_switchport,
                            'access_vlan' => null,
                        ]);
                    }
                } else {
                    if (SwitchPort::where('native_vlan', $unique_switchport['native_vlan'])
                        ->where('trunk_vlan_ranges', $unique_switchport['trunk_vlan_ranges'])
                        ->doesntExist()) {
                        SwitchPort::create($unique_switchport);
                    }
                }
            }
        }
    }

    /**
     * Save STP configurations in the stp_profiles table
     */
    public static function saveStp($stp_profiles)
    {
        foreach ($stp_profiles as $stp_profile) {
            if (StpProfile::where('admin_edge_port', $stp_profile['admin_edge_port'])
                ->where('admin_edge_port_trunk', $stp_profile['admin_edge_port_trunk'])
                ->where('bpdu_guard', $stp_profile['bpdu_guard'])
                ->where('loop_guard', $stp_profile['loop_guard'])
                ->doesntExist()) {
                StpProfile::create($stp_profile);
            }
        }
    }

    public static function saveLacp($lacp_profiles)
    {
        foreach ($lacp_profiles as $lacp_profile) {
            if (LacpProfile::where('mode', $lacp_profile['mode'])
                ->where('trunk_type', $lacp_profile['trunk_type'])
                ->where('port_list', $lacp_profile['port_list'])
                ->where('rate', $lacp_profile['rate'])
                ->doesntExist()) {
                LacpProfile::create($lacp_profile);
            }
        }
    }

    public static function getSitesWithDeviceSerials(array $deviceArray)
    {
        $devices_with_sites = array_filter($deviceArray, fn ($device) => array_key_exists('site', $device) && $device['site'] !== '');
        $sites = array_unique(array_column($devices_with_sites, 'site'));
        $sites_with_devices = array_map(fn ($site) => [
            'name' => $site,
            'devices' => array_map(fn ($device) => $device['serial'], array_filter($devices_with_sites, fn ($device) => $device['site'] === $site)),
        ], $sites);

        return $sites_with_devices;
    }

    public static function saveSitesWithDevices(array $sites_with_devices, Client $client, ?int $userId = null)
    {
        $saved_sites = [];
        foreach ($sites_with_devices as $site_with_devices) {
            $site = Site::firstOrCreateForClient($client, $site_with_devices['name']);
            $saved_sites[] = $site;
            array_map(function ($device) use ($site, $userId, $client): void {
                $deviceQuery = Device::query()->where('serial', $device);
                if ($userId !== null) {
                    $deviceQuery->where('user_id', $userId);
                }
                $siteDevice = $deviceQuery->first();
                if ($siteDevice === null || (int) $siteDevice->client_id !== (int) $client->id) {
                    return;
                }
                $siteDevice->update(['site_id' => $site->id]);
            }, $site_with_devices['devices']);
        }

        return $saved_sites;
    }

    public function show(Request $request, Device $device): Response
    {
        if ($request->user()->id !== $device->user_id || $request->user()->currentClient()?->id !== $device->client_id) {
            abort(403);
        }

        $device->load([
            'deployment',
            'site',
        ]);

        $interfaces = DeviceInterface::query()
            ->where('device_id', $device->id)
            ->with(['switch_port', 'lacp_profile', 'stp_profile'])
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString()
            ->through(function (DeviceInterface $interface) {
                return [
                    'id' => $interface->id,
                    'interface' => $interface->interface,
                    'description' => $interface->description,
                    'ip_address' => $interface->ip_address,
                    'enable' => $interface->enable,
                    'jumbo_frames' => (bool) $interface->jumbo_frames,
                    'routing' => (bool) $interface->routing,
                    'shutdown_on_split' => (bool) $interface->shutdown_on_split,
                    'vrf_forwarding' => $interface->vrf_forwarding,
                    'sw_profile' => $interface->sw_profile,
                    'portchannel_lag' => $interface->portchannel_lag,
                    'switch_port' => $interface->switch_port
                        ? SwitchPortResource::make($interface->switch_port)->resolve()
                        : null,
                    'lacp_profile' => $interface->lacp_profile
                        ? LacpProfileResource::make($interface->lacp_profile)->resolve()
                        : null,
                    'stp_profile' => $interface->stp_profile
                        ? StpProfileResource::make($interface->stp_profile)->resolve()
                        : null,
                ];
            });

        $client = $request->user()->currentClient();
        $central = new CentralAPIHelper($client);
        $sitesResult = $central->collectScopeManagementSites();
        $groupsResult = $central->collectScopeManagementDeviceGroups();

        $centralSites = self::ensureScopeNameInCentralList(
            $sitesResult['sites'],
            $device->site?->name,
            $device->site?->scope_id,
        );
        $centralDeviceGroups = self::ensureScopeNameInCentralList(
            $groupsResult['groups'],
            $device->group,
            null,
        );

        return Inertia::render('Device/Show', [
            'device' => [
                'id' => $device->id,
                'name' => $device->name,
                'site' => $device->site?->name,
                'group' => $device->group,
                'serial' => $device->serial,
                'sku' => $device->sku,
                'scope_id' => $device->scope_id,
                'device_function' => $device->device_function,
            ],
            'deployment' => [
                'id' => $device->deployment->id,
                'name' => $device->deployment->name,
            ],
            'interfaces' => $interfaces,
            'central_sites' => $centralSites,
            'central_sites_error' => $sitesResult['error'],
            'central_device_groups' => $centralDeviceGroups,
            'central_device_groups_error' => $groupsResult['error'],
        ]);
    }

    public function updateMetadata(UpdateDeviceMetadataRequest $request, Device $device)
    {
        if ($request->user()->id !== $device->user_id || $request->user()->currentClient()?->id !== $device->client_id) {
            abort(403);
        }

        $validated = $request->validated();

        if (array_key_exists('site', $validated)) {
            $siteName = $validated['site'];
            if ($siteName === null || $siteName === '') {
                $device->update(['site_id' => null]);
            } else {
                $site = Site::firstOrCreateForClient($device->client, $siteName);
                $central = new CentralAPIHelper($device->client);
                $centralSite = collect($central->collectScopeManagementSites()['sites'])
                    ->firstWhere('scopeName', $siteName);
                if (is_array($centralSite) && ($centralSite['scopeId'] ?? '') !== '') {
                    $site->scope_id = $centralSite['scopeId'];
                    $site->save();
                }
                $device->update(['site_id' => $site->id]);
            }
        }

        if (array_key_exists('group', $validated)) {
            $group = $validated['group'];
            $device->update([
                'group' => $group === null || $group === '' ? null : $group,
            ]);
        }

        return back()->with('success', 'Device updated successfully.');
    }

    /**
     * @param  array<int, array{scopeName: string, scopeId: string}>  $items
     * @return array<int, array{scopeName: string, scopeId: string}>
     */
    private static function ensureScopeNameInCentralList(
        array $items,
        ?string $currentName,
        ?string $currentScopeId,
    ): array {
        $currentName = $currentName !== null ? trim($currentName) : '';
        if ($currentName === '') {
            return $items;
        }

        foreach ($items as $item) {
            if (($item['scopeName'] ?? '') === $currentName) {
                return $items;
            }
        }

        $items[] = [
            'scopeName' => $currentName,
            'scopeId' => trim((string) ($currentScopeId ?? '')),
        ];

        return $items;
    }

    public function updateInterfaces(UpdateDeviceInterfacesRequest $request, Device $device)
    {
        if ($request->user()->id !== $device->user_id || $request->user()->currentClient()?->id !== $device->client_id) {
            abort(403);
        }

        $updates = $request->validated('updates', []);
        if (count($updates) === 0) {
            return back()->with('success', 'No interface updates to apply.');
        }

        $ids = collect($updates)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $interfaces = DeviceInterface::query()
            ->where('device_id', $device->id)
            ->whereIn('id', $ids)
            ->with(['switch_port', 'lacp_profile', 'stp_profile'])
            ->get()
            ->keyBy('id');

        if ($interfaces->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'updates' => 'One or more interfaces do not belong to the selected device.',
            ]);
        }

        $resolver = new DeviceInterfaceUpdateResolver;

        DB::transaction(function () use ($updates, $interfaces, $resolver): void {
            foreach ($updates as $index => $update) {
                /** @var DeviceInterface $interface */
                $interface = $interfaces->get((int) $update['id']);
                $resolved = $resolver->resolve($interface, $update, $index);
                $resolver->applyResolved($interface, $resolved);
            }
        });

        return back()->with('success', 'Interface updates saved successfully.');
    }

    /**
     * Remove a device interface row and optionally delete orphan profile records.
     */
    public function destroyInterface(Request $request, Device $device, DeviceInterface $deviceInterface)
    {
        if ($request->user()->id !== $device->user_id || $request->user()->currentClient()?->id !== $device->client_id) {
            abort(403);
        }

        if ($deviceInterface->device_id !== $device->id) {
            abort(404);
        }

        $switchPortId = $deviceInterface->switch_port_id;
        $lacpProfileId = $deviceInterface->lacp_profile_id;
        $stpProfileId = $deviceInterface->stp_profile_id;

        DB::transaction(function () use ($deviceInterface, $switchPortId, $lacpProfileId, $stpProfileId): void {
            $deviceInterface->delete();

            if ($switchPortId !== null && ! DeviceInterface::query()->where('switch_port_id', $switchPortId)->exists()) {
                SwitchPort::query()->whereKey($switchPortId)->delete();
            }
            if ($lacpProfileId !== null && ! DeviceInterface::query()->where('lacp_profile_id', $lacpProfileId)->exists()) {
                LacpProfile::query()->whereKey($lacpProfileId)->delete();
            }
            if ($stpProfileId !== null && ! DeviceInterface::query()->where('stp_profile_id', $stpProfileId)->exists()) {
                StpProfile::query()->whereKey($stpProfileId)->delete();
            }
        });

        return back()->with('success', 'Interface removed from this device.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Device $device)
    {
        if ($request->user()->id !== $device->user_id || $request->user()->currentClient()?->id !== $device->client_id) {
            abort(403);
        }

        $data = [];
        if ($request->has('name')) {
            $validated_name = $request->validate(['name' => 'string|min:3|max:255']);
            $data = array_merge($data, ['name' => $validated_name['name']]);
        }
        if ($request->has('serial')) {
            $validated_serial = $request->validate(['serial' => 'string|min:10']);
            $data = array_merge($data, ['serial' => $validated_serial['serial']]);
        }
        if ($request->has('device_function')) {
            $validated_device_function = $request->validate(['device_function' => Rule::in(DeviceFunction::cases())]);
            $data = array_merge($data, ['device_function' => $validated_device_function['device_function']]);
        }
        if ($request->has('group')) {
            $validated_group = $request->validate(['group' => 'string|min:3|max:255']);
            $data = array_merge($data, ['group' => $validated_group['group']]);
        }
        if ($request->has('deployment_id')) {
            $client = $device->client;
            $deployment = $client->deployments()->find($request->deployment_id);
            if (! $deployment) {
                return back()->withErrors('Deployment does not belong to current client.', 'deployment_id');
            }
            $data = array_merge($data, ['deployment_id' => $deployment->id]);
        }

        $serialChanging = isset($data['serial']) && $data['serial'] !== $device->serial;

        if ($serialChanging) {
            if (Device::query()
                ->where('serial', $data['serial'])
                ->where('user_id', $device->user_id)
                ->where('id', '!=', $device->id)
                ->exists()) {
                return back()->withErrors(['serial' => 'A device with this serial already exists.']);
            }

            $newDevice = DB::transaction(function () use ($device, $data): Device {
                $newDevice = $device->replicate();
                $newDevice->fill($data);
                $newDevice->save();

                DeviceInterface::query()->where('device_id', $device->id)->update(['device_id' => $newDevice->id]);

                DB::table('device_task')->where('device_id', $device->id)->update(['device_id' => $newDevice->id]);

                $device->delete();

                return $newDevice->fresh();
            });

            $deployment = $newDevice->deployment;

            return to_route('deployments.show', $deployment)->with('success', 'Device updated successfully');
        }

        $device->update($data);

        $deployment = $device->fresh()->deployment;

        return to_route('deployments.show', $deployment)->with('success', 'Device updated successfully');
    }

    /**
     *  Refresh the device scope-id in Central
     */
    public function refreshScopeId(Request $request, Device $device)
    {
        if ($request->user()->id !== $device->user_id || $request->user()->currentClient()?->id !== $device->client_id) {
            abort(403);
        }

        $centralAPIHelper = new CentralAPIHelper($request->user()->currentClient());
        $scopeId = $centralAPIHelper->getScopeIdFromCentral($device);
        if (! array_key_exists('error', $scopeId)) {
            $device->update(['scope_id' => array_pop($scopeId)['scopeId']]);

            return to_route('deployments.show', $device->fresh()->deployment)->with('success', 'Device scope ID refreshed successfully');
        }

        return to_route('deployments.show', $device->fresh()->deployment)->with('error', 'Failed to refresh device scope ID');
    }

    public function refreshScopeIds(Request $request, Deployment $deployment)
    {
        $currentClient = $request->user()->currentClient();
        if (! $currentClient || (int) $deployment->client_id !== (int) $currentClient->id) {
            session()->flash('error', 'Please set current client to match this deployment before refreshing device scope IDs.');

            return back();
        }

        $data = $request->validate([
            'device_ids' => ['nullable', 'array', 'min:1'],
            'device_ids.*' => ['integer'],
            'sync_all' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $syncAll = $request->boolean('sync_all');
        $deviceIds = $data['device_ids'] ?? [];

        if (! $syncAll && $deviceIds === []) {
            throw ValidationException::withMessages([
                'device_ids' => 'Select at least one device or sync all devices.',
            ]);
        }

        $search = is_string($data['search'] ?? null) ? mb_substr(trim($data['search']), 0, 255) : '';

        if ($syncAll) {
            $devicesQuery = $deployment->devices();
            $this->applyDeploymentDeviceSearch($devicesQuery, $search);
            $devices = $devicesQuery->get();
        } else {
            $devices = $deployment->devices()
                ->whereIn('id', $deviceIds)
                ->get();

            $missingIds = collect($deviceIds)->diff($devices->pluck('id'));
            if ($missingIds->isNotEmpty()) {
                session()->flash('error', 'One or more selected devices do not belong to this deployment.');

                return back();
            }
        }

        if ($devices->isEmpty()) {
            session()->flash('error', 'No devices selected to refresh scope IDs.');

            return back();
        }

        $centralAPIHelper = new CentralAPIHelper($currentClient);
        $succeeded = collect();
        $failedNames = [];

        foreach ($devices as $device) {
            $scopeId = $centralAPIHelper->getScopeIdFromCentral($device);
            if (array_key_exists('error', $scopeId)) {
                $failedNames[] = $device->name;

                continue;
            }

            $device->update(['scope_id' => array_pop($scopeId)['scopeId']]);
            $succeeded->push($device->fresh());
        }

        if ($succeeded->isEmpty()) {
            session()->flash('error', 'Failed to refresh device scope IDs.');

            return back();
        }

        if ($failedNames !== []) {
            session()->flash(
                'error',
                'Failed to refresh scope ID for: '.implode(', ', $failedNames).'. '
                .$this->formatDeviceScopeIdUpdateFlashMessage($succeeded)
            );

            return back();
        }

        session()->flash('success', $this->formatDeviceScopeIdUpdateFlashMessage($succeeded));

        return back();
    }

    private function applyDeploymentDeviceSearch(Builder|Relation $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $pattern = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';
        $query->where(function ($inner) use ($pattern) {
            $inner->whereRaw('lower(name) LIKE ?', [$pattern])
                ->orWhereRaw('lower(serial) LIKE ?', [$pattern])
                ->orWhereRaw('lower(device_function) LIKE ?', [$pattern]);
        });
    }

    private function formatDeviceScopeIdUpdateFlashMessage(Collection $devices): string
    {
        $details = $devices
            ->sortBy(fn (Device $device) => $device->name)
            ->map(fn (Device $device) => "{$device->name}: {$device->scope_id}")
            ->values()
            ->all();

        if (count($details) === 1) {
            return "Updated scope ID for {$details[0]}.";
        }

        return 'Updated scope IDs: '.implode(', ', $details).'.';
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Device $device)
    {
        if ($request->user()->id !== $device->user_id || $request->user()->currentClient()?->id !== $device->client_id) {
            abort(403);
        }

        $deployment = $device->deployment;
        if ($device->interfaces()->count() > 0) {
            $device->interfaces->map(fn ($interface) => $interface->tasks()->detach());
        }
        if ($device->tasks()->count() > 0) {
            $device->tasks()->detach();
        }
        $device->delete();

        return to_route('deployments.show', $deployment);
    }
}
