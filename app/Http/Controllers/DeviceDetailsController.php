<?php

namespace App\Http\Controllers;

use App\Helper\CentralAPIHelper;
use App\Jobs\RebootAccessPointJob;
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
        ]);

        $serials = $this->normalizeSerials($validated['serials']);

        if ($serials === []) {
            return back()->withErrors(['serials' => 'At least one serial number is required.']);
        }

        $helper = new CentralAPIHelper($currentClient);
        $devices = [];

        foreach ($serials as $serial) {
            $devices[] = $this->buildDevicePayload($helper, $filterBuilder, $serial);
        }

        return Inertia::render('DeviceDetails/Show', [
            'devices' => $devices,
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
        ]);

        $helper = new CentralAPIHelper($currentClient);
        $result = $helper->run_cx_show_commands(trim($validated['serial']), $validated['commands']);

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
            ['serial' => $serial, 'taskId' => $taskId],
            [
                'serial' => ['required', 'string', 'max:64'],
                'taskId' => ['required', 'uuid'],
            ],
            [],
            ['taskId' => 'task id'],
        )->validate();

        $helper = new CentralAPIHelper($currentClient);
        $result = $helper->get_cx_show_commands_result(
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

    /**
     * @return array{
     *     serial: string,
     *     device_name: string,
     *     device_type: string,
     *     device_function: string,
     *     interfaces: list<array<string, mixed>>,
     *     central_error: string|null
     * }
     */
    private function buildDevicePayload(CentralAPIHelper $helper, DeviceCentralFilterBuilder $filterBuilder, string $serial): array
    {
        $deviceName = '';
        $deviceType = '';
        $deviceFunction = '';
        $model = '';
        $centralError = null;
        $filter = $filterBuilder->build(['serialNumber' => $serial]);

        if ($filter !== null) {
            $deviceResult = $helper->get_all_devices([
                'filter' => $filter,
                'limit' => 1,
            ]);

            if (is_array($deviceResult) && array_key_exists('error', $deviceResult)) {
                $centralError = (string) $deviceResult['error'];
            } elseif (is_array($deviceResult) && $deviceResult !== []) {
                $deviceName = (string) ($deviceResult[0]['deviceName'] ?? '');
                $deviceType = (string) ($deviceResult[0]['deviceType'] ?? '');
                $deviceFunction = (string) ($deviceResult[0]['deviceFunction'] ?? $deviceResult[0]['persona'] ?? '');
                $model = (string) ($deviceResult[0]['model'] ?? '');
            }
        }

        $isAccessPoint = $this->isAccessPoint($deviceType, $deviceFunction, $model);
        $resolvedDeviceType = $isAccessPoint
            ? 'ACCESS_POINT'
            : ($deviceType !== '' ? $deviceType : 'SWITCH');

        $interfaces = [];

        if ($centralError === null && ! $isAccessPoint) {
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
}
