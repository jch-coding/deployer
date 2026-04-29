<?php

namespace App\Http\Controllers;

use App\DeviceFunction;
use App\Helper\CentralAPIHelper;
use App\Http\Requests\UpdateDeviceInterfacesRequest;
use App\Http\Resources\LacpProfileResource;
use App\Http\Resources\StpProfileResource;
use App\Http\Resources\SwitchPortResource;
use App\Helper\CSVHelper;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\LacpProfile;
use App\Models\Site;
use App\Models\StpProfile;
use App\Models\SwitchPort;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DeviceController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Deployment $deployment)
    {
        $data = $request->validate([
            'name' => 'required|string|min:3|max:255',
            'serial' => 'required|string|min:12',
            'device_function' => [
                'required',
                Rule::in(DeviceFunction::cases()),
            ],
        ]);

        if ($request->user()->currentClient()->id !== $deployment->client_id) {
            return redirect()->route('deployments.show', $deployment)->with('error', 'Device cannot be created for this deployment');
        }

        if (Device::where('serial', $data['serial'])->exists()) {
            $device = Device::where('serial', $data['serial'])->first();
            $device->update([
                ...$data,
                'client_id' => $request->user()->currentClient()->id,
                'deployment_id' => $deployment->id,
            ]);

            return redirect()->route('deployments.show', $deployment)->with('success', 'Device updated successfully');
        }
        Device::create([
            ...$data,
            'client_id' => $request->user()->currentClient()->id,
            'deployment_id' => $deployment->id,
        ]);

        return to_route('deployments.show', $deployment)->with('success', 'Device created successfully');
    }

    /**
     *  Parse CSV file uploaded and store information about devices
     */
    public function storeMany(Request $request, Deployment $deployment)
    {
        if (! $request->hasFile('devices')) {
            return back()->withErrors('No file uploaded');
        }

        $file = $request->file('devices');
        $csvData = CSVHelper::processCSVFile($file->getPathname());
        $devices = CSVHelper::createDeviceArrays($csvData);

        if (count($devices) === 0) {
            return back()->withErrors('No devices found in CSV file');
        }

        $headers = $csvData[0];
        if (! in_array('name', $headers) || ! in_array('serial', $headers) || ! in_array('device_function', $headers)) {
            return back()->withErrors('CSV file does not contain required headers. Must include name, serial and device_function');
        }

        $unique_devices = $this->consolidateDataForDevices($devices);

        $withDeployment = array_map(
            fn ($arr) => [
                'name' => $arr['name'],
                'serial' => $arr['serial'],
                'device_function' => $arr['device_function'],
                'deployment_id' => $deployment->id,
                'group' => $arr['group'] ?? null,
                'sku' => $arr['sku'] == '' ? null : $arr['sku'],
            ],
            $unique_devices
        );

        $savedDevices = $request->user()->currentClient()->devices()->upsert($withDeployment, ['serial'], ['name', 'device_function', 'deployment_id', 'group', 'sku']);

        $errors = [];
        $unsaved_devices = [];
        $unsaved_interfaces = [];
        $unsaved_sites = [];

        if ($savedDevices !== count($devices)) {
            $unsaved_devices = array_filter($devices, fn ($device) => Device::where('serial', $device['serial'])->doesntExist());
        }

        if (in_array('site', $headers)) {
            $sites_with_devices = static::getSitesWithDeviceSerials($devices);
            $saved_sites = static::saveSitesWithDevices($sites_with_devices);
            $unsaved_sites = array_filter($sites_with_devices, fn ($site) => Site::where('name', $site['name'])->doesntExist());
        }

        if (in_array('interface', $headers)) {

            $interfaces = static::getInterfaces($devices);

            $savedInterfaces = static::saveInterfaces($interfaces);

            if ($savedInterfaces !== $interfaces['total_interfaces']) {
                $unsaved_interfaces = array_filter($interfaces,
                    fn ($interface) => DeviceInterface::where('interface', $interface['interface'])
                        ->where('device_id', $interface['device_id'])
                        ->doesntExist());
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
        $interface_pairs = array_map(fn ($pair) => explode('-', $pair), explode('&', $range));
        $expanded_ranges = [];
        foreach ($interface_pairs as $pair) {
            if (count($pair) == 2) {
                $interface_parts = explode('/', $pair[0]);
                $prefix = $interface_parts[0].'/'.$interface_parts[1].'/';
                $start = (int) $interface_parts[2];
                $end = (int) explode('/', $pair[1])[2];
                $expanded_ranges = array_merge($expanded_ranges, array_map(fn ($i) => $prefix.$i, range($start, $end)));
            } else {
                $expanded_ranges[] = $pair[0];
            }
        }

        return $expanded_ranges;
    }

    public static function expandInterfaceRangeConfig(array $interface_config)
    {
        $interface_range = static::expandInterfaceRange($interface_config['interface']);
        $interface_range_configs = array_map(fn ($range) => array_merge($interface_config, ['interface' => $range]), $interface_range);

        return $interface_range_configs;
    }

    public static function getInterfaces($devices)
    {
        $unique_devices = array_unique(array_column($devices, 'serial'));
        $devices_with_interface_info = array_filter($devices, fn ($device) => array_key_exists('interface', $device) && $device['interface'] !== '');
        $normalized_devices = array_map(fn ($device) => array_map(fn ($v) => $v === '' ? null : $v, $device), $devices_with_interface_info);

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
                        'native_vlan' => (int) $device['native_vlan'] ?? 1,
                        'trunk_vlan_all' => (bool) $device['trunk_vlan_all'] ?? false,
                        'trunk_vlan_ranges' => $device['trunk_vlan_ranges'] ?? null,
                    ];
                } else {
                    $current_switchport = [
                        'interface_mode' => $device['interface_mode'],
                        'access_vlan' => $device['access_vlan'] ?? 1,
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
                    'admin_edge_port' => $device['admin_edge_port'] ?? false,
                    'admin_edge_port_trunk' => $device['admin_edge_port_trunk'] ?? false,
                    'bpdu_guard' => $device['bpdu_guard'] ?? false,
                    'loop_guard' => $device['loop_guard'] ?? false,
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

        $devices_grouped_config_with_interface_ranges = array_filter($devices_grouped_config_all, fn ($device_group) => count($device_group) > 0);
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
    public static function saveInterfaces($interfaces)
    {
        $saved_interfaces = 0;
        foreach ($interfaces['devices_grouped_config'] as $device_interfaces) {
            foreach ($device_interfaces as $device_interface) {
                $device = Device::where('serial', $device_interface['serial'])->first();
                $device_interface_config = [
                    'device_id' => $device->id,
                    'interface' => $device_interface['interface'],
                    'description' => $device_interface['description'] ?? null,
                    'ip_address' => $device_interface['ip_address'] ?? null,
                    'sw_profile' => $device_interface['port_profile'] ?? null,
                    'switch_port_id' => static::resolveSwitchPortId($device_interface),
                    'stp_profile_id' => static::resolveStpProfileId($device_interface),
                    'lacp_profile_id' => static::resolveLacpProfileId($device_interface),
                ];

                DeviceInterface::upsert($device_interface_config, ['interface', 'device_id'], ['sw_profile', 'switch_port_id', 'stp_profile_id', 'lacp_profile_id', 'description', 'ip_address']);
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
                'trunk_vlan_all' => (bool) ($interfaceData['trunk_vlan_all'] ?? false),
                'trunk_vlan_ranges' => $interfaceData['trunk_vlan_ranges'] ?? null,
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
            'admin_edge_port' => (bool) ($interfaceData['admin_edge_port'] ?? false),
            'admin_edge_port_trunk' => (bool) ($interfaceData['admin_edge_port_trunk'] ?? false),
            'bpdu_guard' => (bool) ($interfaceData['bpdu_guard'] ?? false),
            'loop_guard' => (bool) ($interfaceData['loop_guard'] ?? false),
        ];
    }

    protected static function normalizeLacpAttributes(array $interfaceData): ?array
    {
        $lacpPortList = $interfaceData['port_list'] ?? null;
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

    protected static function resolveSwitchPortId(array $interfaceData): ?int
    {
        $switchPortAttributes = static::normalizeSwitchPortAttributes($interfaceData);
        if ($switchPortAttributes === null) {
            return null;
        }

        return SwitchPort::firstOrCreate($switchPortAttributes)->id;
    }

    protected static function resolveStpProfileId(array $interfaceData): ?int
    {
        $stpAttributes = static::normalizeStpAttributes($interfaceData);
        if ($stpAttributes === null) {
            return null;
        }

        return StpProfile::firstOrCreate($stpAttributes)->id;
    }

    protected static function resolveLacpProfileId(array $interfaceData): ?int
    {
        $lacpAttributes = static::normalizeLacpAttributes($interfaceData);
        if ($lacpAttributes === null) {
            return null;
        }

        return LacpProfile::firstOrCreate($lacpAttributes)->id;
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

    public static function saveSitesWithDevices(array $sites_with_devices)
    {
        $saved_sites = [];
        foreach ($sites_with_devices as $site_with_devices) {
            $site = Site::firstOrCreate(['name' => $site_with_devices['name']]);
            $saved_sites[] = $site;
            array_map(fn ($device) => Device::where('serial', $device)->get()->first()->update(['site_id' => $site->id]), $site_with_devices['devices']);
        }

        return $saved_sites;
    }

    public function show(Request $request, Device $device): Response
    {
        if ($request->user()->currentClient()->id !== $device->client_id) {
            abort(403);
        }

        $device->load([
            'deployment',
            'site',
            'interfaces.switch_port',
            'interfaces.lacp_profile',
            'interfaces.stp_profile',
        ]);

        $interfaces = $device->interfaces->map(function (DeviceInterface $interface) {
            return [
                'id' => $interface->id,
                'interface' => $interface->interface,
                'description' => $interface->description,
                'ip_address' => $interface->ip_address,
                'enable' => $interface->enable,
                'jumbo_frames' => (bool) $interface->jumbo_frames,
                'routing' => (bool) $interface->routing,
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
        })->values()->all();

        return Inertia::render('Device/Show', [
            'device' => [
                'id' => $device->id,
                'name' => $device->name,
                'site' => $device->site?->name,
                'group' => $device->group,
                'serial' => $device->serial,
                'scope_id' => $device->scope_id,
                'device_function' => $device->device_function,
            ],
            'deployment' => [
                'id' => $device->deployment->id,
                'name' => $device->deployment->name,
            ],
            'interfaces' => $interfaces,
        ]);
    }

    public function updateInterfaces(UpdateDeviceInterfacesRequest $request, Device $device)
    {
        if ($request->user()->currentClient()->id !== $device->client_id) {
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

        DB::transaction(function () use ($updates, $interfaces): void {
            foreach ($updates as $index => $update) {
                /** @var DeviceInterface $interface */
                $interface = $interfaces->get((int) $update['id']);
                $resolved = $this->resolveInterfaceUpdateData($interface, $update, $index);

                $interface->description = $resolved['description'];
                $interface->ip_address = $resolved['ip_address'];
                $interface->enable = $resolved['enable'];
                $interface->jumbo_frames = $resolved['jumbo_frames'];
                $interface->routing = $resolved['routing'];
                $interface->vrf_forwarding = $resolved['vrf_forwarding'];
                $interface->sw_profile = $resolved['sw_profile'];
                $interface->portchannel_lag = $resolved['portchannel_lag'];
                $interface->switch_port_id = $resolved['switch_port_id'];
                $interface->lacp_profile_id = $resolved['lacp_profile_id'];
                $interface->stp_profile_id = $resolved['stp_profile_id'];
                $interface->save();
            }
        });

        return back()->with('success', 'Interface updates saved successfully.');
    }

    protected function resolveInterfaceUpdateData(DeviceInterface $interface, array $update, int $index): array
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

                $switchPortData = [
                    'interface_mode' => 'TRUNK',
                    'native_vlan' => (int) $nativeVlan,
                    'trunk_vlan_all' => (bool) ($update['trunk_vlan_all'] ?? $interface->switch_port?->trunk_vlan_all ?? false),
                    'trunk_vlan_ranges' => $update['trunk_vlan_ranges'] ?? $interface->switch_port?->trunk_vlan_ranges,
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
            'vrf_forwarding' => array_key_exists('vrf_forwarding', $update) ? $update['vrf_forwarding'] : $interface->vrf_forwarding,
            'sw_profile' => array_key_exists('sw_profile', $update) ? $update['sw_profile'] : $interface->sw_profile,
            'portchannel_lag' => array_key_exists('portchannel_lag', $update) ? $update['portchannel_lag'] : $interface->portchannel_lag,
            'switch_port_id' => $switchPortData ? static::resolveSwitchPortId($switchPortData) : $interface->switch_port_id,
            'lacp_profile_id' => $lacpData ? static::resolveLacpProfileId($lacpData) : null,
            'stp_profile_id' => $stpData ? static::resolveStpProfileId($stpData) : null,
        ];
    }

    protected function normalizeLacpPortList(array|string|null $portList): array
    {
        if ($portList === null) {
            return [];
        }

        $parts = is_array($portList) ? $portList : explode('&', $portList);

        return array_values(array_filter(array_map(
            static fn ($part) => trim((string) $part),
            $parts
        )));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Device $device)
    {
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
            if (Device::query()->where('serial', $data['serial'])->where('id', '!=', $device->id)->exists()) {
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
        $centralAPIHelper = new CentralAPIHelper($request->user()->currentClient());
        $scopeId = $centralAPIHelper->getScopeIdFromCentral($device);
        if (! array_key_exists('error', $scopeId)) {
            $device->update(['scope_id' => array_pop($scopeId)['scopeId']]);

            return to_route('deployments.show', $device->fresh()->deployment)->with('success', 'Device scope ID refreshed successfully');
        } else {
            return to_route('deployments.show', $device->fresh()->deployment)->with('error', 'Failed to refresh device scope ID');
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Device $device)
    {
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
