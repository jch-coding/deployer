<?php

namespace App\Http\Controllers;

use App\Helper\CentralAPIHelper;
use App\Jobs\RebootAccessPointJob;
use App\Models\Deployment;
use App\Models\DeviceInterfaceSnapshot;
use App\Services\CentralScopeCacheService;
use App\Services\DeviceCentralFilterBuilder;
use App\Services\SwitchPortProfileInterfaceComparer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class DeviceDetailsController extends Controller
{
    private const DEVICE_TYPES = ['ACCESS_POINT', 'SWITCH', 'GATEWAY'];

    private const STATUSES = ['ONLINE', 'OFFLINE'];

    private const DEPLOYMENTS = ['Standalone', 'Cluster', 'Stack'];

    private const MAX_SERIALS = 25;

    private const REBOOT_WHEN_OPTIONS = ['now', 'in_5_minutes', 'in_10_minutes', 'at'];

    public function index(Request $request, DeviceCentralFilterBuilder $filterBuilder, CentralScopeCacheService $centralScopeCacheService)
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            session()->flash('error', 'Please set current client to view device details');

            return to_route('clients.index');
        }

        $validated = $request->validate([
            'site_id' => ['nullable', 'string', 'max:255'],
            'site_name' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'device_type' => ['nullable', 'string', Rule::in(self::DEVICE_TYPES)],
            'status' => ['nullable', 'string', Rule::in(self::STATUSES)],
            'model' => ['nullable', 'string', 'max:255'],
            'firmware_version' => ['nullable', 'string', 'max:255'],
            'deployment' => ['nullable', 'string', Rule::in(self::DEPLOYMENTS)],
            'submitted' => ['nullable', 'boolean'],
        ]);

        $filters = [
            'site_id' => trim((string) ($validated['site_id'] ?? '')),
            'site_name' => trim((string) ($validated['site_name'] ?? '')),
            'serial_number' => trim((string) ($validated['serial_number'] ?? '')),
            'device_name' => trim((string) ($validated['device_name'] ?? '')),
            'device_type' => trim((string) ($validated['device_type'] ?? '')),
            'status' => trim((string) ($validated['status'] ?? '')),
            'model' => trim((string) ($validated['model'] ?? '')),
            'firmware_version' => trim((string) ($validated['firmware_version'] ?? '')),
            'deployment' => trim((string) ($validated['deployment'] ?? '')),
        ];

        $submitted = (bool) ($validated['submitted'] ?? false);

        $helper = new CentralAPIHelper($currentClient);
        $sitesPayload = $centralScopeCacheService->getSites($currentClient);

        $siteOptions = array_map(
            fn (array $site): array => [
                'siteId' => $site['scopeId'],
                'siteName' => $site['scopeName'],
            ],
            $sitesPayload['sites'],
        );

        $centralError = $sitesPayload['error'];
        $devices = [];
        $hasActiveFilters = $this->hasActiveFilters($filters);

        if ($centralError === null && $hasActiveFilters && $submitted) {
            $filter = $filterBuilder->build([
                'siteId' => $filters['site_id'],
                'siteName' => $filters['site_name'],
                'serialNumber' => $filters['serial_number'],
                'deviceName' => $filters['device_name'],
                'deviceType' => $filters['device_type'],
                'status' => $filters['status'],
                'model' => $filters['model'],
                'firmwareVersion' => $filters['firmware_version'],
                'deployment' => $filters['deployment'],
            ]);

            if ($filter !== null) {
                $result = $helper->get_all_devices(['filter' => $filter]);

                if (array_key_exists('error', $result)) {
                    $centralError = (string) $result['error'];
                } else {
                    $devices = array_map(
                        fn (array $item): array => [
                            'deviceName' => (string) ($item['deviceName'] ?? ''),
                            'serialNumber' => (string) ($item['serialNumber'] ?? ''),
                            'deviceType' => (string) ($item['deviceType'] ?? ''),
                            'deviceFunction' => (string) ($item['deviceFunction'] ?? $item['persona'] ?? ''),
                            'model' => (string) ($item['model'] ?? ''),
                            'ipv4' => (string) ($item['ipv4'] ?? ''),
                            'status' => (string) ($item['status'] ?? ''),
                            'deployment' => (string) ($item['deployment'] ?? ''),
                            'siteName' => (string) ($item['siteName'] ?? ''),
                        ],
                        $result,
                    );
                }
            }
        }

        return Inertia::render('DeviceDetails/Index', [
            'devices' => $devices,
            'filters' => $filters,
            'site_options' => $siteOptions,
            'central_error' => $centralError,
            'has_active_filters' => $hasActiveFilters,
            'device_type_options' => self::DEVICE_TYPES,
            'status_options' => self::STATUSES,
            'deployment_options' => self::DEPLOYMENTS,
            ...$centralScopeCacheService->getCacheMetadata($currentClient),
        ]);
    }

    public function show(Request $request, DeviceCentralFilterBuilder $filterBuilder)
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            session()->flash('error', 'Please set current client to view device details');

            return to_route('clients.index');
        }

        $validated = $request->validate([
            'serials' => ['required', 'array', 'min:1', 'max:'.self::MAX_SERIALS],
            'serials.*' => ['required', 'string', 'max:16'],
            'names' => ['nullable', 'array'],
            'names.*' => ['nullable', 'string', 'max:255'],
        ]);

        $serials = $this->normalizeSerials($validated['serials']);

        if ($serials === []) {
            return back()->withErrors(['serials' => 'At least one serial number is required.']);
        }

        $fallbackNames = $this->normalizeNamesBySerial($validated['names'] ?? [], $serials);
        $helper = new CentralAPIHelper($currentClient);
        $metaBySerial = $this->fetchDeviceMetaBySerials($helper, $filterBuilder, $serials);
        $devices = [];

        foreach ($serials as $serial) {
            $meta = $metaBySerial[$serial] ?? [];
            $devices[] = $this->buildDevicePayload(
                $helper,
                $serial,
                $meta,
                $fallbackNames[$serial] ?? '',
            );
        }

        $snapshotSummaries = DeviceInterfaceSnapshot::query()
            ->where('user_id', $request->user()->id)
            ->where('client_id', $currentClient->id)
            ->whereIn('serial', $serials)
            ->get(['serial', 'captured_at', 'device_name'])
            ->map(fn (DeviceInterfaceSnapshot $snapshot): array => [
                'serial' => $snapshot->serial,
                'device_name' => $snapshot->device_name,
                'captured_at' => $snapshot->captured_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('DeviceDetails/Show', [
            'devices' => $devices,
            'snapshot_summaries' => $snapshotSummaries,
        ]);
    }

    public function storeSnapshots(Request $request): JsonResponse
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            return response()->json([
                'message' => 'Please set current client to save interface snapshots.',
            ], 422);
        }

        $validated = $request->validate([
            'devices' => ['required', 'array', 'min:1', 'max:'.self::MAX_SERIALS],
            'devices.*.serial' => ['required', 'string', 'max:16'],
            'devices.*.device_name' => ['nullable', 'string', 'max:255'],
            'devices.*.device_type' => ['nullable', 'string', 'max:255'],
            'devices.*.device_function' => ['nullable', 'string', 'max:255'],
            'devices.*.central_error' => ['nullable', 'string'],
            'devices.*.interfaces' => ['nullable', 'array'],
            'devices.*.interfaces.*.name' => ['nullable', 'string', 'max:255'],
            'devices.*.interfaces.*.status' => ['nullable', 'string', 'max:255'],
            'devices.*.interfaces.*.operStatus' => ['nullable', 'string', 'max:255'],
            'devices.*.interfaces.*.neighbour' => ['nullable', 'string', 'max:255'],
            'devices.*.interfaces.*.neighbourSerial' => ['nullable', 'string', 'max:255'],
            'devices.*.interfaces.*.vlanMode' => ['nullable', 'string', 'max:255'],
            'devices.*.interfaces.*.allowedVlanIds' => ['nullable', 'array'],
            'devices.*.interfaces.*.allowedVlanIds.*' => ['integer'],
            'devices.*.interfaces.*.nativeVlan' => ['nullable', 'string', 'max:255'],
            'devices.*.interfaces.*.poeClass' => ['nullable', 'string', 'max:255'],
            'devices.*.interfaces.*.neighbourFamily' => ['nullable', 'string', 'max:255'],
            'devices.*.interfaces.*.neighbourFunction' => ['nullable', 'string', 'max:255'],
            'devices.*.interfaces.*.neighbourType' => ['nullable', 'string', 'max:255'],
            'devices.*.interfaces.*.transceiverType' => ['nullable', 'string', 'max:255'],
        ]);

        $capturedAt = now();
        $saved = [];

        foreach ($validated['devices'] as $device) {
            $serial = trim((string) $device['serial']);
            if ($serial === '') {
                continue;
            }

            $deviceType = trim((string) ($device['device_type'] ?? ''));
            $deviceFunction = trim((string) ($device['device_function'] ?? ''));

            if ($this->isAccessPoint($deviceType, $deviceFunction)) {
                continue;
            }

            $centralError = trim((string) ($device['central_error'] ?? ''));
            if ($centralError !== '') {
                continue;
            }

            $interfaces = [];
            foreach ($device['interfaces'] ?? [] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $interfaces[] = $this->mapInterfaceItem($item);
            }

            $snapshot = DeviceInterfaceSnapshot::query()->updateOrCreate(
                [
                    'user_id' => $request->user()->id,
                    'client_id' => $currentClient->id,
                    'serial' => $serial,
                ],
                [
                    'device_name' => trim((string) ($device['device_name'] ?? '')),
                    'device_type' => $deviceType !== '' ? $deviceType : 'SWITCH',
                    'device_function' => $deviceFunction,
                    'interfaces' => $interfaces,
                    'captured_at' => $capturedAt,
                ],
            );

            $saved[] = [
                'serial' => $snapshot->serial,
                'device_name' => $snapshot->device_name,
                'captured_at' => $snapshot->captured_at?->toIso8601String(),
            ];
        }

        return response()->json([
            'message' => $saved === []
                ? 'No switch interfaces were available to snapshot.'
                : 'Interface snapshot saved for '.count($saved).' device'.(count($saved) === 1 ? '' : 's').'.',
            'snapshots' => $saved,
        ]);
    }

    public function indexSnapshots(Request $request): JsonResponse
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            return response()->json([
                'message' => 'Please set current client to view interface snapshots.',
            ], 422);
        }

        $validated = $request->validate([
            'serials' => ['required', 'array', 'min:1', 'max:'.self::MAX_SERIALS],
            'serials.*' => ['required', 'string', 'max:16'],
        ]);

        $serials = $this->normalizeSerials($validated['serials']);

        if ($serials === []) {
            return response()->json([
                'message' => 'At least one serial number is required.',
            ], 422);
        }

        $snapshots = DeviceInterfaceSnapshot::query()
            ->where('user_id', $request->user()->id)
            ->where('client_id', $currentClient->id)
            ->whereIn('serial', $serials)
            ->get()
            ->map(fn (DeviceInterfaceSnapshot $snapshot): array => [
                'serial' => $snapshot->serial,
                'device_name' => $snapshot->device_name,
                'device_type' => $snapshot->device_type,
                'device_function' => $snapshot->device_function,
                'interfaces' => $snapshot->interfaces ?? [],
                'captured_at' => $snapshot->captured_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return response()->json([
            'snapshots' => $snapshots,
        ]);
    }

    public function redirectShow(string $serial)
    {
        return redirect()->route('device-details.show', [
            'serials' => [trim($serial)],
        ]);
    }

    public function compareProfiles(Request $request, SwitchPortProfileInterfaceComparer $comparer): JsonResponse
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            return response()->json([
                'message' => 'Please set current client to compare switch profiles.',
            ], 422);
        }

        $validated = $request->validate([
            'serial' => ['required', 'string', 'max:16'],
        ]);

        $helper = new CentralAPIHelper($currentClient);
        $result = $comparer->compare($helper, trim($validated['serial']));

        if ($result['error'] !== null) {
            return response()->json($result, 422);
        }

        return response()->json($result);
    }

    public function showCommands(Request $request): JsonResponse
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            return response()->json([
                'error' => 'Please set current client to run show commands.',
            ], 422);
        }

        $validated = $request->validate([
            'serial' => ['required', 'string', 'max:64'],
            'commands' => ['required', 'array', 'min:1', 'max:20'],
            'commands.*' => ['required', 'string', 'max:512'],
            'device_type' => ['nullable', 'string', Rule::in(['ACCESS_POINT'])],
        ]);

        $helper = new CentralAPIHelper($currentClient);
        $serial = trim($validated['serial']);
        $isAccessPoint = ($validated['device_type'] ?? '') === 'ACCESS_POINT';

        $result = $isAccessPoint
            ? $helper->run_ap_show_commands($serial, $validated['commands'])
            : $helper->run_cx_show_commands($serial, $validated['commands']);

        if (! $result['ok']) {
            $status = $result['status'] ?? 422;
            if (! is_int($status) || $status < 400 || $status > 599) {
                $status = 422;
            }

            return response()->json([
                'error' => $result['error'],
            ], $status);
        }

        return response()->json([
            'task_id' => $result['task_id'],
            'status' => $result['body']['status'] ?? 'INITIATED',
            'start_time' => $result['body']['startTime'] ?? null,
        ], 202);
    }

    public function showCommandsResult(Request $request, string $serial, string $taskId): JsonResponse
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            return response()->json([
                'error' => 'Please set current client to run show commands.',
            ], 422);
        }

        $validated = validator(
            [
                'serial' => $serial,
                'taskId' => $taskId,
                'device_type' => $request->query('device_type'),
            ],
            [
                'serial' => ['required', 'string', 'max:64'],
                'taskId' => ['required', 'uuid'],
                'device_type' => ['nullable', 'string', Rule::in(['ACCESS_POINT'])],
            ],
            [],
            ['taskId' => 'task id'],
        )->validate();

        $helper = new CentralAPIHelper($currentClient);
        $serial = trim($validated['serial']);
        $taskId = trim($validated['taskId']);
        $isAccessPoint = ($validated['device_type'] ?? '') === 'ACCESS_POINT';

        $result = $isAccessPoint
            ? $helper->get_ap_show_commands_result($serial, $taskId)
            : $helper->get_cx_show_commands_result($serial, $taskId);

        if (! $result['ok']) {
            $status = $result['status'] ?? 422;
            if (! is_int($status) || $status < 400 || $status > 599) {
                $status = 422;
            }

            return response()->json([
                'error' => $result['error'],
            ], $status);
        }

        return response()->json($result['body']);
    }

    public function apShowCommandsResult(Request $request, string $serial, string $taskId): JsonResponse
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            return response()->json([
                'error' => 'Please set current client to run show commands.',
            ], 422);
        }

        $validated = validator(
            ['serial' => $serial, 'taskId' => $taskId],
            [
                'serial' => ['required', 'string', 'max:64'],
                'taskId' => ['required', 'uuid'],
            ],
            [],
            ['taskId' => 'task id'],
        )->validate();

        $helper = new CentralAPIHelper($currentClient);
        $result = $helper->get_ap_show_commands_result(
            trim($validated['serial']),
            trim($validated['taskId']),
        );

        if (! $result['ok']) {
            $status = $result['status'] ?? 422;
            if (! is_int($status) || $status < 400 || $status > 599) {
                $status = 422;
            }

            return response()->json([
                'error' => $result['error'],
            ], $status);
        }

        return response()->json($result['body']);
    }

    public function poeBounce(Request $request): JsonResponse
    {
        return $this->startCxPortBounce($request, 'poe');
    }

    public function poeBounceResult(Request $request, string $serial, string $taskId): JsonResponse
    {
        return $this->getCxPortBounceResult($request, $serial, $taskId, 'poe');
    }

    public function portBounce(Request $request): JsonResponse
    {
        return $this->startCxPortBounce($request, 'port');
    }

    public function portBounceResult(Request $request, string $serial, string $taskId): JsonResponse
    {
        return $this->getCxPortBounceResult($request, $serial, $taskId, 'port');
    }

    public function bssids(Request $request, DeviceCentralFilterBuilder $filterBuilder): JsonResponse
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            return response()->json([
                'serial' => '',
                'bssids' => [],
                'error' => 'Please set current client to view BSSIDs.',
            ], 422);
        }

        $validated = $request->validate([
            'serial' => ['required', 'string', 'max:16'],
        ]);

        $serial = trim($validated['serial']);
        $helper = new CentralAPIHelper($currentClient);
        $filter = $filterBuilder->build(['serialNumber' => $serial]);

        if ($filter === null) {
            return response()->json([
                'serial' => $serial,
                'bssids' => [],
                'error' => 'A valid serial number is required.',
            ], 422);
        }

        $result = $helper->get_all_bssids(['filter' => $filter]);

        if (array_key_exists('error', $result)) {
            return response()->json([
                'serial' => $serial,
                'bssids' => [],
                'error' => (string) $result['error'],
            ], 422);
        }

        $bssids = array_map(
            fn (array $item): array => $this->mapBssidItem($item),
            $result,
        );

        return response()->json([
            'serial' => $serial,
            'bssids' => $bssids,
            'error' => null,
        ]);
    }

    public function reboot(Request $request): JsonResponse
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            return response()->json([
                'scheduled' => false,
                'scheduled_at' => null,
                'serials' => [],
                'results' => [],
                'error' => 'Please set current client to reboot access points.',
            ], 422);
        }

        $validated = $request->validate([
            'serials' => ['required', 'array', 'min:1', 'max:'.self::MAX_SERIALS],
            'serials.*' => ['required', 'string', 'max:16'],
            'when' => ['required', 'string', Rule::in(self::REBOOT_WHEN_OPTIONS)],
            'reboot_at' => ['nullable', 'required_if:when,at', 'date', 'after:now'],
        ]);

        $serials = $this->normalizeSerials($validated['serials']);

        if ($serials === []) {
            return response()->json([
                'scheduled' => false,
                'scheduled_at' => null,
                'serials' => [],
                'results' => [],
                'error' => 'At least one serial number is required.',
            ], 422);
        }

        $when = $validated['when'];

        if ($when === 'now') {
            $helper = new CentralAPIHelper($currentClient);
            $results = [];

            foreach ($serials as $serial) {
                $result = $helper->reboot_ap($serial);
                $results[] = [
                    'serial' => $serial,
                    'ok' => (bool) ($result['ok'] ?? false),
                    'error' => ($result['ok'] ?? false) ? null : (string) ($result['error'] ?? 'failed to reboot access point from central.'),
                    'status' => $result['status'] ?? null,
                ];
            }

            return response()->json([
                'scheduled' => false,
                'scheduled_at' => null,
                'serials' => $serials,
                'results' => $results,
                'error' => null,
            ]);
        }

        $scheduledAt = match ($when) {
            'in_5_minutes' => now()->addMinutes(5),
            'in_10_minutes' => now()->addMinutes(10),
            'at' => Carbon::parse((string) $validated['reboot_at']),
            default => now(),
        };

        foreach ($serials as $serial) {
            RebootAccessPointJob::dispatch($currentClient->id, $serial)
                ->delay($scheduledAt);
        }

        return response()->json([
            'scheduled' => true,
            'scheduled_at' => $scheduledAt->toIso8601String(),
            'serials' => $serials,
            'results' => [],
            'error' => null,
        ]);
    }

    public function siteBssids(Request $request, DeviceCentralFilterBuilder $filterBuilder): JsonResponse
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            return response()->json([
                'site_id' => '',
                'site_name' => '',
                'bssids' => [],
                'error' => 'Please set current client to view BSSIDs.',
            ], 422);
        }

        $validated = $request->validate([
            'site_id' => ['nullable', 'string', 'max:255'],
            'site_name' => ['nullable', 'string', 'max:255'],
        ]);

        $siteId = trim((string) ($validated['site_id'] ?? ''));
        $siteName = trim((string) ($validated['site_name'] ?? ''));

        if ($siteId === '' && $siteName === '') {
            return response()->json([
                'site_id' => '',
                'site_name' => '',
                'bssids' => [],
                'error' => 'A site ID or site name is required.',
            ], 422);
        }

        $helper = new CentralAPIHelper($currentClient);
        $filter = $filterBuilder->build([
            'siteId' => $siteId,
            'siteName' => $siteName,
        ]);

        if ($filter === null) {
            return response()->json([
                'site_id' => $siteId,
                'site_name' => $siteName,
                'bssids' => [],
                'error' => 'A site ID or site name is required.',
            ], 422);
        }

        $result = $helper->get_all_bssids(['filter' => $filter]);

        if (array_key_exists('error', $result)) {
            return response()->json([
                'site_id' => $siteId,
                'site_name' => $siteName,
                'bssids' => [],
                'error' => (string) $result['error'],
            ], 422);
        }

        $bssids = array_map(
            fn (array $item): array => [
                'ap_name' => (string) ($item['deviceName'] ?? ''),
                'ap_mac' => (string) ($item['bssid'] ?? ''),
            ],
            $result,
        );

        return response()->json([
            'site_id' => $siteId,
            'site_name' => $siteName,
            'bssids' => $bssids,
            'error' => null,
        ]);
    }

    public function deployments(Request $request): JsonResponse
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            return response()->json([
                'deployments' => [],
                'error' => 'Please set current client to view deployments.',
            ], 422);
        }

        $deployments = $currentClient->deployments()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Deployment $deployment): array => [
                'id' => $deployment->id,
                'name' => $deployment->name,
            ])
            ->values()
            ->all();

        return response()->json([
            'deployments' => $deployments,
            'error' => null,
        ]);
    }

    public function deploymentDevices(Request $request, Deployment $deployment): JsonResponse
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            return response()->json([
                'deployment_id' => $deployment->id,
                'devices' => [],
                'error' => 'Please set current client to view deployment devices.',
            ], 422);
        }

        if ((int) $deployment->client_id !== (int) $currentClient->id) {
            return response()->json([
                'deployment_id' => $deployment->id,
                'devices' => [],
                'error' => 'Deployment does not belong to the current client.',
            ], 422);
        }

        $devices = $deployment->devices()
            ->orderBy('name')
            ->get(['id', 'name', 'serial', 'mac_address'])
            ->filter(fn ($device): bool => trim((string) ($device->mac_address ?? '')) !== '')
            ->map(fn ($device): array => [
                'id' => $device->id,
                'name' => $device->name,
                'serial' => (string) $device->serial,
                'mac_address' => (string) $device->mac_address,
            ])
            ->values()
            ->all();

        return response()->json([
            'deployment_id' => $deployment->id,
            'devices' => $devices,
            'error' => null,
        ]);
    }

    /**
     * @param  array{
     *     device_name?: string,
     *     device_type?: string,
     *     device_function?: string,
     *     model?: string
     * }  $meta
     * @return array{
     *     serial: string,
     *     device_name: string,
     *     device_type: string,
     *     device_function: string,
     *     interfaces: list<array<string, mixed>>,
     *     central_error: string|null
     * }
     */
    private function buildDevicePayload(
        CentralAPIHelper $helper,
        string $serial,
        array $meta = [],
        string $fallbackName = '',
    ): array {
        $deviceName = trim((string) ($meta['device_name'] ?? ''));
        if ($deviceName === '') {
            $deviceName = trim($fallbackName);
        }

        $deviceType = trim((string) ($meta['device_type'] ?? ''));
        $deviceFunction = trim((string) ($meta['device_function'] ?? ''));
        $model = trim((string) ($meta['model'] ?? ''));
        $centralError = null;

        $isAccessPoint = $this->isAccessPoint($deviceType, $deviceFunction, $model);
        $resolvedDeviceType = $isAccessPoint
            ? 'ACCESS_POINT'
            : ($deviceType !== '' ? $deviceType : 'SWITCH');

        $interfaces = [];

        if (! $isAccessPoint) {
            $interfacesResult = $helper->get_all_switch_interfaces($serial);

            if (array_key_exists('error', $interfacesResult)) {
                $centralError = (string) $interfacesResult['error'];
            } else {
                $interfaces = array_map(
                    fn (array $item): array => $this->mapInterfaceItem($item),
                    $interfacesResult,
                );
            }
        }

        return [
            'serial' => $serial,
            'device_name' => $deviceName,
            'device_type' => $resolvedDeviceType,
            'device_function' => $deviceFunction,
            'interfaces' => $interfaces,
            'central_error' => $centralError,
        ];
    }

    /**
     * @param  list<string>  $serials
     * @return array<string, array{
     *     device_name: string,
     *     device_type: string,
     *     device_function: string,
     *     model: string
     * }>
     */
    private function fetchDeviceMetaBySerials(
        CentralAPIHelper $helper,
        DeviceCentralFilterBuilder $filterBuilder,
        array $serials,
    ): array {
        $metaBySerial = [];
        $filters = $filterBuilder->buildInChunks('serialNumber', $serials);

        foreach ($filters as $filter) {
            $deviceResult = $helper->get_all_devices([
                'filter' => $filter,
                'limit' => max(count($serials), 1),
            ]);

            if (! is_array($deviceResult) || array_key_exists('error', $deviceResult)) {
                continue;
            }

            foreach ($deviceResult as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $serial = trim((string) ($item['serialNumber'] ?? ''));
                if ($serial === '') {
                    continue;
                }

                $metaBySerial[$serial] = [
                    'device_name' => (string) ($item['deviceName'] ?? ''),
                    'device_type' => (string) ($item['deviceType'] ?? ''),
                    'device_function' => (string) ($item['deviceFunction'] ?? $item['persona'] ?? ''),
                    'model' => (string) ($item['model'] ?? ''),
                ];
            }
        }

        // Fall back to per-serial lookups for any serials missed by chunked `in` filters.
        foreach ($serials as $serial) {
            if (isset($metaBySerial[$serial])) {
                continue;
            }

            $filter = $filterBuilder->build(['serialNumber' => $serial]);
            if ($filter === null) {
                continue;
            }

            $deviceResult = $helper->get_all_devices([
                'filter' => $filter,
                'limit' => 1,
            ]);

            if (! is_array($deviceResult) || array_key_exists('error', $deviceResult) || $deviceResult === []) {
                continue;
            }

            $item = $deviceResult[0] ?? null;
            if (! is_array($item)) {
                continue;
            }

            $metaBySerial[$serial] = [
                'device_name' => (string) ($item['deviceName'] ?? ''),
                'device_type' => (string) ($item['deviceType'] ?? ''),
                'device_function' => (string) ($item['deviceFunction'] ?? $item['persona'] ?? ''),
                'model' => (string) ($item['model'] ?? ''),
            ];
        }

        return $metaBySerial;
    }

    /**
     * @param  array<string, mixed>  $rawNames
     * @param  list<string>  $serials
     * @return array<string, string>
     */
    private function normalizeNamesBySerial(array $rawNames, array $serials): array
    {
        $allowed = array_fill_keys($serials, true);
        $names = [];

        foreach ($rawNames as $serial => $name) {
            $trimmedSerial = trim((string) $serial);
            $trimmedName = trim((string) $name);

            if ($trimmedSerial === '' || $trimmedName === '' || ! isset($allowed[$trimmedSerial])) {
                continue;
            }

            $names[$trimmedSerial] = $trimmedName;
        }

        return $names;
    }

    private function isAccessPoint(string $deviceType, string $deviceFunction, string $model = ''): bool
    {
        $normalizedType = strtoupper(trim($deviceType));
        if (in_array($normalizedType, ['ACCESS_POINT', 'AP', 'IAP'], true)) {
            return true;
        }

        $normalizedFunction = strtoupper(trim($deviceFunction));
        if ($normalizedFunction !== '') {
            if (str_contains($normalizedFunction, 'AP')) {
                return true;
            }

            if (in_array($normalizedFunction, ['CAMPUS', 'MICROBRANCH'], true)) {
                return true;
            }
        }

        $normalizedModel = strtoupper(trim($model));
        if (str_starts_with($normalizedModel, 'AP-') || str_starts_with($normalizedModel, 'IAP-')) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{
     *     bssid: string,
     *     wlanName: string,
     *     radioNumber: int|null,
     *     radioMacAddress: string,
     *     macAddress: string,
     *     clientCount: int|null,
     *     siteName: string,
     *     siteId: string,
     *     clusterId: string,
     *     deviceName: string,
     *     serialNumber: string
     * }
     */
    private function mapBssidItem(array $item): array
    {
        $radioNumber = $item['radioNumber'] ?? null;
        $clientCount = $item['clientCount'] ?? $item['clientcount'] ?? null;

        return [
            'bssid' => (string) ($item['bssid'] ?? ''),
            'wlanName' => (string) ($item['wlanName'] ?? ''),
            'radioNumber' => is_numeric($radioNumber) ? (int) $radioNumber : null,
            'radioMacAddress' => (string) ($item['radioMacAddress'] ?? ''),
            'macAddress' => (string) ($item['macAddress'] ?? ''),
            'clientCount' => is_numeric($clientCount) ? (int) $clientCount : null,
            'siteName' => (string) ($item['siteName'] ?? ''),
            'siteId' => (string) ($item['siteId'] ?? ''),
            'clusterId' => (string) ($item['clusterId'] ?? ''),
            'deviceName' => (string) ($item['deviceName'] ?? ''),
            'serialNumber' => (string) ($item['serialNumber'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{
     *     name: string,
     *     status: string,
     *     operStatus: string,
     *     neighbour: string,
     *     neighbourSerial: string,
     *     vlanMode: string,
     *     allowedVlanIds: list<int>,
     *     nativeVlan: string,
     *     poeClass: string,
     *     neighbourFamily: string,
     *     neighbourFunction: string,
     *     neighbourType: string,
     *     transceiverType: string
     * }
     */
    private function mapInterfaceItem(array $item): array
    {
        $allowedVlanIds = $item['allowedVlanIds'] ?? [];
        if (! is_array($allowedVlanIds)) {
            $allowedVlanIds = [];
        }

        $normalizedVlanIds = [];
        foreach ($allowedVlanIds as $vlanId) {
            if (is_numeric($vlanId)) {
                $normalizedVlanIds[] = (int) $vlanId;
            }
        }

        $nativeVlan = $item['nativeVlan'] ?? '';
        if ($nativeVlan === null) {
            $nativeVlan = '';
        }

        return [
            'name' => (string) ($item['name'] ?? ''),
            'status' => (string) ($item['status'] ?? ''),
            'operStatus' => (string) ($item['operStatus'] ?? ''),
            'neighbour' => (string) ($item['neighbour'] ?? ''),
            'neighbourSerial' => (string) ($item['neighbourSerial'] ?? ''),
            'vlanMode' => (string) ($item['vlanMode'] ?? ''),
            'allowedVlanIds' => $normalizedVlanIds,
            'nativeVlan' => (string) $nativeVlan,
            'poeClass' => (string) ($item['poeClass'] ?? ''),
            'neighbourFamily' => (string) ($item['neighbourFamily'] ?? ''),
            'neighbourFunction' => (string) ($item['neighbourFunction'] ?? ''),
            'neighbourType' => (string) ($item['neighbourType'] ?? ''),
            'transceiverType' => (string) ($item['transceiverType'] ?? ''),
        ];
    }

    /**
     * @param  list<string>  $rawSerials
     * @return list<string>
     */
    private function normalizeSerials(array $rawSerials): array
    {
        $serials = [];

        foreach ($rawSerials as $serial) {
            $trimmed = trim((string) $serial);
            if ($trimmed === '' || in_array($trimmed, $serials, true)) {
                continue;
            }

            $serials[] = $trimmed;
        }

        return $serials;
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function hasActiveFilters(array $filters): bool
    {
        foreach ($filters as $value) {
            if ($value !== '') {
                return true;
            }
        }

        return false;
    }

    private function startCxPortBounce(Request $request, string $type): JsonResponse
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            return response()->json([
                'error' => 'Please set current client to run port bounce operations.',
            ], 422);
        }

        $validated = $request->validate([
            'serial' => ['required', 'string', 'max:64'],
            'ports' => ['required', 'array', 'min:1'],
            'ports.*' => ['required', 'string', 'max:64'],
        ]);

        $helper = new CentralAPIHelper($currentClient);
        $result = $type === 'poe'
            ? $helper->run_cx_poe_bounce(trim($validated['serial']), $validated['ports'])
            : $helper->run_cx_port_bounce(trim($validated['serial']), $validated['ports']);

        if (! $result['ok']) {
            $status = $result['status'] ?? 422;
            if (! is_int($status) || $status < 400 || $status > 599) {
                $status = 422;
            }

            return response()->json([
                'error' => $result['error'],
            ], $status);
        }

        return response()->json([
            'task_id' => $result['task_id'],
            'status' => $result['body']['status'] ?? 'INITIATED',
            'start_time' => $result['body']['startTime'] ?? null,
        ], 202);
    }

    private function getCxPortBounceResult(Request $request, string $serial, string $taskId, string $type): JsonResponse
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            return response()->json([
                'error' => 'Please set current client to run port bounce operations.',
            ], 422);
        }

        $validated = validator(
            ['serial' => $serial, 'taskId' => $taskId],
            [
                'serial' => ['required', 'string', 'max:64'],
                'taskId' => ['required', 'uuid'],
            ],
            [],
            ['taskId' => 'task id'],
        )->validate();

        $helper = new CentralAPIHelper($currentClient);
        $result = $type === 'poe'
            ? $helper->get_cx_poe_bounce_result(trim($validated['serial']), trim($validated['taskId']))
            : $helper->get_cx_port_bounce_result(trim($validated['serial']), trim($validated['taskId']));

        if (! $result['ok']) {
            $status = $result['status'] ?? 422;
            if (! is_int($status) || $status < 400 || $status > 599) {
                $status = 422;
            }

            return response()->json([
                'error' => $result['error'],
            ], $status);
        }

        return response()->json($result['body']);
    }
}
