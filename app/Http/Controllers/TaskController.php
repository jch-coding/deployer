<?php

namespace App\Http\Controllers;

use App\Helper\CentralAPIHelper;
use App\InterfaceKind;
use App\JobQueueShard;
use App\Jobs\AssignDeviceFunctionJob;
use App\Jobs\AssociateDeviceToSiteJob;
use App\Jobs\AssociateSiteAndNameJob;
use App\Jobs\ConfigureEthernetInterface;
use App\Jobs\ConfigureLagInterfaceJob;
use App\Jobs\ConfigureVlanInterfaceJob;
use App\Jobs\CreateVSFProfileJob;
use App\Jobs\MoveDevicesToGroupJob;
use App\Jobs\PreprovisionDevicesToGroupJob;
use App\Jobs\RemoveLocalOverrideDNSJob;
use App\Jobs\RemoveLocalOverrideNTPJob;
use App\Jobs\RemoveLocalOverrideStaticRouteJob;
use App\Jobs\RemoveLocalOverrideVlansJob;
use App\Jobs\UpdateSystemInfo;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\Task;
use App\TaskType;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class TaskController extends Controller
{
    public $display_columns = [
        'UPDATE_SYSTEM_INFO' => [
            'device_function',
        ],
        'CONFIGURE_ETHERNET_INTERFACE' => [
            'sw_profile',
        ],
        'CONFIGURE_VLAN_INTERFACE' => [
            'ip_address',
        ],
        'CONFIGURE_LAG_INTERFACE' => [
        ],
        'PREPROVISION_DEVICE_TO_GROUP' => [
            'group',
        ],
        'ASSIGN_DEVICE_FUNCTION' => [
            'device_function',
        ],
        'ASSOCIATE_SITE_AND_NAME' => [
            'site',
        ],
        'CREATE_VSF_PROFILE' => [
            'sku',
        ],
        'MOVE_DEVICE_TO_GROUP' => [
            'group',
        ],
        'REMOVE_LOCAL_OVERRIDE_VLANS' => [],
        'REMOVE_LOCAL_OVERRIDE_DNS_PROFILE' => [],
        'REMOVE_LOCAL_OVERRIDE_NTP_PROFILE' => [],
        'REMOVE_LOCAL_OVERRIDE_STATIC_ROUTE' => [],
    ];

    public function index(Request $request)
    {
        $currentClient = $request->user()?->currentClient();
        $taskName = trim((string) $request->query('task_name', ''));
        $deploymentName = trim((string) $request->query('deployment_name', ''));
        $status = trim((string) $request->query('status', ''));

        $tasksQuery = Task::query()
            ->with(['deployment.client'])
            ->withCount(['devices', 'deviceInterfaces'])
            ->when(
                $currentClient,
                fn ($query) => $query->whereHas('deployment', fn ($deploymentQuery) => $deploymentQuery->where('client_id', $currentClient->id)),
                fn ($query) => $query->whereRaw('1 = 0')
            );

        if ($status !== '') {
            $tasksQuery->where('status', $status);
        }

        if ($deploymentName !== '') {
            $tasksQuery->whereHas('deployment', fn ($deploymentQuery) => $deploymentQuery
                ->whereRaw('lower(name) LIKE ?', ['%'.mb_strtolower($deploymentName).'%']));
        }

        if ($taskName !== '') {
            $availableTypes = Task::query()
                ->when(
                    $currentClient,
                    fn ($query) => $query->whereHas('deployment', fn ($deploymentQuery) => $deploymentQuery->where('client_id', $currentClient->id)),
                    fn ($query) => $query->whereRaw('1 = 0')
                )
                ->select('task_type')
                ->distinct()
                ->pluck('task_type')
                ->all();

            $matchingTypes = array_values(array_filter(
                $availableTypes,
                fn (string $taskType) => str_contains(mb_strtolower(Task::getTaskFriendlyName($taskType)), mb_strtolower($taskName))
            ));

            if ($matchingTypes === []) {
                $tasksQuery->whereRaw('1 = 0');
            } else {
                $tasksQuery->whereIn('task_type', $matchingTypes);
            }
        }

        $statusOptions = Task::query()
            ->when(
                $currentClient,
                fn ($query) => $query->whereHas('deployment', fn ($deploymentQuery) => $deploymentQuery->where('client_id', $currentClient->id)),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->select('status')
            ->distinct()
            ->orderBy('status')
            ->pluck('status')
            ->all();

        $tasks = $tasksQuery
            ->latest()
            ->paginate(15)
            ->withQueryString()
            ->through(function (Task $task) {
                $category = $task->getTaskCategory($task->task_type);

                return [
                    'id' => $task->id,
                    'task_name' => Task::getTaskFriendlyName($task->task_type),
                    'deployment_name' => $task->deployment?->name,
                    'client_name' => $task->deployment?->client?->name,
                    'status' => $task->status,
                    'item_count' => $category === 'INTERFACE' ? $task->device_interfaces_count : $task->devices_count,
                    'human_updated_at' => Carbon::parse($task->updated_at)->diffForHumans(),
                ];
            });

        return Inertia::render('Task/Index', [
            'tasks' => $tasks,
            'status_options' => $statusOptions,
            'filters' => [
                'task_name' => $taskName,
                'deployment_name' => $deploymentName,
                'status' => $status,
            ],
        ]);
    }

    public function show(Task $task)
    {
        $task->loadMissing('deployment');

        if ($task->composite_group_id !== null && $task->composite_kind !== null) {
            $siblings = Task::query()
                ->where('deployment_id', $task->deployment_id)
                ->where('composite_group_id', $task->composite_group_id)
                ->orderBy('composite_order')
                ->with([
                    'devices' => fn ($q) => $q->withPivot('status')->with('interfaces'),
                    'deviceInterfaces' => fn ($q) => $q->withPivot('status'),
                ])
                ->get();

            return Inertia::render('Task/MultiJobTask', [
                'task' => $task,
                'deployment' => $task->deployment,
                'logical_friendly_name' => Task::getTaskFriendlyName($task->composite_kind),
                'logical_description' => Task::getTaskFriendlyDescription($task->composite_kind),
                'sub_jobs' => $this->buildSubJobsForCompositePage($siblings),
            ]);
        }

        $isDeviceBasedTask = $task->getTaskCategory($task->task_type) === 'DEVICE';
        $inertia_component = $isDeviceBasedTask ? 'Task/DeviceTask' : 'Task/InterfaceTask';

        return Inertia::render($inertia_component, [
            'task' => $task,
            'task_friendly_name' => Task::getTaskFriendlyName($task->task_type),
            'task_friendly_description' => Task::getTaskFriendlyDescription($task->task_type),
            'devices' => $task->devices,
            'interfaces' => $isDeviceBasedTask ? [] : $task->deviceInterfaces()->withPivot('status')->get(),
            'deployment' => $task->deployment,
            'display_columns' => $this->display_columns[$task->task_type] ?? [],
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Task>  $siblings
     * @return array<int, array<string, mixed>>
     */
    protected function buildSubJobsForCompositePage(Collection $siblings): array
    {
        return $siblings->map(function (Task $sub) {
            $isDeviceBased = $sub->getTaskCategory($sub->task_type) === 'DEVICE';
            if ($isDeviceBased) {
                $devices = $sub->devices;
                $completed = $devices->filter(fn ($d) => ($d->pivot->status ?? null) === 'COMPLETED')->count();
                $total = $devices->count();

                return [
                    'id' => $sub->id,
                    'task_type' => $sub->task_type,
                    'status' => $sub->status,
                    'status_log' => $sub->status_log ?? '',
                    'friendly_label' => Task::getTaskFriendlyName($sub->task_type),
                    'completed_count' => $completed,
                    'total_count' => $total,
                    'is_device_based' => true,
                    'devices' => $devices,
                    'interfaces' => [],
                    'display_columns' => $this->display_columns[$sub->task_type] ?? [],
                ];
            }

            $interfaces = $sub->deviceInterfaces;
            $completed = $interfaces->filter(fn ($i) => ($i->pivot->status ?? null) === 'COMPLETED')->count();
            $total = $interfaces->count();

            return [
                'id' => $sub->id,
                'task_type' => $sub->task_type,
                'status' => $sub->status,
                'status_log' => $sub->status_log ?? '',
                'friendly_label' => Task::getTaskFriendlyName($sub->task_type),
                'completed_count' => $completed,
                'total_count' => $total,
                'is_device_based' => false,
                'devices' => $sub->devices,
                'interfaces' => $interfaces,
                'display_columns' => $this->display_columns[$sub->task_type] ?? [],
            ];
        })->values()->all();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Task>
     */
    protected function tasksInCompositeGroup(Task $task): Collection
    {
        if ($task->composite_group_id === null) {
            return collect([$task]);
        }

        return Task::query()
            ->where('deployment_id', $task->deployment_id)
            ->where('composite_group_id', $task->composite_group_id)
            ->orderBy('composite_order')
            ->get();
    }

    protected function allocateJobQueue(Request $request, string $entropy): string
    {
        $userId = (int) $request->user()->id;

        return JobQueueShard::fromUserEntropy($userId, $entropy);
    }

    protected function performCancelSingle(Task $task): void
    {
        $task->update(['status' => 'CANCELLED']);
        if ($task->batch_id) {
            $batch = Bus::findBatch($task->batch_id);
            if ($batch) {
                $batch->cancel();
            }
        }
    }

    public function store(Request $request, Deployment $deployment)
    {
        $validated = $request->validate([
            'task_type' => ['required', Rule::in(array_map(fn ($task) => $task->name, TaskType::cases()))],
            'devices' => 'required|array',
            'deployment_time' => 'required|integer',
        ]);

        $shardEntropy = (string) Str::uuid();

        if ($validated['task_type'] === 'REMOVE_VSF_PROFILE_LOCAL_OVERRIDES') {
            $compositeGroupId = (string) Str::uuid();
            $compositeKind = 'REMOVE_VSF_PROFILE_LOCAL_OVERRIDES';
            $jobQueue = $this->allocateJobQueue($request, $shardEntropy);
            $remove_vlans_task = $deployment->tasks()->create([
                'task_type' => 'REMOVE_LOCAL_OVERRIDE_VLANS',
                'name' => 'task_for_'.$deployment->name.now(),
                'deployment_time' => $validated['deployment_time'],
                'status' => 'IN_PROGRESS',
                'job_queue' => $jobQueue,
                'composite_group_id' => $compositeGroupId,
                'composite_kind' => $compositeKind,
                'composite_order' => 1,
            ]);
            $remove_dns_task = $deployment->tasks()->create([
                'task_type' => 'REMOVE_LOCAL_OVERRIDE_DNS_PROFILE',
                'name' => 'task_for_'.$deployment->name.now(),
                'deployment_time' => $validated['deployment_time'],
                'status' => 'IN_PROGRESS',
                'job_queue' => $jobQueue,
                'composite_group_id' => $compositeGroupId,
                'composite_kind' => $compositeKind,
                'composite_order' => 2,
            ]);
            $remove_static_route_task = $deployment->tasks()->create([
                'task_type' => 'REMOVE_LOCAL_OVERRIDE_STATIC_ROUTE',
                'name' => 'task_for_'.$deployment->name.now(),
                'deployment_time' => $validated['deployment_time'],
                'status' => 'IN_PROGRESS',
                'job_queue' => $jobQueue,
                'composite_group_id' => $compositeGroupId,
                'composite_kind' => $compositeKind,
                'composite_order' => 3,
            ]);
            $remove_ntp_task = $deployment->tasks()->create([
                'task_type' => 'REMOVE_LOCAL_OVERRIDE_NTP_PROFILE',
                'name' => 'task_for_'.$deployment->name.now(),
                'deployment_time' => $validated['deployment_time'],
                'status' => 'IN_PROGRESS',
                'job_queue' => $jobQueue,
                'composite_group_id' => $compositeGroupId,
                'composite_kind' => $compositeKind,
                'composite_order' => 4,
            ]);
            $device_collection = Collection::make($validated['devices']);
            $remove_vlans_task->devices()->attach($device_collection->pluck('id'));
            $remove_dns_task->devices()->attach($device_collection->pluck('id'));
            $remove_static_route_task->devices()->attach($device_collection->pluck('id'));
            $remove_ntp_task->devices()->attach($device_collection->pluck('id'));

            $batch = $this->dispatchJob($remove_vlans_task);
            $remove_vlans_task->update(['batch_id' => $batch]);
            $batch = $this->dispatchJob($remove_dns_task);
            $remove_dns_task->update(['batch_id' => $batch]);
            $batch = $this->dispatchJob($remove_static_route_task);
            $remove_static_route_task->update(['batch_id' => $batch]);
            $batch = $this->dispatchJob($remove_ntp_task);
            $remove_ntp_task->update(['batch_id' => $batch]);
            $task = $remove_vlans_task;
        } elseif ($validated['task_type'] === 'CONFIGURE_ALL_INTERFACE') {
            $selectedDeviceIds = Collection::make($validated['devices'])
                ->pluck('id')
                ->filter(fn ($id) => $id !== null)
                ->map(fn ($id) => (int) $id)
                ->values();

            $selectedDevices = Device::query()
                ->where('deployment_id', $deployment->id)
                ->whereIn('id', $selectedDeviceIds)
                ->with('interfaces')
                ->get();

            $configureDefinitions = collect([
                ['task_type' => 'CONFIGURE_LAG_INTERFACE', 'name_prefix' => 'configure_lag_interface_for_'],
                ['task_type' => 'CONFIGURE_ETHERNET_INTERFACE', 'name_prefix' => 'configure_ethernet_interface_for_'],
                ['task_type' => 'CONFIGURE_VLAN_INTERFACE', 'name_prefix' => 'configure_svi_interface_for_'],
            ])->filter(function (array $definition) use ($selectedDevices): bool {
                return $this->getConfigureAllInterfacesForType($selectedDevices, $definition['task_type'])->isNotEmpty();
            })->values();

            if ($configureDefinitions->isEmpty()) {
                return back()->withErrors([
                    'devices' => 'No matching interfaces found for selected devices.',
                ]);
            }

            $compositeGroupId = (string) Str::uuid();
            $compositeKind = 'CONFIGURE_ALL_INTERFACE';
            $jobQueue = $this->allocateJobQueue($request, $shardEntropy);
            $createdConfigureTasks = collect();

            foreach ($configureDefinitions as $index => $definition) {
                $createdTask = $deployment->tasks()->create([
                    'task_type' => $definition['task_type'],
                    'name' => $definition['name_prefix'].$deployment->name.now(),
                    'deployment_time' => $validated['deployment_time'],
                    'status' => 'IN_PROGRESS',
                    'job_queue' => $jobQueue,
                    'composite_group_id' => $compositeGroupId,
                    'composite_kind' => $compositeKind,
                    'composite_order' => $index + 1,
                ]);

                $createdTask->devices()->attach($selectedDevices->pluck('id'));

                $batchId = $this->dispatchJob($createdTask);
                if ($batchId !== null) {
                    $createdTask->forceFill(['batch_id' => $batchId])->save();
                }

                $createdConfigureTasks->push($createdTask);
            }

            $task = $createdConfigureTasks->first();
        } else {
            $task = $deployment->tasks()->create([
                'task_type' => $validated['task_type'],
                'name' => 'task_for_'.$deployment->name.now(),
                'deployment_time' => $validated['deployment_time'],
                'status' => 'IN_PROGRESS',
                'job_queue' => $this->allocateJobQueue($request, $shardEntropy),
            ]);

            $device_collection = Collection::make($validated['devices']);
            $task->devices()->attach($device_collection->pluck('id'));
            $batchId = $this->dispatchJob($task);
            if ($batchId !== null) {
                $task->forceFill(['batch_id' => $batchId])->save();
            }
        }

        return to_route('tasks.show', $task);
    }

    public function checkCentralGroup(Request $request, Deployment $deployment)
    {
        $request->validate([
            'task_type' => ['required', Rule::in(['PREPROVISION_DEVICE_TO_GROUP', 'MOVE_DEVICE_TO_GROUP'])],
        ]);

        $currentClient = $request->user()->currentClient();
        if (! $currentClient || (int) $deployment->client_id !== (int) $currentClient->id) {
            session()->flash('error', 'Please set current client to match this deployment before checking groups.');

            return back();
        }

        $deviceGroups = Device::query()
            ->where('deployment_id', $deployment->id)
            ->pluck('group')
            ->map(fn ($g) => is_string($g) ? trim($g) : '')
            ->filter(fn ($g) => $g !== '')
            ->unique()
            ->values()
            ->all();

        if ($deviceGroups === []) {
            session()->flash('error', 'No group names are set on devices in this deployment.');

            return back();
        }

        $helper = new CentralAPIHelper($deployment->client);
        $result = $helper->classic_collect_all_group_names();
        if (isset($result['error'])) {
            session()->flash('error', 'Could not load groups from Central.');

            return back();
        }

        $centralSet = array_flip($result['names']);
        $missing = array_values(array_filter(
            $deviceGroups,
            fn (string $g): bool => ! array_key_exists($g, $centralSet)
        ));

        if ($missing === []) {
            session()->flash('success', 'All group names exist in Central.');
        } else {
            session()->flash('error', 'These groups were not found in Central: '.implode(', ', $missing).'.');
        }

        return back();
    }

    public function destroy(Task $task)
    {
        $task->devices()->detach();
        $task->delete();

        return back();
    }

    public function force_restart(Task $task)
    {
        $group = $this->tasksInCompositeGroup($task);
        foreach ($group as $t) {
            $this->performCancelSingle($t);
        }
        foreach ($group as $t) {
            $t->resetIncompletePivotRowsToPending();
            $t->update(['status' => 'IN_PROGRESS']);
            $batchId = $this->dispatchJob($t);
            if ($batchId !== null) {
                $t->forceFill(['batch_id' => $batchId])->save();
            }
        }

        return to_route('tasks.show', $group->first());
    }

    public function relaunch(Task $task)
    {
        if (! in_array($task->status, ['FAILED', 'TIMED_OUT', 'CANCELLED'], true)) {
            session()->flash('error', 'Only failed, timed out, or cancelled tasks can be relaunched.');

            return back();
        }

        $task->resetIncompletePivotRowsToPending();
        $task->update(['status' => 'IN_PROGRESS']);
        $batchId = $this->dispatchJob($task);

        if ($batchId !== null) {
            $task->forceFill(['batch_id' => $batchId])->save();
        }

        return to_route('tasks.show', $task);
    }

    public function cancel(Task $task)
    {
        foreach ($this->tasksInCompositeGroup($task) as $t) {
            $this->performCancelSingle($t);
        }

        session()->flash('success', 'Task cancelled successfully.');

        return back();
    }

    public function clearQueue(Task $task)
    {
        $maxAttempts = 5;
        $lastOutput = '';

        $connection = config('queue.default');
        $queueName = JobQueueShard::resolve($task->job_queue);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $exitCode = Artisan::call('queue:clear', [
                $connection,
                '--queue' => $queueName,
            ]);
            $rawOutput = trim(Artisan::output());
            $normalizedOutput = strtolower($rawOutput);
            $lastOutput = $rawOutput;

            if ($exitCode !== 0) {
                usleep(200000);

                continue;
            }

            if (
                str_contains($normalizedOutput, 'no messages were deleted')
                || str_contains($normalizedOutput, 'queue is empty')
            ) {
                session()->flash('success', 'Queue is clear. No pending jobs remain.');

                return to_route('tasks.show', $task);
            }

            if (
                preg_match('/cleared\s+\[?(\d+)\]?\s+jobs?/', $normalizedOutput, $matches) === 1
                && (int) $matches[1] === 0
            ) {
                session()->flash('success', 'Queue cleared successfully.');

                return to_route('tasks.show', $task);
            }

            usleep(200000);
        }

        session()->flash('error', 'Unable to confirm queue is clear after 5 attempts.'.($lastOutput !== '' ? ' Last output: '.$lastOutput : ''));

        return to_route('tasks.show', $task);
    }

    public function chunk_devices(Collection $devices_by_group)
    {
        $keys = $devices_by_group->keys()->toArray();
        $chunked_devices_by_group = $devices_by_group->map(fn ($devices) => $devices->chunk(25));

        return ['keys' => $keys, 'chunked_devices_by_group' => $chunked_devices_by_group->toArray()];
    }

    public function create_jobs_by_grouped_chunks(array $chunked_devices_by_group_with_keys, Task $task, CentralAPIHelper $centralAPIHelper, $job_class)
    {
        $devices_by_group_jobs = array_map(fn ($chunk, $group) => array_map(fn ($devices) => new $job_class($devices, $group, $task, $centralAPIHelper),
            $chunk
        ),
            $chunked_devices_by_group_with_keys['chunked_devices_by_group'], $chunked_devices_by_group_with_keys['keys']
        );

        return array_merge(...$devices_by_group_jobs);
    }

    public function attach_interfaces(Task $task, $interfaces)
    {
        $nonattached_interfaces = $interfaces->filter(fn ($interface) => ! $task->deviceInterfaces()->find($interface->id));
        $task->deviceInterfaces()->attach($nonattached_interfaces->pluck('id'));

        return $task->refresh();
    }

    protected function getConfigureAllInterfacesForType(Collection $devices, string $taskType): Collection
    {
        return match ($taskType) {
            'CONFIGURE_ETHERNET_INTERFACE' => $devices->map(
                fn ($device) => $device->interfaces->filter(fn ($interface) => $interface->interface_kind === InterfaceKind::ETHERNET)
            )->collapse(),
            'CONFIGURE_VLAN_INTERFACE' => $devices->map(
                fn ($device) => $device->interfaces->filter(fn ($interface) => $interface->interface_kind === InterfaceKind::VLAN)
            )->collapse(),
            'CONFIGURE_LAG_INTERFACE' => $devices->map(
                fn ($device) => $device->interfaces->filter(fn ($interface) => $interface->interface_kind === InterfaceKind::LAG)
            )->collapse(),
            default => collect(),
        };
    }

    public function dispatchJob(Task $task): ?string
    {
        $task->loadMissing('deployment');
        $task->unsetRelation('devices');
        $task->unsetRelation('deviceInterfaces');
        $task->load(['devices', 'deviceInterfaces']);

        $queueName = JobQueueShard::resolve($task->job_queue);

        $centralAPIHelper = new CentralAPIHelper($task->deployment->client);
        $jobs = [];
        switch ($task->task_type) {
            case 'UPDATE_SYSTEM_INFO':
                $in_progress = $task->devices->filter(fn ($device) => $device->pivot->status !== 'COMPLETED');
                $jobs[] = $in_progress->map(fn ($device) => new UpdateSystemInfo($device, $task, $centralAPIHelper))->toArray();
                break;
            case 'PREPROVISION_DEVICE_TO_GROUP':
                $in_progress = $task->devices->filter(fn ($device) => $device->pivot->status !== 'COMPLETED');
                $devices_by_group = $in_progress->groupBy('group');
                $chunked_devices_by_group_with_keys = $this->chunk_devices($devices_by_group);
                $devices_by_group_jobs = $this->create_jobs_by_grouped_chunks($chunked_devices_by_group_with_keys, $task, $centralAPIHelper, PreprovisionDevicesToGroupJob::class);
                // One batch segment so all chunk jobs dispatch together (avoids Bus::chain of batches and preserves batch_id).
                $jobs[] = $devices_by_group_jobs;
                break;
            case 'MOVE_DEVICE_TO_GROUP':
                $in_progress = $task->devices->filter(fn ($device) => $device->pivot->status !== 'COMPLETED');
                $devices_by_group = $in_progress->groupBy('group');
                $chunked_devices_by_group_with_keys = $this->chunk_devices($devices_by_group);
                $devices_by_group_jobs = $this->create_jobs_by_grouped_chunks($chunked_devices_by_group_with_keys, $task, $centralAPIHelper, MoveDevicesToGroupJob::class);
                $jobs[] = $devices_by_group_jobs;
                break;
            case 'ASSIGN_DEVICE_FUNCTION':
                $in_progress = $task->devices->filter(fn ($device) => $device->pivot->status !== 'COMPLETED');
                $devices_by_device_function = $in_progress->groupBy('device_function');
                $chunked_devices_by_group_with_keys = $this->chunk_devices($devices_by_device_function);
                $devices_by_device_function_jobs = $this->create_jobs_by_grouped_chunks($chunked_devices_by_group_with_keys, $task, $centralAPIHelper, AssignDeviceFunctionJob::class);
                $jobs[] = $devices_by_device_function_jobs;
                break;
            case 'ASSIGN_DEVICE_TO_SITE':
                $in_progress = $task->devices->filter(fn ($device) => $device->pivot->status !== 'COMPLETED');
                $jobs[] = $in_progress->map(fn ($device) => new AssociateDeviceToSiteJob($device, $task, $centralAPIHelper))->toArray();
                break;
            case 'CREATE_VSF_PROFILE':
                $devices_with_vsf_profile = $task->devices->filter(fn ($device) => $device->sku && $device->pivot->status !== 'COMPLETED');
                $jobs[] = $devices_with_vsf_profile->map(fn ($device) => new CreateVSFProfileJob($device, $task, $centralAPIHelper))->toArray();
                break;
            case 'REMOVE_LOCAL_OVERRIDE_DNS_PROFILE':
                $devices_with_vsf_profile = $task->devices->filter(fn ($device) => $device->sku && $device->pivot->status !== 'COMPLETED');
                $jobs[] = $devices_with_vsf_profile->map(fn ($device) => new RemoveLocalOverrideDnsJob($task, $device, $centralAPIHelper))->toArray();
                break;
            case 'REMOVE_LOCAL_OVERRIDE_NTP_PROFILE':
                $devices_with_vsf_profile = $task->devices->filter(fn ($device) => $device->sku && $device->pivot->status !== 'COMPLETED');
                $jobs[] = $devices_with_vsf_profile->map(fn ($device) => new RemoveLocalOverrideNtpJob($task, $device, $centralAPIHelper))->toArray();
                break;
            case 'REMOVE_LOCAL_OVERRIDE_VLANS':
                $devices_with_vsf_profile = $task->devices->filter(fn ($device) => $device->sku && $device->pivot->status !== 'COMPLETED');
                $jobs[] = $devices_with_vsf_profile->map(fn ($device) => new RemoveLocalOverrideVlansJob($task, $device, $centralAPIHelper))->toArray();
                break;
            case 'REMOVE_LOCAL_OVERRIDE_STATIC_ROUTE':
                $devices_with_vsf_profile = $task->devices->filter(fn ($device) => $device->sku && $device->pivot->status !== 'COMPLETED');
                $jobs[] = $devices_with_vsf_profile->map(fn ($device) => new RemoveLocalOverrideStaticRouteJob($task, $device, $centralAPIHelper))->toArray();
                break;
            case 'CONFIGURE_ETHERNET_INTERFACE':
                $devices_with_port_profiles = $task->devices->map(function ($device) {
                    $device->interfaces_sw_profiles = $device->interfaces->filter(fn ($interface) => $interface->sw_profile && str_contains($interface->interface, '/'));

                    return $device;
                });
                $task = $this->attach_interfaces($task, $this->getConfigureAllInterfacesForType($devices_with_port_profiles, 'CONFIGURE_ETHERNET_INTERFACE'));
                $in_progress = $task->deviceInterfaces->filter(fn ($device_interface) => $device_interface->pivot->status !== 'COMPLETED');
                $jobs[] = $in_progress->map(fn ($interface) => new ConfigureEthernetInterface($interface, $task, $centralAPIHelper))->toArray();
                break;
            case 'CONFIGURE_VLAN_INTERFACE':
                $vlan_interfaces = $this->getConfigureAllInterfacesForType($task->devices, 'CONFIGURE_VLAN_INTERFACE');
                $task = $this->attach_interfaces($task, $vlan_interfaces);
                $in_progress = $task->deviceInterfaces->filter(fn ($device_interface) => $device_interface->pivot->status !== 'COMPLETED');
                $jobs[] = $in_progress->map(fn ($vlan_interface) => new ConfigureVlanInterfaceJob($vlan_interface, $task, $centralAPIHelper))->toArray();
                break;
            case 'CONFIGURE_LAG_INTERFACE':
                $lag_interfaces = $this->getConfigureAllInterfacesForType($task->devices, 'CONFIGURE_LAG_INTERFACE');
                $task = $this->attach_interfaces($task, $lag_interfaces);
                $in_progress = $task->deviceInterfaces->filter(fn ($device_interface) => $device_interface->pivot->status !== 'COMPLETED');
                $jobs[] = $in_progress->map(fn ($lag_interface) => new ConfigureLagInterfaceJob($lag_interface, $task, $centralAPIHelper))->toArray();
                break;
            case 'ASSOCIATE_SITE_AND_NAME':
                $in_progress = $task->devices->filter(fn ($device) => $device->pivot->status !== 'COMPLETED');
                $jobs[] = $in_progress->map(fn ($device) => new AssociateSiteAndNameJob($device, $task, $centralAPIHelper))->toArray();
                break;
        }

        $pendingBatches = [];

        foreach ($jobs as $segment) {
            if (is_array($segment)) {
                $segment = array_values(array_filter($segment));
                if ($segment === []) {
                    continue;
                }
                $pendingBatches[] = Bus::batch($segment)->allowFailures()->onQueue($queueName);
            } elseif ($segment instanceof ShouldQueue) {
                $pendingBatches[] = Bus::batch([$segment])->allowFailures()->onQueue($queueName);
            }
        }

        if ($pendingBatches === []) {
            return null;
        }

        if (count($pendingBatches) === 1) {
            return $pendingBatches[0]->dispatch()->id;
        }

        Bus::chain($pendingBatches)->onQueue($queueName)->dispatch();

        return null;
    }

    public static function get_unique_sw_profiles(Collection $devices)
    {
        return $devices->map(fn ($device) => $device->interfaces_sw_profiles->unique('sw_profile'))->collapse()->unique('sw_profile');
    }
}
