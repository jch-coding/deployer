<?php

namespace App\Http\Controllers;

use App\Helper\CentralAPIHelper;
use App\Helper\GreenLakeAPIHelper;
use App\LicenseType;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\Task;
use App\Services\DeploymentCriticalCheckService;
use App\Services\DeviceInterfacePayloadSync;
use App\Services\FinalizeExpiredTasksService;
use App\Services\LicensingInventoryService;
use App\Services\RelaunchFailedCriticalConfigService;
use App\TaskType;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class DeploymentController extends Controller
{
    public function index(Request $request)
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            session()->flash('error', 'Please set current client to view deployments');

            return to_route('clients.index');
        }

        $deployments = $currentClient->deployments()->withCount('devices')->get();

        return Inertia::render('Deployment/Index', [
            'deployments' => $deployments,
        ]);
    }

    public function show(
        Request $request,
        Deployment $deployment,
        FinalizeExpiredTasksService $finalizeExpiredTasks,
        LicensingInventoryService $licensingInventoryService,
    ) {
        $deployment->load('devices');

        $finalizeExpiredTasks->run((int) $deployment->id);

        $latest_tasks = $deployment->tasks()->withCount('devices')->latest()->take(6)->get()
            ->map(function ($task) {
                if ($task->status !== 'COMPLETED') {
                    $task_completed = $task->processTaskStatus();
                    if ($task_completed) {
                        $task->status = 'COMPLETED';
                        $task->save();
                    }
                }

                return $task;
            })
            ->map(function ($task) {
                $task->human_created_at = Carbon::parse($task->created_at)->diffForHumans();
                $task->human_updated_at = Carbon::parse($task->updated_at)->diffForHumans();
                $task->friendly_name = Task::getTaskFriendlyName($task->task_type);

                return $task;
            });

        $latest_of_tasks = collect(TaskType::cases())->map(fn ($task) => $deployment->tasks()->where('task_type', $task->name)->latest()->first())
            ->filter(fn ($task) => $task !== null);

        $items = $latest_of_tasks->map(fn ($task) => $task->devices->map(fn ($device) => $device->interfaces)->collapse());
        $items_obj = collect(array_map(fn ($task, $item) => [$task['task_type'] => $item], $latest_of_tasks->toArray(), $items->toArray()))->collapse();
        $items_with_names = $items_obj->map(fn ($group) => collect($group)->map(
            function ($member) {
                $attributes = is_array($member) ? $member : $member->toArray();

                return [
                    ...$attributes,
                    'name' => $attributes['interface'] ?? '',
                ];
            }
        )->values()->all());

        $rawSearch = $request->query('search');
        $search = is_string($rawSearch) ? mb_substr(trim($rawSearch), 0, 255) : '';

        $allowedPerPage = [10, 25, 50, 100];
        $rawPerPage = $request->query('per_page');
        $perPage = is_numeric($rawPerPage) ? (int) $rawPerPage : 25;
        if (! in_array($perPage, $allowedPerPage, true)) {
            $perPage = 25;
        }

        $devicesQuery = $deployment->devices()->with('site');
        if ($search !== '') {
            $pattern = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';
            $devicesQuery->where(function ($query) use ($pattern) {
                $query->whereRaw('lower(name) LIKE ?', [$pattern])
                    ->orWhereRaw('lower(serial) LIKE ?', [$pattern])
                    ->orWhereRaw('lower(device_function) LIKE ?', [$pattern]);
            });
        }

        $licensingOptions = [
            'enabled_services' => [],
            'available_subscriptions' => [],
            'license_tags' => [],
            'central_licensing_error' => null,
        ];
        $currentClient = $request->user()->currentClient();
        $licensingSyncedAt = null;
        $cxFirmwareVersions = [];
        $centralFirmwareError = null;
        $centralSites = [];
        $centralDeviceGroups = [];
        $deviceGroupOptions = [];
        $centralSitesError = null;
        $centralDeviceGroupsError = null;
        $classicDeviceGroupsError = null;
        if ($currentClient && (int) $deployment->client_id === (int) $currentClient->id) {
            try {
                $centralHelper = new CentralAPIHelper($currentClient);
                $greenLakeHelper = new GreenLakeAPIHelper($currentClient);
                $licensingPayload = $licensingInventoryService->resolveLicensingOptions(
                    $currentClient,
                    $centralHelper,
                    $greenLakeHelper,
                );
                $licensingOptions = [
                    'enabled_services' => $licensingPayload['enabled_services'],
                    'available_subscriptions' => $licensingPayload['available_subscriptions'],
                    'license_tags' => $licensingPayload['license_tags'],
                    'central_licensing_error' => $licensingPayload['central_error'],
                ];
                $licensingSyncedAt = $licensingPayload['licensing_synced_at'];

                $firmwarePayload = $centralHelper->resolveCxFirmwareVersionOptions();
                $cxFirmwareVersions = $firmwarePayload['versions'];
                $centralFirmwareError = $firmwarePayload['error'];

                $sitesResult = $centralHelper->collectScopeManagementSites();
                $groupsResult = $centralHelper->collectScopeManagementDeviceGroups();
                $centralSites = $sitesResult['sites'];
                $centralSitesError = $sitesResult['error'];
                $centralDeviceGroups = $groupsResult['groups'];
                $centralDeviceGroupsError = $groupsResult['error'];

                $centralGroupNames = collect($centralDeviceGroups)
                    ->pluck('scopeName')
                    ->flip();
                $deviceGroupOptions = collect($centralDeviceGroups)
                    ->map(fn (array $group) => [
                        'scopeName' => $group['scopeName'],
                        'scopeId' => $group['scopeId'],
                        'isClassic' => false,
                    ])
                    ->values()
                    ->all();

                $classicGroupsResult = $centralHelper->classic_collect_all_group_names();
                if (array_key_exists('error', $classicGroupsResult)) {
                    $classicDeviceGroupsError = $classicGroupsResult['error'];
                } else {
                    $classicOnlyNames = collect($classicGroupsResult['names'] ?? [])
                        ->filter(fn (string $name) => ! $centralGroupNames->has($name))
                        ->sort()
                        ->values();

                    foreach ($classicOnlyNames as $name) {
                        $deviceGroupOptions[] = [
                            'scopeName' => $name,
                            'scopeId' => '',
                            'isClassic' => true,
                        ];
                    }
                }
            } catch (\Throwable $exception) {
                Log::warning('Failed to load Central options for deployment show page.', [
                    'deployment_id' => $deployment->id,
                    'client_id' => $currentClient->id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return Inertia::render('Deployment/Show', [
            'deployment' => $deployment,
            'devices' => $devicesQuery
                ->paginate($perPage)
                ->withQueryString()
                ->through(fn (Device $device) => [
                    'id' => $device->id,
                    'name' => $device->name,
                    'serial' => $device->serial,
                    'device_function' => $device->device_function,
                    'site' => $device->site?->name,
                    'group' => $device->group,
                ]),
            'device_search' => $search,
            'enabled_services' => $licensingOptions['enabled_services'],
            'available_subscriptions' => $licensingOptions['available_subscriptions'],
            'license_tags' => $licensingOptions['license_tags'],
            'license_type_options' => LicenseType::values(),
            'central_licensing_error' => $licensingOptions['central_licensing_error'],
            'licensing_synced_at' => $licensingSyncedAt,
            'cx_firmware_versions' => $cxFirmwareVersions,
            'central_firmware_error' => $centralFirmwareError,
            'central_sites' => $centralSites,
            'central_sites_error' => $centralSitesError,
            'central_device_groups' => $centralDeviceGroups,
            'central_device_groups_error' => $centralDeviceGroupsError,
            'device_group_options' => $deviceGroupOptions,
            'classic_device_groups_error' => $classicDeviceGroupsError,
            'tasks' => array_map(fn ($task) => [
                'task_type' => $task->name,
                'friendly_name' => Task::getTaskFriendlyName($task->name),
                'friendly_description' => Task::getTaskFriendlyDescription($task->name),
                'required_columns' => Task::getTaskRequiredColumns($task->name),
            ], TaskType::cases()),
            'latest_tasks' => $latest_tasks,
            'items' => $items_with_names,
        ]);
    }

    public function store(Request $request)
    {
        $currentClient = $request->user()->currentClient();
        if (! $currentClient) {
            return redirect()->route('clients.index')->with('error', 'Please set current client before creating deployments');
        }

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'min:3',
                'max:255',
                Rule::unique('deployments', 'name')->where(
                    fn ($query) => $query->where('client_id', $currentClient->id)
                ),
            ],
            'description' => 'nullable|string|max:255',
        ]);

        Deployment::create([
            ...$data,
            'client_id' => $currentClient->id,
        ]);

        return redirect()->route('deployments.index');
    }

    public function destroy(Request $request, Deployment $deployment)
    {
        if ($request->user()->cannot('delete', $deployment)) {
            abort(403);
        }
        $deployment->delete();

        return redirect()->route('deployments.index');
    }

    public function criticalCheck(Request $request, Deployment $deployment, DeploymentCriticalCheckService $criticalCheckService)
    {
        if ($response = $this->criticalCheckClientGuard($request, $deployment)) {
            return $response;
        }

        return Inertia::render('Deployment/CriticalCheck', [
            'deployment' => $deployment->only(['id', 'name']),
            'device_count' => $deployment->devices()->count(),
            'total_steps' => $criticalCheckService->totalSteps($deployment),
            ...$criticalCheckService->emptyResults(),
        ]);
    }

    public function criticalCheckStep(
        Request $request,
        Deployment $deployment,
        int $step,
        DeploymentCriticalCheckService $criticalCheckService,
    ) {
        if ($response = $this->criticalCheckClientGuard($request, $deployment, json: true)) {
            return $response;
        }

        $validated = $request->validate([
            'dns_scope_id' => ['nullable', 'string'],
            'dns_scope_error' => ['nullable', 'string'],
            'include_ethernet' => ['sometimes', 'boolean'],
        ]);

        $includeEthernet = $request->boolean('include_ethernet');

        $context = [
            'include_ethernet' => $includeEthernet,
        ];
        if (array_key_exists('dns_scope_id', $validated) && $validated['dns_scope_id'] !== null) {
            $context['dns_scope_id'] = $validated['dns_scope_id'];
        }
        if (array_key_exists('dns_scope_error', $validated) && $validated['dns_scope_error'] !== null) {
            $context['dns_scope_error'] = $validated['dns_scope_error'];
        }

        $helper = new CentralAPIHelper($deployment->client);
        $total = $criticalCheckService->totalSteps($deployment, $includeEthernet);

        if ($step < 0 || $step >= $total) {
            abort(404);
        }

        return response()->json(
            $criticalCheckService->runStep($deployment, $helper, $step, $context)
        );
    }

    protected function criticalCheckClientGuard(Request $request, Deployment $deployment, bool $json = false)
    {
        $deployment->loadMissing('client');

        $currentClient = $request->user()?->currentClient();
        if (! $currentClient || (int) $deployment->client_id !== (int) $currentClient->id) {
            $message = 'Please set current client to match this deployment before running critical configuration check.';

            if ($json) {
                return response()->json(['message' => $message], 403);
            }

            session()->flash('error', $message);

            return redirect()->route('deployments.index');
        }

        return null;
    }

    public function patchCriticalCheckFailedInterfaces(
        Request $request,
        Deployment $deployment,
        DeviceInterfacePayloadSync $payloadSync,
    ) {
        if ($response = $this->criticalCheckClientGuard($request, $deployment, json: true)) {
            return $response;
        }

        $validated = $request->validate([
            'updates' => ['required', 'array', 'min:1'],
            'updates.*.device_interface_id' => ['required', 'integer', 'distinct'],
            'updates.*.kind' => ['required', Rule::in(['lag', 'vlan', 'ethernet'])],
            'updates.*.attributes' => ['required', 'array'],
        ]);

        $ids = collect($validated['updates'])->pluck('device_interface_id')->map(fn ($id) => (int) $id)->all();
        $interfaces = DeviceInterface::query()
            ->whereIn('id', $ids)
            ->whereHas('device', fn ($q) => $q->where('deployment_id', $deployment->id))
            ->with(['switch_port', 'lacp_profile', 'stp_profile'])
            ->get()
            ->keyBy('id');

        if ($interfaces->count() !== count($ids)) {
            return response()->json(['message' => 'One or more interfaces do not belong to this deployment.'], 422);
        }

        DB::transaction(function () use ($validated, $interfaces, $payloadSync): void {
            foreach ($validated['updates'] as $row) {
                $interface = $interfaces->get((int) $row['device_interface_id']);
                if ($interface === null) {
                    continue;
                }
                $payloadSync->apply($interface, (string) $row['kind'], $row['attributes']);
            }
        });

        return response()->json(['ok' => true]);
    }

    public function relaunchFailedCriticalConfig(
        Request $request,
        Deployment $deployment,
        RelaunchFailedCriticalConfigService $relaunchService,
    ) {
        if ($response = $this->criticalCheckClientGuard($request, $deployment)) {
            return $response;
        }

        $validated = $request->validate([
            'deployment_time' => ['required', 'integer', 'min:0'],
            'wait_time' => ['required', 'integer', 'min:0'],
            'include_ethernet' => ['sometimes', 'boolean'],
            'failed_interface_ids' => ['required', 'array'],
            'failed_interface_ids.lag' => ['sometimes', 'array'],
            'failed_interface_ids.lag.*' => ['integer'],
            'failed_interface_ids.vlan' => ['sometimes', 'array'],
            'failed_interface_ids.vlan.*' => ['integer'],
            'failed_interface_ids.ethernet' => ['sometimes', 'array'],
            'failed_interface_ids.ethernet.*' => ['integer'],
            'profile_device_ids' => ['required', 'array'],
            'profile_device_ids.static_route' => ['sometimes', 'array'],
            'profile_device_ids.static_route.*' => ['integer'],
            'profile_device_ids.dns' => ['sometimes', 'array'],
            'profile_device_ids.dns.*' => ['integer'],
            'profile_device_ids.local_management' => ['sometimes', 'array'],
            'profile_device_ids.local_management.*' => ['integer'],
        ]);

        try {
            $firstTask = $relaunchService->create($deployment, [
                'deployment_time' => (int) $validated['deployment_time'],
                'wait_time' => (int) $validated['wait_time'],
                'include_ethernet' => $request->boolean('include_ethernet'),
                'failed_interface_ids' => $validated['failed_interface_ids'],
                'profile_device_ids' => $validated['profile_device_ids'],
            ], $request);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['relaunch' => $e->getMessage()]);
        }

        session()->flash('success', 'Started relaunch task for failed critical configuration items.');

        return to_route('tasks.show', $firstTask);
    }
}
