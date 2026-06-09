<?php

namespace App\Http\Controllers;

use App\Helper\CentralAPIHelper;
use App\Helper\GreenLakeAPIHelper;
use App\InterfaceKind;
use App\JobQueueShard;
use App\Jobs\AddVlansToDeviceGroup;
use App\Jobs\AssignDeviceFunctionJob;
use App\Jobs\AssignSubscriptionJob;
use App\Jobs\AssociateDeviceToSiteJob;
use App\Jobs\AssociateSiteAndNameJob;
use App\Jobs\ConfigureEthernetInterface;
use App\Jobs\ConfigureLagInterfaceJob;
use App\Jobs\ConfigureMirrorSessionJob;
use App\Jobs\ConfigureVlanInterfaceJob;
use App\Jobs\CreateNewCentralCXGroup;
use App\Jobs\CreateVSFProfileJob;
use App\Jobs\CreateVsxProfileJob;
use App\Jobs\MoveDevicesToGroupJob;
use App\Jobs\PreprovisionDevicesToGroupJob;
use App\Jobs\RemoveLocalOverrideDNSJob;
use App\Jobs\RemoveLocalOverrideLocalManagementProfileJob;
use App\Jobs\RemoveLocalOverrideNTPJob;
use App\Jobs\RemoveLocalOverrideStaticRouteJob;
use App\Jobs\RemoveLocalOverrideVlansJob;
use App\Jobs\UnassignSubscriptionJob;
use App\Jobs\UpdateSystemInfo;
use App\LicenseType;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\Site;
use App\Models\Task;
use App\Services\DeploymentCriticalCheckService;
use App\Services\DeviceCentralVerifier;
use App\Services\EthernetInterfaceCentralVerifier;
use App\Services\LagInterfaceCentralVerifier;
use App\Services\LicensingInventoryService;
use App\Services\LicensingPoolResolver;
use App\Services\RelaunchFailedCriticalConfigService;
use App\Services\TaskRemediationCheckService;
use App\Services\VlanInterfaceCentralVerifier;
use App\TaskType;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
        'CREATE_VSX_PROFILE' => [
            'group',
            'site',
            'vsx_profile',
            'vsx_role',
            'vsx_system_mac',
            'vsx_isl_ports',
            'vsx_keepalive_ports',
        ],
        'CONFIGURE_MIRROR_SESSION' => [
            'mirror_session_id',
            'mirror_dst_ports',
            'mirror_vlans',
            'mirror_name',
        ],
        'MOVE_DEVICE_TO_GROUP' => [
            'group',
        ],
        'REMOVE_LOCAL_OVERRIDE_VLANS' => [],
        'REMOVE_LOCAL_OVERRIDE_DNS_PROFILE' => [],
        'REMOVE_LOCAL_OVERRIDE_NTP_PROFILE' => [],
        'REMOVE_LOCAL_OVERRIDE_STATIC_ROUTE' => [],
        'REMOVE_LOCAL_OVERRIDE_LOCAL_MANAGEMENT_PROFILE' => [],
        'ADD_VLANS_FOR_DEVICE_GROUP' => [
            'group',
        ],
        'CREATE_NEW_CENTRAL_CX_GROUP' => [
            'group',
        ],
    ];

    private $core_vlans = [
        ['vlan' => 2, 'name' => 'Voice', 'enable' => true, 'igmp' => ['enable' => true, 'snooping' => true, 'version' => 3]],
        ['vlan' => 3, 'name' => 'Visitor', 'enable' => true, 'igmp' => ['enable' => true, 'snooping' => true, 'version' => 3]],
        ['vlan' => 4, 'name' => 'Freezer_Voice', 'enable' => true, 'igmp' => ['enable' => true, 'snooping' => true, 'version' => 3]],
        ['vlan' => 6, 'name' => 'WCDAGV', 'enable' => true, 'igmp' => ['enable' => true, 'snooping' => true, 'version' => 3]],
        ['vlan' => 7, 'name' => 'AGVMGMT', 'enable' => true, 'igmp' => ['enable' => true, 'snooping' => true, 'version' => 3]],
        ['vlan' => 8, 'name' => 'WCDLAN', 'enable' => true, 'igmp' => ['enable' => true, 'snooping' => true, 'version' => 3]],
        ['vlan' => 10, 'name' => 'WCDWLAN', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 12, 'name' => 'WCDTM', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 14, 'name' => 'WCDLOG', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 16, 'name' => 'TJLAN', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 17, 'name' => 'TJWLAN', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 20, 'name' => 'AccessControl', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 21, 'name' => 'ProdMGMT', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 22, 'name' => 'WCDSVR', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 23, 'name' => 'WCD_PI', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 24, 'name' => 'WCDKitchen', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 25, 'name' => 'BCP', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 28, 'name' => 'BananaRipening', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 30, 'name' => 'APMGMT', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 40, 'name' => 'RFID', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 42, 'name' => 'RF', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 60, 'name' => 'TEMPSENSOR', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 176, 'name' => 'Replication', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 199, 'name' => 'vMotion', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
    ];

    private $access_vlans = [
        ['vlan' => 2, 'name' => 'Voice', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true, 'voice-enable' => true],
        ['vlan' => 3, 'name' => 'Visitor', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 4, 'name' => 'Freezer_Voice', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true, 'voice-enable' => true],
        ['vlan' => 6, 'name' => 'WCDAGV', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 8, 'name' => 'WCDLAN', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 10, 'name' => 'WCDWLAN', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 12, 'name' => 'WCDTM', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 14, 'name' => 'WCDLOG', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 16, 'name' => 'TJLAN', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 17, 'name' => 'TJWLAN', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 20, 'name' => 'AccessControl', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 23, 'name' => 'WCD_PI', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 24, 'name' => 'WCDKitchen', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 28, 'name' => 'BananaRipening', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 30, 'name' => 'APMGMT', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 40, 'name' => 'RFID', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 42, 'name' => 'RF', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 60, 'name' => 'TEMPSENSOR', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
    ];

    private $dmz_vlans = [
        ['vlan' => 700, 'name' => 'WAN', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 815, 'name' => 'Internet 1', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 816, 'name' => 'Internet 2', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 915, 'name' => 'Internet 3', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 916, 'name' => 'Internet 4', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
    ];

    private $svr_vlans = [
        ['vlan' => 6, 'name' => 'WCDAGV', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 7, 'name' => 'AGVMGMT', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 21, 'name' => 'ProdMGMT', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 22, 'name' => 'WCDSVR', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 25, 'name' => 'BCP', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 176, 'name' => 'Replication', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 199, 'name' => 'vMotion', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
    ];

    private $mgmt_vlans = [
        ['vlan' => 2, 'name' => 'Voice', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 3, 'name' => 'Visitor', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 4, 'name' => 'Freezer_Voice', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 6, 'name' => 'WCDAGV', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 7, 'name' => 'AGVMGMT', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 8, 'name' => 'WCDLAN', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 10, 'name' => 'WCDWLAN', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 12, 'name' => 'WCDTM', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 14, 'name' => 'WCDLOG', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 16, 'name' => 'TJLAN', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 17, 'name' => 'TJWLAN', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 20, 'name' => 'AccessControl', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 21, 'name' => 'ProdMGMT', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 22, 'name' => 'WCDSVR', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 23, 'name' => 'WCD_PI', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 24, 'name' => 'WCDKitchen', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 25, 'name' => 'BCP', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 28, 'name' => 'BananaRipening', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 30, 'name' => 'APMGMT', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 40, 'name' => 'RFID', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 42, 'name' => 'RF', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
        ['vlan' => 60, 'name' => 'TEMPSENSOR', 'igmp' => ['enable' => true, 'version' => 3, 'snooping' => true], 'enable' => true],
    ];

    /**
     * @return array<int, string>
     */
    protected function vlanWarehouseGroupsFromPrefix(string $prefix): array
    {
        $prefix = trim($prefix);

        return [
            'WHSE-'.$prefix.'-ACCESS',
            'WHSE-'.$prefix.'-CORE',
            'WHSE-'.$prefix.'-MGMT',
            'WHSE-'.$prefix.'-DMZ',
            'WHSE-'.$prefix.'-SERVER',
        ];
    }

    /**
     * VLAN template set from device group name (MGMT overrides other role tokens).
     *
     * @return array<int, array{vlan: int, name: string}>
     */
    protected function resolveVlansForDeviceGroupName(string $deviceGroup): array
    {
        if (str_contains($deviceGroup, 'MGMT')) {
            return $this->mgmt_vlans;
        }
        if (str_contains($deviceGroup, 'CORE')) {
            return $this->core_vlans;
        }
        if (str_contains($deviceGroup, 'ACCESS')) {
            return $this->access_vlans;
        }
        if (str_contains($deviceGroup, 'DMZ')) {
            return $this->dmz_vlans;
        }
        if (str_contains($deviceGroup, 'SERVER')) {
            return $this->svr_vlans;
        }

        return [];
    }

    public function index(Request $request)
    {
        $currentClient = $request->user()?->currentClient();
        $taskName = trim((string) $request->query('task_name', ''));
        $deploymentName = trim((string) $request->query('deployment_name', ''));
        $status = trim((string) $request->query('status', ''));

        $tasksQuery = Task::query()
            ->with(['deployment.client'])
            ->withCount(['devices', 'deviceInterfaces'])
            ->where(function ($query) {
                $query->whereNull('composite_group_id')
                    ->orWhere('composite_order', 1);
            })
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
                $displayType = $task->composite_kind ?? $task->task_type;
                $supportsRemediationCheck = Task::supportsRemediationCentralCheck($task->composite_kind);
                $supportsCentralCheck = Task::supportsCentralCheck($task->task_type) || $supportsRemediationCheck;

                $canRunCentralCheck = false;
                if ($supportsRemediationCheck && $task->composite_group_id !== null) {
                    $siblings = Task::query()
                        ->where('composite_group_id', $task->composite_group_id)
                        ->get();
                    $canRunCentralCheck = Task::compositeCanRunRemediationCheck($task->composite_kind, $siblings);
                } elseif (Task::supportsCentralCheck($task->task_type)) {
                    $canRunCentralCheck = $task->status === 'COMPLETED';
                }

                return [
                    'id' => $task->id,
                    'task_name' => Task::getTaskFriendlyName($displayType),
                    'deployment_name' => $task->deployment?->name,
                    'client_name' => $task->deployment?->client?->name,
                    'status' => $task->status,
                    'item_count' => $category === 'INTERFACE' ? $task->device_interfaces_count : $task->devices_count,
                    'human_updated_at' => Carbon::parse($task->updated_at)->diffForHumans(),
                    'deployment_time' => $task->deployment_time,
                    'wait_time' => $task->wait_time,
                    'supports_central_check' => $supportsCentralCheck,
                    'supports_remediation_check' => $supportsRemediationCheck,
                    'can_run_central_check' => $canRunCentralCheck,
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
                'supports_remediation_check' => Task::supportsRemediationCentralCheck($task->composite_kind),
                'can_run_remediation_check' => Task::compositeCanRunRemediationCheck($task->composite_kind, $siblings),
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
            'supports_central_check' => Task::supportsCentralCheck($task->task_type),
            'can_run_central_check' => Task::supportsCentralCheck($task->task_type) && $task->status === 'COMPLETED',
        ]);
    }

    public function check(Request $request, Task $task)
    {
        $task->loadMissing('deployment.client');

        $currentClient = $request->user()?->currentClient();
        if (! $currentClient || (int) $task->deployment?->client_id !== (int) $currentClient->id) {
            session()->flash('error', 'Please set current client to match this deployment before verifying configuration.');

            return redirect()->route('tasks.index');
        }

        if (! Task::supportsCentralCheck($task->task_type)) {
            abort(404);
        }

        $helper = new CentralAPIHelper($task->deployment->client);

        if ($this->isDeviceCentralCheckTask($task->task_type)) {
            $checkKind = match ($task->task_type) {
                'ASSOCIATE_DEVICE_TO_SITE' => 'site_association',
                'ASSOCIATE_SITE_AND_NAME' => 'site_and_name',
                'UPDATE_SYSTEM_INFO' => 'device_name',
                default => abort(404),
            };
            $verification = (new DeviceCentralVerifier)->verify($task, $helper);
        } else {
            $checkKind = match ($task->task_type) {
                'CONFIGURE_LAG_INTERFACE' => 'lag',
                'CONFIGURE_ETHERNET_INTERFACE' => 'ethernet',
                'CONFIGURE_VLAN_INTERFACE' => 'vlan',
                default => abort(404),
            };

            $verification = match ($task->task_type) {
                'CONFIGURE_LAG_INTERFACE' => (new LagInterfaceCentralVerifier)->verify($task, $helper),
                'CONFIGURE_ETHERNET_INTERFACE' => (new EthernetInterfaceCentralVerifier)->verify($task, $helper),
                'CONFIGURE_VLAN_INTERFACE' => (new VlanInterfaceCentralVerifier)->verify($task, $helper),
                default => abort(404),
            };
        }

        $passed = collect($verification['results'])->where('ok', true)->count();
        $failed = collect($verification['results'])->where('ok', false)->count();

        return Inertia::render('Task/Check', [
            'task' => $task->only(['id', 'task_type', 'status']),
            'task_friendly_name' => Task::getTaskFriendlyName($task->task_type),
            'check_kind' => $checkKind,
            'deployment' => $task->deployment->only(['id', 'name']),
            'device_errors' => $verification['device_errors'],
            'results' => $verification['results'],
            'summary' => [
                'total' => count($verification['results']),
                'passed' => $passed,
                'failed' => $failed,
            ],
            'can_relaunch_failed_verification' => $failed > 0,
        ]);
    }

    public function relaunchFailedVerification(Request $request, Task $task)
    {
        $task->loadMissing('deployment.client');

        $currentClient = $request->user()?->currentClient();
        if (! $currentClient || (int) $task->deployment?->client_id !== (int) $currentClient->id) {
            session()->flash('error', 'Please set current client to match this deployment before relaunching failed interfaces.');

            return redirect()->route('tasks.index');
        }

        if (! Task::supportsCentralCheck($task->task_type)) {
            abort(404);
        }

        $helper = new CentralAPIHelper($task->deployment->client);

        if ($this->isDeviceCentralCheckTask($task->task_type)) {
            $verification = (new DeviceCentralVerifier)->verify($task, $helper);

            $failedDeviceIds = collect($verification['results'])
                ->where('ok', false)
                ->pluck('device_id')
                ->unique()
                ->values();

            if ($failedDeviceIds->isEmpty()) {
                session()->flash('error', 'No devices failed verification; nothing to relaunch.');

                return back();
            }

            $namePrefix = match ($task->task_type) {
                'ASSOCIATE_DEVICE_TO_SITE' => 'associate_device_to_site_retry_',
                'ASSOCIATE_SITE_AND_NAME' => 'associate_site_and_name_retry_',
                'UPDATE_SYSTEM_INFO' => 'update_system_info_retry_',
                default => 'device_task_retry_',
            };

            $jobQueue = $task->job_queue;
            if (! is_string($jobQueue) || $jobQueue === '') {
                $jobQueue = $this->allocateJobQueue($request, (string) $task->id);
            }

            $newTask = $task->deployment->tasks()->create([
                'task_type' => $task->task_type,
                'name' => $namePrefix.$task->deployment->name.now(),
                'deployment_time' => $task->deployment_time,
                'wait_time' => $task->wait_time,
                'status' => 'IN_PROGRESS',
                'job_queue' => $jobQueue,
            ]);

            $attachData = $failedDeviceIds
                ->mapWithKeys(fn (int $id) => [$id => ['status' => 'PENDING']])
                ->all();
            $newTask->devices()->attach($attachData);

            $batchId = $this->dispatchJob($newTask);

            if ($batchId !== null) {
                $newTask->forceFill(['batch_id' => $batchId])->save();
            }

            session()->flash(
                'success',
                'Started a new task for '.$failedDeviceIds->count().' device(s) that failed verification.',
            );

            return to_route('tasks.show', $newTask);
        }

        $verification = match ($task->task_type) {
            'CONFIGURE_LAG_INTERFACE' => (new LagInterfaceCentralVerifier)->verify($task, $helper),
            'CONFIGURE_ETHERNET_INTERFACE' => (new EthernetInterfaceCentralVerifier)->verify($task, $helper),
            'CONFIGURE_VLAN_INTERFACE' => (new VlanInterfaceCentralVerifier)->verify($task, $helper),
            default => abort(404),
        };

        $failedInterfaceIds = collect($verification['results'])
            ->where('ok', false)
            ->pluck('device_interface_id')
            ->unique()
            ->values();

        if ($failedInterfaceIds->isEmpty()) {
            session()->flash('error', 'No interfaces failed verification; nothing to relaunch.');

            return back();
        }

        $deviceIds = DeviceInterface::query()
            ->whereIn('id', $failedInterfaceIds)
            ->pluck('device_id')
            ->unique()
            ->values();

        $namePrefix = match ($task->task_type) {
            'CONFIGURE_LAG_INTERFACE' => 'configure_lag_interface_retry_',
            'CONFIGURE_ETHERNET_INTERFACE' => 'configure_ethernet_interface_retry_',
            'CONFIGURE_VLAN_INTERFACE' => 'configure_vlan_interface_retry_',
            default => 'configure_interface_retry_',
        };

        $jobQueue = $task->job_queue;
        if (! is_string($jobQueue) || $jobQueue === '') {
            $jobQueue = $this->allocateJobQueue($request, (string) $task->id);
        }

        $newTask = $task->deployment->tasks()->create([
            'task_type' => $task->task_type,
            'name' => $namePrefix.$task->deployment->name.now(),
            'deployment_time' => $task->deployment_time,
            'wait_time' => $task->wait_time,
            'status' => 'IN_PROGRESS',
            'job_queue' => $jobQueue,
        ]);

        $newTask->devices()->attach($deviceIds);

        $attachData = $failedInterfaceIds
            ->mapWithKeys(fn (int $id) => [$id => ['status' => 'PENDING']])
            ->all();
        $newTask->deviceInterfaces()->attach($attachData);

        $batchId = $this->dispatchJob($newTask);

        if ($batchId !== null) {
            $newTask->forceFill(['batch_id' => $batchId])->save();
        }

        session()->flash(
            'success',
            'Started a new task for '.$failedInterfaceIds->count().' interface(s) that failed verification.',
        );

        return to_route('tasks.show', $newTask);
    }

    /**
     * Progress counts for composite sub-jobs that are not tracked via device/interface pivots.
     *
     * @return array{completed: int, total: int}|null
     */
    protected function compositeSubJobProgressForDisplay(Task $sub): ?array
    {
        if ($sub->task_type === 'CREATE_NEW_CENTRAL_CX_GROUP') {
            return [
                'completed' => $sub->status === 'COMPLETED' ? 1 : 0,
                'total' => 1,
            ];
        }

        if ($sub->task_type !== 'ADD_VLANS_FOR_DEVICE_GROUP') {
            return null;
        }

        $name = $sub->vlan_target_device_group;
        if (! is_string($name) || trim($name) === '') {
            return ['completed' => 0, 'total' => 0];
        }

        $total = count($this->resolveVlansForDeviceGroupName(trim($name)));
        if ($total === 0) {
            return [
                'completed' => $sub->status === 'COMPLETED' ? 1 : 0,
                'total' => 1,
            ];
        }

        if ($sub->status === 'COMPLETED') {
            return ['completed' => $total, 'total' => $total];
        }

        $log = (string) ($sub->status_log ?? '');
        $fromLog = substr_count($log, 'Added vlan ')
            + substr_count($log, 'already exists in device group');

        return ['completed' => min($fromLog, $total), 'total' => $total];
    }

    /**
     * @return array{supports_central_check: bool, can_run_central_check: bool}
     */
    protected function centralCheckFlagsForTask(Task $task): array
    {
        $supports = Task::supportsCentralCheck($task->task_type);

        return [
            'supports_central_check' => $supports,
            'can_run_central_check' => $supports && $task->status === 'COMPLETED',
        ];
    }

    protected function isDeviceCentralCheckTask(string $taskType): bool
    {
        return in_array($taskType, [
            'ASSOCIATE_DEVICE_TO_SITE',
            'ASSOCIATE_SITE_AND_NAME',
            'UPDATE_SYSTEM_INFO',
        ], true);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Task>  $siblings
     * @return array<int, array<string, mixed>>
     */
    protected function buildSubJobsForCompositePage(Collection $siblings): array
    {
        return $siblings->map(function (Task $sub) {
            $progressOverride = $this->compositeSubJobProgressForDisplay($sub);
            if ($progressOverride !== null) {
                $devices = $sub->devices;

                return [
                    'id' => $sub->id,
                    'task_type' => $sub->task_type,
                    'status' => $sub->status,
                    'status_log' => $sub->status_log ?? '',
                    'friendly_label' => Task::getTaskFriendlyName($sub->task_type),
                    'completed_count' => $progressOverride['completed'],
                    'total_count' => $progressOverride['total'],
                    'is_device_based' => true,
                    'devices' => $devices,
                    'interfaces' => [],
                    'display_columns' => $this->display_columns[$sub->task_type] ?? [],
                    ...$this->centralCheckFlagsForTask($sub),
                ];
            }

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
                    ...$this->centralCheckFlagsForTask($sub),
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
                ...$this->centralCheckFlagsForTask($sub),
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

    public function store(
        Request $request,
        Deployment $deployment,
        LicensingInventoryService $licensingInventoryService,
    ) {
        $validated = $request->validate([
            'task_type' => ['required', Rule::in(array_map(fn ($task) => $task->name, TaskType::cases()))],
            'devices' => ['nullable', 'array'],
            'devices.*.id' => ['sometimes', 'integer'],
            'devices.*.service_name' => ['sometimes', 'string', 'max:255'],
            'deployment_time' => ['required', 'integer'],
            'vlan_site_prefix' => ['nullable', 'string', 'max:64'],
            'override_device_scope' => ['nullable', Rule::in(['vsf_only', 'all'])],
            'licensing_mode' => ['nullable', Rule::in(['uniform', 'per_device'])],
            'license_tag' => ['nullable', 'string', 'max:255'],
            'license_type' => ['nullable', 'string', 'max:255'],
            'devices.*.license_tag' => ['sometimes', 'string', 'max:255'],
            'devices.*.license_type' => ['sometimes', 'string', 'max:255'],
            'service_name' => ['nullable', 'string', 'max:255'],
        ]);

        $isAddVlans = $validated['task_type'] === 'ADD_VLANS_TO_DEVICE_GROUP';
        $vlanSitePrefix = trim((string) ($validated['vlan_site_prefix'] ?? ''));

        if (! $isAddVlans) {
            if (! isset($validated['devices']) || $validated['devices'] === []) {
                return back()->withErrors(['devices' => 'Select at least one device.']);
            }
        } elseif ($vlanSitePrefix !== '') {
            if (! preg_match('/^[A-Za-z0-9_-]+$/', $vlanSitePrefix)) {
                return back()->withErrors(['vlan_site_prefix' => 'Use only letters, numbers, hyphens, or underscores.']);
            }
        } elseif (! isset($validated['devices']) || $validated['devices'] === []) {
            return back()->withErrors(['devices' => 'Select at least one device with a group set, or enter a site prefix.']);
        }

        $shardEntropy = (string) Str::uuid();

        if ($validated['task_type'] === 'REMOVE_VSF_PROFILE_LOCAL_OVERRIDES') {
            $compositeGroupId = (string) Str::uuid();
            $compositeKind = 'REMOVE_VSF_PROFILE_LOCAL_OVERRIDES';
            $jobQueue = $this->allocateJobQueue($request, $shardEntropy);
            $overrideDeviceScope = $validated['override_device_scope'] ?? 'vsf_only';
            $selectedDeviceIds = Collection::make($validated['devices'])
                ->pluck('id')
                ->filter(fn ($id) => $id !== null)
                ->map(fn ($id) => (int) $id)
                ->values();
            $selectedDevices = Device::query()
                ->where('deployment_id', $deployment->id)
                ->whereIn('id', $selectedDeviceIds)
                ->get();
            $deviceAttachData = $selectedDevices->mapWithKeys(function (Device $device) use ($overrideDeviceScope) {
                $status = ($overrideDeviceScope === 'vsf_only' && ! $device->sku) ? 'COMPLETED' : 'PENDING';

                return [$device->id => ['status' => $status]];
            })->all();

            $compositeTaskDefinitions = [
                ['task_type' => 'REMOVE_LOCAL_OVERRIDE_VLANS', 'composite_order' => 1],
                ['task_type' => 'REMOVE_LOCAL_OVERRIDE_DNS_PROFILE', 'composite_order' => 2],
                ['task_type' => 'REMOVE_LOCAL_OVERRIDE_STATIC_ROUTE', 'composite_order' => 3],
                ['task_type' => 'REMOVE_LOCAL_OVERRIDE_NTP_PROFILE', 'composite_order' => 4],
                ['task_type' => 'REMOVE_LOCAL_OVERRIDE_LOCAL_MANAGEMENT_PROFILE', 'composite_order' => 5],
            ];

            $createdTasks = collect();
            foreach ($compositeTaskDefinitions as $definition) {
                $subTask = $deployment->tasks()->create([
                    'task_type' => $definition['task_type'],
                    'name' => 'task_for_'.$deployment->name.now(),
                    'deployment_time' => $validated['deployment_time'],
                    'status' => 'IN_PROGRESS',
                    'job_queue' => $jobQueue,
                    'composite_group_id' => $compositeGroupId,
                    'composite_kind' => $compositeKind,
                    'composite_order' => $definition['composite_order'],
                    'override_device_scope' => $overrideDeviceScope,
                ]);
                $subTask->devices()->attach($deviceAttachData);
                $batch = $this->dispatchJob($subTask);
                $subTask->update(['batch_id' => $batch]);
                $createdTasks->push($subTask);
            }

            $task = $createdTasks->first();
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
        } elseif ($validated['task_type'] === 'ADD_VLANS_TO_DEVICE_GROUP') {
            $compositeGroupId = (string) Str::uuid();
            $compositeKind = 'ADD_VLANS_TO_DEVICE_GROUP';
            $jobQueue = $this->allocateJobQueue($request, $shardEntropy);
            $createdVlanTasks = collect();
            $selectedDevices = collect();

            if ($vlanSitePrefix !== '') {
                $groups = $this->vlanWarehouseGroupsFromPrefix($vlanSitePrefix);
            } else {
                $selectedDeviceIds = Collection::make($validated['devices'])
                    ->pluck('id')
                    ->filter(fn ($id) => $id !== null)
                    ->map(fn ($id) => (int) $id)
                    ->values();

                $selectedDevices = Device::query()
                    ->where('deployment_id', $deployment->id)
                    ->whereIn('id', $selectedDeviceIds)
                    ->get();

                $missingGroup = $selectedDevices->filter(fn (Device $device): bool => trim((string) $device->group) === '');
                if ($missingGroup->isNotEmpty()) {
                    return back()->withErrors(['devices' => 'Every selected device must have a group set on the device row.']);
                }

                $groups = $selectedDevices
                    ->map(fn (Device $device): string => trim((string) $device->group))
                    ->unique()
                    ->values()
                    ->all();
            }

            if ($groups === []) {
                return back()->withErrors(['devices' => 'No device groups found for the selection.']);
            }

            $currentClient = $request->user()->currentClient();
            if (! $currentClient || (int) $deployment->client_id !== (int) $currentClient->id) {
                session()->flash('error', 'Please set current client to match this deployment before adding VLANs to device groups.');

                return back();
            }

            $centralHelper = new CentralAPIHelper($deployment->client);
            $groupNamesResult = $centralHelper->classic_collect_all_group_names();
            if (isset($groupNamesResult['error'])) {
                session()->flash('error', 'Could not load groups from Central.');

                return back();
            }

            $centralSet = array_flip($groupNamesResult['names']);
            $compositeOrder = 0;

            foreach ($groups as $groupName) {
                $deviceIdsForGroup = collect();
                if ($vlanSitePrefix === '' && $selectedDevices->isNotEmpty()) {
                    $deviceIdsForGroup = $selectedDevices
                        ->filter(fn (Device $device): bool => trim((string) $device->group) === $groupName)
                        ->pluck('id');
                }

                $centralGroupCreationTaskId = null;
                if (! array_key_exists($groupName, $centralSet)) {
                    $compositeOrder++;
                    $createTask = $deployment->tasks()->create([
                        'task_type' => 'CREATE_NEW_CENTRAL_CX_GROUP',
                        'name' => 'create_central_group_'.$groupName.'_'.now(),
                        'deployment_time' => $validated['deployment_time'],
                        'status' => 'IN_PROGRESS',
                        'job_queue' => $jobQueue,
                        'composite_group_id' => $compositeGroupId,
                        'composite_kind' => $compositeKind,
                        'composite_order' => $compositeOrder,
                        'vlan_target_device_group' => $groupName,
                    ]);
                    if ($deviceIdsForGroup->isNotEmpty()) {
                        $createTask->devices()->attach($deviceIdsForGroup);
                    }
                    $centralGroupCreationTaskId = $createTask->id;
                }

                $compositeOrder++;
                $createdTask = $deployment->tasks()->create([
                    'task_type' => 'ADD_VLANS_FOR_DEVICE_GROUP',
                    'name' => 'add_vlans_'.$groupName.'_'.now(),
                    'deployment_time' => $validated['deployment_time'],
                    'status' => 'IN_PROGRESS',
                    'job_queue' => $jobQueue,
                    'composite_group_id' => $compositeGroupId,
                    'composite_kind' => $compositeKind,
                    'composite_order' => $compositeOrder,
                    'vlan_target_device_group' => $groupName,
                    'central_group_creation_task_id' => $centralGroupCreationTaskId,
                ]);

                if ($deviceIdsForGroup->isNotEmpty()) {
                    $createdTask->devices()->attach($deviceIdsForGroup);
                }

                $batchId = $this->dispatchJob($createdTask);
                if ($batchId !== null) {
                    $createdTask->forceFill(['batch_id' => $batchId])->save();
                }

                $createdVlanTasks->push($createdTask);
            }

            $task = $createdVlanTasks->first();
        } elseif (in_array($validated['task_type'], ['ASSIGN_SUBSCRIPTION', 'UNASSIGN_SUBSCRIPTION'], true)) {
            $task = $this->storeLicensingTask(
                $request,
                $deployment,
                $validated,
                $shardEntropy,
                $licensingInventoryService,
                app(LicensingPoolResolver::class),
            );
        } elseif ($validated['task_type'] === 'CONFIGURE_MIRROR_SESSION') {
            $selectedDeviceIds = Collection::make($validated['devices'])
                ->pluck('id')
                ->filter(fn ($id) => $id !== null)
                ->map(fn ($id) => (int) $id)
                ->values();

            $selectedDevices = Device::query()
                ->where('deployment_id', $deployment->id)
                ->whereIn('id', $selectedDeviceIds)
                ->get();

            $fallbackMode = CentralAPIHelper::deploymentUsesMirrorFallbackMode($selectedDevices);

            if ($fallbackMode) {
                $devicesToAttach = $selectedDevices->filter(
                    fn (Device $device) => CentralAPIHelper::deviceMatchesMirrorSessionNamePattern($device)
                );

                if ($devicesToAttach->isEmpty()) {
                    return back()->withErrors([
                        'devices' => 'No selected devices match a mirror session name pattern (CORE, FZN-MDF-MGMT, or MDF-MGMT).',
                    ]);
                }
            } else {
                $devicesToAttach = $selectedDevices->filter(
                    fn (Device $device) => CentralAPIHelper::deviceHasMirrorAttributes($device)
                );

                if ($devicesToAttach->isEmpty()) {
                    return back()->withErrors([
                        'devices' => 'No selected devices have mirror session columns set.',
                    ]);
                }
            }

            $task = $deployment->tasks()->create([
                'task_type' => $validated['task_type'],
                'name' => 'task_for_'.$deployment->name.now(),
                'deployment_time' => $validated['deployment_time'],
                'status' => 'IN_PROGRESS',
                'job_queue' => $this->allocateJobQueue($request, $shardEntropy),
                'mirror_fallback_mode' => $fallbackMode,
            ]);

            $task->devices()->attach($devicesToAttach->pluck('id')->all());
            $batchId = $this->dispatchJob($task);
            if ($batchId !== null) {
                $task->forceFill(['batch_id' => $batchId])->save();
            }
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

    /**
     * @param  array<string, mixed>  $validated
     */
    private function storeLicensingTask(
        Request $request,
        Deployment $deployment,
        array $validated,
        string $shardEntropy,
        LicensingInventoryService $licensingInventoryService,
        LicensingPoolResolver $licensingPoolResolver,
    ): Task {
        $isAssign = $validated['task_type'] === 'ASSIGN_SUBSCRIPTION';
        $licensingMode = $validated['licensing_mode'] ?? 'uniform';
        $deviceRows = Collection::make($validated['devices'] ?? []);
        $deviceIds = $deviceRows
            ->pluck('id')
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->values();

        $selectedDevices = Device::query()
            ->where('deployment_id', $deployment->id)
            ->whereIn('id', $deviceIds)
            ->get()
            ->keyBy('id');

        if ($selectedDevices->count() !== $deviceIds->count()) {
            throw ValidationException::withMessages(['devices' => 'One or more selected devices are invalid for this deployment.']);
        }

        $deployment->loadMissing('client');
        $centralHelper = new CentralAPIHelper($deployment->client);
        $greenLakeHelper = new GreenLakeAPIHelper($deployment->client);
        $licensingOptions = $licensingInventoryService->resolveLicensingOptions(
            $deployment->client,
            $centralHelper,
            $greenLakeHelper,
        );
        if ($licensingOptions['central_error'] !== null) {
            throw ValidationException::withMessages(['devices' => $licensingOptions['central_error']]);
        }

        $availableSubscriptions = $licensingOptions['available_subscriptions'];
        $subscriptionsByKey = $licensingOptions['subscriptions_by_key'];
        $cachedPayload = $licensingInventoryService->buildFromCache($deployment->client, []);
        $inventoryBySerial = collect($cachedPayload['devices'])->keyBy('serial');

        $attachData = [];
        $taskLicenseTag = null;
        $taskLicenseType = null;

        if (! $isAssign) {
            foreach ($deviceIds as $deviceId) {
                $serial = (string) $selectedDevices->get($deviceId)->serial;
                $inventoryRow = $inventoryBySerial->get($serial);
                if (! is_array($inventoryRow)) {
                    throw ValidationException::withMessages(['devices' => "Device {$serial} has no subscription to remove."]);
                }

                $resolved = $licensingPoolResolver->resolveAssignedSubscriptionForUnassign(
                    $inventoryRow,
                    $subscriptionsByKey,
                );
                if (isset($resolved['error'])) {
                    $message = $resolved['error'] === 'no_greenlake_device'
                        ? "Device {$serial} is not linked in GreenLake. Renew licensing and try again."
                        : "Device {$serial} has no subscription to remove.";
                    throw ValidationException::withMessages(['devices' => $message]);
                }

                $attachData[$deviceId] = [
                    'status' => 'PENDING',
                    'licensing_service_name' => $resolved['greenlake_subscription_id'],
                    'license_tag' => $resolved['license_tag'],
                    'license_type' => $resolved['license_type'],
                ];
            }
        } elseif ($licensingMode === 'per_device') {
            if ($isAssign) {
                $poolGroups = [];
                foreach ($deviceRows as $row) {
                    $deviceId = (int) ($row['id'] ?? 0);
                    if (! $selectedDevices->has($deviceId)) {
                        continue;
                    }

                    $licenseTag = trim((string) ($row['license_tag'] ?? ''));
                    $licenseTypeValue = trim((string) ($row['license_type'] ?? ''));
                    if ($licenseTag === '' || $licenseTypeValue === '') {
                        throw ValidationException::withMessages(['devices' => 'Each device must have a license tag and license type selected.']);
                    }

                    $licenseType = LicenseType::tryFromValue($licenseTypeValue);
                    if ($licenseType === null) {
                        throw ValidationException::withMessages(['devices' => "Invalid license type \"{$licenseTypeValue}\" for one or more devices."]);
                    }

                    $groupKey = $licenseTag.'|'.$licenseType->value;
                    $poolGroups[$groupKey] ??= [
                        'license_tag' => $licenseTag,
                        'license_type' => $licenseType,
                        'device_ids' => [],
                    ];
                    $poolGroups[$groupKey]['device_ids'][] = $deviceId;
                }

                foreach ($poolGroups as $group) {
                    $capacityError = $licensingPoolResolver->validatePoolCapacity(
                        $group['license_tag'],
                        $group['license_type'],
                        count($group['device_ids']),
                        $availableSubscriptions,
                    );
                    if ($capacityError !== null) {
                        throw ValidationException::withMessages(['devices' => $capacityError['error']]);
                    }
                }

                foreach ($poolGroups as $group) {
                    $allocations = $licensingPoolResolver->allocateDevices(
                        $group['device_ids'],
                        $group['license_tag'],
                        $group['license_type'],
                        $availableSubscriptions,
                    );
                    if (count($allocations) !== count($group['device_ids'])) {
                        throw ValidationException::withMessages(['devices' => 'Could not allocate enough licenses from one or more tag/type pools. Renew licensing and try again.']);
                    }

                    foreach ($group['device_ids'] as $deviceId) {
                        $attachData[$deviceId] = [
                            'status' => 'PENDING',
                            'licensing_service_name' => $allocations[$deviceId],
                            'license_tag' => $group['license_tag'],
                            'license_type' => $group['license_type']->value,
                        ];
                    }
                }
            }
        } else {
            if ($isAssign) {
                $licenseTag = trim((string) ($validated['license_tag'] ?? ''));
                $licenseTypeValue = trim((string) ($validated['license_type'] ?? ''));
                if ($licenseTag === '') {
                    throw ValidationException::withMessages(['license_tag' => 'Select a license tag.']);
                }
                if ($licenseTypeValue === '') {
                    throw ValidationException::withMessages(['license_type' => 'Select a license type.']);
                }

                $licenseType = LicenseType::tryFromValue($licenseTypeValue);
                if ($licenseType === null) {
                    throw ValidationException::withMessages(['license_type' => 'Selected license type is not valid.']);
                }

                $capacityError = $licensingPoolResolver->validatePoolCapacity(
                    $licenseTag,
                    $licenseType,
                    $deviceIds->count(),
                    $availableSubscriptions,
                );
                if ($capacityError !== null) {
                    throw ValidationException::withMessages(['license_tag' => $capacityError['error']]);
                }

                $allocations = $licensingPoolResolver->allocateDevices(
                    $deviceIds->all(),
                    $licenseTag,
                    $licenseType,
                    $availableSubscriptions,
                );
                if (count($allocations) !== $deviceIds->count()) {
                    throw ValidationException::withMessages(['license_tag' => 'Could not allocate enough licenses from the selected tag/type pool. Renew licensing and try again.']);
                }

                $taskLicenseTag = $licenseTag;
                $taskLicenseType = $licenseType->value;

                foreach ($deviceIds as $deviceId) {
                    $attachData[$deviceId] = [
                        'status' => 'PENDING',
                        'licensing_service_name' => $allocations[$deviceId],
                        'license_tag' => $licenseTag,
                        'license_type' => $licenseType->value,
                    ];
                }
            }
        }

        if ($attachData === []) {
            throw ValidationException::withMessages(['devices' => 'Select at least one device.']);
        }

        $task = $deployment->tasks()->create([
            'task_type' => $validated['task_type'],
            'name' => 'task_for_'.$deployment->name.now(),
            'deployment_time' => $validated['deployment_time'],
            'status' => 'IN_PROGRESS',
            'job_queue' => $this->allocateJobQueue($request, $shardEntropy),
            'license_tag' => $taskLicenseTag,
            'license_type' => $taskLicenseType,
        ]);

        $task->devices()->attach($attachData);
        $batchId = $this->dispatchJob($task);
        if ($batchId !== null) {
            $task->forceFill(['batch_id' => $batchId])->save();
        }

        return $task;
    }

    public function checkCentralGroup(Request $request, Deployment $deployment)
    {
        $request->validate([
            'task_type' => ['required', Rule::in(['PREPROVISION_DEVICE_TO_GROUP', 'MOVE_DEVICE_TO_GROUP', 'ADD_VLANS_TO_DEVICE_GROUP'])],
            'vlan_site_prefix' => ['nullable', 'string', 'max:64'],
        ]);

        $currentClient = $request->user()->currentClient();
        if (! $currentClient || (int) $deployment->client_id !== (int) $currentClient->id) {
            session()->flash('error', 'Please set current client to match this deployment before checking groups.');

            return back();
        }

        $prefix = trim((string) $request->input('vlan_site_prefix', ''));

        if ($request->input('task_type') === 'ADD_VLANS_TO_DEVICE_GROUP' && $prefix !== '') {
            if (! preg_match('/^[A-Za-z0-9_-]+$/', $prefix)) {
                session()->flash('error', 'Invalid site prefix.');

                return back();
            }
            $deviceGroups = $this->vlanWarehouseGroupsFromPrefix($prefix);
        } else {
            $deviceGroups = Device::query()
                ->where('deployment_id', $deployment->id)
                ->pluck('group')
                ->map(fn ($g) => is_string($g) ? trim($g) : '')
                ->filter(fn ($g) => $g !== '')
                ->unique()
                ->values()
                ->all();
        }

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

    public function checkCentralSites(Request $request, Deployment $deployment)
    {
        $request->validate([
            'task_type' => ['required', Rule::in(['ASSOCIATE_DEVICE_TO_SITE', 'ASSOCIATE_SITE_AND_NAME'])],
        ]);

        $currentClient = $request->user()->currentClient();
        if (! $currentClient || (int) $deployment->client_id !== (int) $currentClient->id) {
            session()->flash('error', 'Please set current client to match this deployment before checking sites.');

            return back();
        }

        $devices = Device::query()
            ->where('deployment_id', $deployment->id)
            ->with('site')
            ->get();

        $withoutSite = $devices->filter(fn (Device $device): bool => $device->site_id === null || $device->site === null);
        if ($withoutSite->isNotEmpty()) {
            $names = $withoutSite->pluck('name')->filter()->values()->all();
            session()->flash('error', 'These devices have no site assigned: '.implode(', ', $names).'.');

            return back();
        }

        $siteNames = $devices
            ->map(fn (Device $device): string => trim((string) $device->site->name))
            ->filter(fn (string $name): bool => $name !== '')
            ->unique()
            ->values()
            ->all();

        if ($siteNames === []) {
            session()->flash('error', 'No valid site names are set for devices in this deployment.');

            return back();
        }

        $helper = new CentralAPIHelper($deployment->client);
        $result = $helper->classic_collect_all_sites();
        if (isset($result['error'])) {
            session()->flash('error', 'Could not load sites from Central.');

            return back();
        }

        $centralNames = [];
        foreach ($result['sites'] as $centralSite) {
            if (! is_array($centralSite)) {
                continue;
            }
            $siteName = $centralSite['site_name'] ?? null;
            if (is_string($siteName) && trim($siteName) !== '') {
                $centralNames[trim($siteName)] = true;
            }
        }

        $sitesToSync = $devices
            ->map(fn (Device $device) => $device->site)
            ->unique('id')
            ->filter(function (Site $site) use ($centralNames): bool {
                $name = trim((string) $site->name);

                return blank($site->scope_id) && $name !== '' && array_key_exists($name, $centralNames);
            })
            ->values();

        if ($sitesToSync->isNotEmpty()) {
            $syncResult = $helper->syncScopeIdsForSites($sitesToSync);
            if ($syncResult['error'] !== null) {
                session()->flash('error', $syncResult['error']);

                return back();
            }
        }

        $missing = array_values(array_filter(
            $siteNames,
            fn (string $name): bool => ! array_key_exists($name, $centralNames)
        ));

        if ($missing === []) {
            session()->flash('success', 'All site names exist in Central.');
        } else {
            session()->flash('error', 'These sites were not found in Central: '.implode(', ', $missing).'.');
        }

        return back();
    }

    public function forceUpdateSiteScopeIds(Request $request, Deployment $deployment)
    {
        $request->validate([
            'task_type' => ['required', Rule::in(['ASSOCIATE_DEVICE_TO_SITE', 'ASSOCIATE_SITE_AND_NAME'])],
        ]);

        $currentClient = $request->user()->currentClient();
        if (! $currentClient || (int) $deployment->client_id !== (int) $currentClient->id) {
            session()->flash('error', 'Please set current client to match this deployment before updating site scope IDs.');

            return back();
        }

        $devices = Device::query()
            ->where('deployment_id', $deployment->id)
            ->with('site')
            ->get();

        $withoutSite = $devices->filter(fn (Device $device): bool => $device->site_id === null || $device->site === null);
        if ($withoutSite->isNotEmpty()) {
            $names = $withoutSite->pluck('name')->filter()->values()->all();
            session()->flash('error', 'These devices have no site assigned: '.implode(', ', $names).'.');

            return back();
        }

        $sitesToSync = $devices
            ->map(fn (Device $device) => $device->site)
            ->unique('id')
            ->filter(fn (Site $site): bool => trim((string) $site->name) !== '')
            ->values();

        if ($sitesToSync->isEmpty()) {
            session()->flash('error', 'No valid site names are set for devices in this deployment.');

            return back();
        }

        $helper = new CentralAPIHelper($deployment->client);
        $syncResult = $helper->syncScopeIdsForSites($sitesToSync);

        if ($syncResult['error'] !== null) {
            session()->flash('error', $syncResult['error']);

            return back();
        }

        session()->flash('success', $this->formatSiteScopeIdUpdateFlashMessage($sitesToSync));

        return back();
    }

    private function formatSiteScopeIdUpdateFlashMessage(Collection $sites): string
    {
        $details = $sites
            ->sortBy(fn (Site $site) => $site->name)
            ->map(fn (Site $site) => "{$site->name}: {$site->scope_id}")
            ->values()
            ->all();

        if (count($details) === 1) {
            return "Updated scope ID for {$details[0]}.";
        }

        return 'Updated scope IDs: '.implode(', ', $details).'.';
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
            if ($t->composite_kind === 'ADD_VLANS_TO_DEVICE_GROUP' && $t->task_type === 'CREATE_NEW_CENTRAL_CX_GROUP') {
                continue;
            }
            $batchId = $this->dispatchJob($t);
            if ($batchId !== null) {
                $t->forceFill(['batch_id' => $batchId])->save();
            }
        }

        return to_route('tasks.show', $group->first());
    }

    public function relaunch(Request $request, Task $task)
    {
        $group = $this->tasksInCompositeGroup($task);

        foreach ($group as $t) {
            if (! in_array($t->status, ['FAILED', 'TIMED_OUT', 'CANCELLED'], true)) {
                session()->flash('error', 'Only failed, timed out, or cancelled tasks can be relaunched.');

                return back();
            }
        }

        $validated = $request->validate([
            'deployment_time' => ['nullable', 'integer', 'min:0'],
            'wait_time' => ['nullable', 'integer', 'min:0'],
        ]);

        $updates = ['status' => 'IN_PROGRESS'];
        if (array_key_exists('deployment_time', $validated)) {
            $updates['deployment_time'] = $validated['deployment_time'];
        }
        if (array_key_exists('wait_time', $validated)) {
            $updates['wait_time'] = $validated['wait_time'];
        }

        foreach ($group as $t) {
            $t->resetIncompletePivotRowsToPending();
            $t->update($updates);
            if ($t->composite_kind === 'ADD_VLANS_TO_DEVICE_GROUP' && $t->task_type === 'CREATE_NEW_CENTRAL_CX_GROUP') {
                continue;
            }
            $batchId = $this->dispatchJob($t);
            if ($batchId !== null) {
                $t->forceFill(['batch_id' => $batchId])->save();
            }
        }

        return to_route('tasks.show', $group->first());
    }

    public function remediationCheck(Request $request, Task $task, TaskRemediationCheckService $remediationCheckService)
    {
        $task->loadMissing('deployment.client');
        $siblings = $this->tasksInCompositeGroup($task);

        if (! Task::supportsRemediationCentralCheck($task->composite_kind)) {
            abort(404);
        }

        $currentClient = $request->user()?->currentClient();
        if (! $currentClient || (int) $task->deployment?->client_id !== (int) $currentClient->id) {
            session()->flash('error', 'Please set current client to match this deployment before verifying remediation.');

            return redirect()->route('tasks.index');
        }

        $scope = $remediationCheckService->buildScope($siblings);
        $includeEthernet = $scope['include_ethernet'];

        return Inertia::render('Deployment/CriticalCheck', [
            'deployment' => $task->deployment->only(['id', 'name']),
            'device_count' => $scope['devices']->count(),
            'total_steps' => $remediationCheckService->totalSteps($siblings),
            'remediation_task_id' => $task->id,
            'include_ethernet' => $includeEthernet,
            'remediation_title' => Task::getTaskFriendlyName(RelaunchFailedCriticalConfigService::COMPOSITE_KIND),
            ...(new DeploymentCriticalCheckService)->emptyResults(),
        ]);
    }

    public function remediationCheckStep(
        Request $request,
        Task $task,
        int $step,
        TaskRemediationCheckService $remediationCheckService,
    ) {
        $task->loadMissing('deployment.client');
        $siblings = $this->tasksInCompositeGroup($task);

        if (! Task::supportsRemediationCentralCheck($task->composite_kind)) {
            abort(404);
        }

        $currentClient = $request->user()?->currentClient();
        if (! $currentClient || (int) $task->deployment?->client_id !== (int) $currentClient->id) {
            return response()->json(['message' => 'Please set current client to match this deployment.'], 403);
        }

        $validated = $request->validate([
            'dns_scope_id' => ['nullable', 'string'],
            'dns_scope_error' => ['nullable', 'string'],
        ]);

        $context = [];
        if (array_key_exists('dns_scope_id', $validated) && $validated['dns_scope_id'] !== null) {
            $context['dns_scope_id'] = $validated['dns_scope_id'];
        }
        if (array_key_exists('dns_scope_error', $validated) && $validated['dns_scope_error'] !== null) {
            $context['dns_scope_error'] = $validated['dns_scope_error'];
        }

        $helper = new CentralAPIHelper($task->deployment->client);
        $total = $remediationCheckService->totalSteps($siblings);

        if ($step < 0 || $step >= $total) {
            abort(404);
        }

        return response()->json(
            $remediationCheckService->runStep($siblings, $helper, $step, $context)
        );
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
            case 'CONFIGURE_MIRROR_SESSION':
                $fallbackMode = $task->mirror_fallback_mode;
                if ($fallbackMode === null) {
                    $fallbackMode = CentralAPIHelper::deploymentUsesMirrorFallbackMode($task->devices);
                }
                $in_progress = $task->devices->filter(fn ($device) => $device->pivot->status !== 'COMPLETED');
                $jobs[] = $in_progress->map(
                    fn ($device) => new ConfigureMirrorSessionJob($device, $task, $centralAPIHelper, (bool) $fallbackMode)
                )->toArray();
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
            case 'ASSOCIATE_DEVICE_TO_SITE':
                $in_progress = $task->devices->filter(fn ($device) => $device->pivot->status !== 'COMPLETED');
                $jobs[] = $in_progress->map(fn ($device) => new AssociateDeviceToSiteJob($device, $task, $centralAPIHelper))->toArray();
                break;
            case 'CREATE_VSF_PROFILE':
                $devices_with_vsf_profile = $task->devices->filter(fn ($device) => $device->sku && $device->pivot->status !== 'COMPLETED');
                $jobs[] = $devices_with_vsf_profile->map(fn ($device) => new CreateVSFProfileJob($device, $task, $centralAPIHelper))->toArray();
                break;
            case 'CREATE_VSX_PROFILE':
                $pending_vsx = $task->devices->filter(fn ($device) => $device->vsx_profile && $device->pivot->status !== 'COMPLETED');
                $jobs[] = $pending_vsx
                    ->groupBy('vsx_profile')
                    ->map(fn ($devices, $name) => new CreateVsxProfileJob($name, $devices, $task, $centralAPIHelper))
                    ->values()
                    ->toArray();
                break;
            case 'REMOVE_LOCAL_OVERRIDE_DNS_PROFILE':
                $devices_for_local_override = $this->devicesForLocalOverrideRemoval($task);
                $jobs[] = $devices_for_local_override->map(fn ($device) => new RemoveLocalOverrideDnsJob($task, $device, $centralAPIHelper))->toArray();
                break;
            case 'REMOVE_LOCAL_OVERRIDE_NTP_PROFILE':
                $devices_for_local_override = $this->devicesForLocalOverrideRemoval($task);
                $jobs[] = $devices_for_local_override->map(fn ($device) => new RemoveLocalOverrideNtpJob($task, $device, $centralAPIHelper))->toArray();
                break;
            case 'REMOVE_LOCAL_OVERRIDE_VLANS':
                $devices_for_local_override = $this->devicesForLocalOverrideRemoval($task);
                $jobs[] = $devices_for_local_override->map(fn ($device) => new RemoveLocalOverrideVlansJob($task, $device, $centralAPIHelper))->toArray();
                break;
            case 'REMOVE_LOCAL_OVERRIDE_STATIC_ROUTE':
                $devices_for_local_override = $this->devicesForLocalOverrideRemoval($task);
                $jobs[] = $devices_for_local_override->map(fn ($device) => new RemoveLocalOverrideStaticRouteJob($task, $device, $centralAPIHelper))->toArray();
                break;
            case 'REMOVE_LOCAL_OVERRIDE_LOCAL_MANAGEMENT_PROFILE':
                $devices_for_local_override = $this->devicesForLocalOverrideRemoval($task);
                $jobs[] = $devices_for_local_override->map(fn ($device) => new RemoveLocalOverrideLocalManagementProfileJob($task, $device, $centralAPIHelper))->toArray();
                break;
            case 'CONFIGURE_ETHERNET_INTERFACE':
                if ($task->deviceInterfaces->isNotEmpty()) {
                    $in_progress = $task->deviceInterfaces->filter(
                        fn ($device_interface) => $device_interface->pivot->status !== 'COMPLETED',
                    );
                } else {
                    $devices_with_port_profiles = $task->devices->map(function ($device) {
                        $device->interfaces_sw_profiles = $device->interfaces->filter(
                            fn ($interface) => $interface->sw_profile && str_contains($interface->interface, '/'),
                        );

                        return $device;
                    });
                    $task = $this->attach_interfaces(
                        $task,
                        $this->getConfigureAllInterfacesForType($devices_with_port_profiles, 'CONFIGURE_ETHERNET_INTERFACE'),
                    );
                    $in_progress = $task->deviceInterfaces->filter(
                        fn ($device_interface) => $device_interface->pivot->status !== 'COMPLETED',
                    );
                }
                $jobs[] = $in_progress->map(fn ($interface) => new ConfigureEthernetInterface($interface, $task, $centralAPIHelper))->toArray();
                break;
            case 'CONFIGURE_VLAN_INTERFACE':
                if ($task->deviceInterfaces->isNotEmpty()) {
                    $in_progress = $task->deviceInterfaces->filter(
                        fn ($device_interface) => $device_interface->pivot->status !== 'COMPLETED',
                    );
                } else {
                    $vlan_interfaces = $this->getConfigureAllInterfacesForType($task->devices, 'CONFIGURE_VLAN_INTERFACE');
                    $task = $this->attach_interfaces($task, $vlan_interfaces);
                    $in_progress = $task->deviceInterfaces->filter(
                        fn ($device_interface) => $device_interface->pivot->status !== 'COMPLETED',
                    );
                }
                $jobs[] = $in_progress->map(fn ($vlan_interface) => new ConfigureVlanInterfaceJob($vlan_interface, $task, $centralAPIHelper))->toArray();
                break;
            case 'CONFIGURE_LAG_INTERFACE':
                if ($task->deviceInterfaces->isNotEmpty()) {
                    $in_progress = $task->deviceInterfaces->filter(
                        fn ($device_interface) => $device_interface->pivot->status !== 'COMPLETED',
                    );
                } else {
                    $lag_interfaces = $this->getConfigureAllInterfacesForType($task->devices, 'CONFIGURE_LAG_INTERFACE');
                    $task = $this->attach_interfaces($task, $lag_interfaces);
                    $in_progress = $task->deviceInterfaces->filter(
                        fn ($device_interface) => $device_interface->pivot->status !== 'COMPLETED',
                    );
                }
                $jobs[] = $in_progress->map(fn ($lag_interface) => new ConfigureLagInterfaceJob($lag_interface, $task, $centralAPIHelper))->toArray();
                break;
            case 'ASSOCIATE_SITE_AND_NAME':
                $in_progress = $task->devices->filter(fn ($device) => $device->pivot->status !== 'COMPLETED');
                $jobs[] = $in_progress->map(fn ($device) => new AssociateSiteAndNameJob($device, $task, $centralAPIHelper))->toArray();
                break;
            case 'CREATE_NEW_CENTRAL_CX_GROUP':
                $group = $task->vlan_target_device_group;
                if (! is_string($group) || trim($group) === '') {
                    break;
                }
                $jobs[] = [new CreateNewCentralCXGroup(trim($group), $task, $centralAPIHelper)];
                break;
            case 'ADD_VLANS_FOR_DEVICE_GROUP':
                $group = $task->vlan_target_device_group;
                if (! is_string($group) || trim($group) === '') {
                    break;
                }
                $group = trim($group);
                $vlans = $this->resolveVlansForDeviceGroupName($group);
                $prereqId = $task->central_group_creation_task_id;
                if ($prereqId !== null) {
                    $prereq = Task::query()
                        ->where('deployment_id', $task->deployment_id)
                        ->find($prereqId);
                    if ($prereq !== null && $prereq->status !== 'COMPLETED') {
                        $prereqGroup = $prereq->vlan_target_device_group;
                        if (is_string($prereqGroup) && trim($prereqGroup) !== '') {
                            $jobs[] = [new CreateNewCentralCXGroup(trim($prereqGroup), $prereq, $centralAPIHelper)];
                        }
                    }
                }
                $jobs[] = [new AddVlansToDeviceGroup($group, $vlans, $task, $centralAPIHelper)];
                break;
            case 'ASSIGN_SUBSCRIPTION':
            case 'UNASSIGN_SUBSCRIPTION':
                $greenLakeAPIHelper = new GreenLakeAPIHelper($task->deployment->client);
                $inventoryBySerial = collect(
                    $this->licensingInventoryDevicesForTask($task),
                )->keyBy('serial');
                $in_progress = $task->devices->filter(fn ($device) => $device->pivot->status !== 'COMPLETED');
                $isAssign = $task->task_type === 'ASSIGN_SUBSCRIPTION';
                if ($isAssign) {
                    $devices_by_subscription = $in_progress->groupBy(function ($device) use ($task): string {
                        $subscriptionId = trim((string) ($device->pivot->licensing_service_name ?? ''));
                        if ($subscriptionId === '') {
                            $subscriptionId = trim((string) ($task->licensing_service_name ?? ''));
                        }

                        return $subscriptionId;
                    })->filter(fn ($group, string $subscriptionId) => $subscriptionId !== '');
                    $subscriptionJobs = [];
                    foreach ($devices_by_subscription as $subscriptionId => $subscriptionDevices) {
                        foreach ($subscriptionDevices->chunk(25) as $deviceChunk) {
                            $subscriptionJobs[] = new AssignSubscriptionJob(
                                $deviceChunk->map(fn ($device) => [
                                    'id' => $device->id,
                                    'serial' => $device->serial,
                                    'greenlake_device_id' => (string) ($inventoryBySerial->get($device->serial)['greenlake_device_id'] ?? ''),
                                ])->all(),
                                (string) $subscriptionId,
                                $task,
                                $greenLakeAPIHelper,
                            );
                        }
                    }
                    if ($subscriptionJobs !== []) {
                        $jobs[] = $subscriptionJobs;
                    }
                } else {
                    $devices_by_subscription = $in_progress->groupBy(function ($device): string {
                        return trim((string) ($device->pivot->licensing_service_name ?? ''));
                    })->filter(fn ($group, string $subscriptionId) => $subscriptionId !== '');
                    $subscriptionJobs = [];
                    foreach ($devices_by_subscription as $subscriptionDevices) {
                        foreach ($subscriptionDevices->chunk(25) as $deviceChunk) {
                            $subscriptionJobs[] = new UnassignSubscriptionJob(
                                $deviceChunk->map(fn ($device) => [
                                    'id' => $device->id,
                                    'serial' => $device->serial,
                                    'greenlake_device_id' => (string) ($inventoryBySerial->get($device->serial)['greenlake_device_id'] ?? ''),
                                ])->all(),
                                $task,
                                $greenLakeAPIHelper,
                            );
                        }
                    }
                    if ($subscriptionJobs !== []) {
                        $jobs[] = $subscriptionJobs;
                    }
                }
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

    protected function devicesForLocalOverrideRemoval(Task $task): Collection
    {
        $inProgress = $task->devices->filter(fn ($device) => $device->pivot->status !== 'COMPLETED');
        $scope = $task->override_device_scope ?? 'vsf_only';

        if ($scope === 'all') {
            return $inProgress;
        }

        return $inProgress->filter(fn ($device) => $device->sku);
    }

    /**
     * @param  array<string, array<string, mixed>>  $subscriptionsByKey
     */
    protected function resolveGreenlakeSubscriptionId(array $subscriptionsByKey, string $subscriptionKey): ?string
    {
        $subscription = $subscriptionsByKey[$subscriptionKey] ?? null;
        if (! is_array($subscription)) {
            return null;
        }

        $id = trim((string) ($subscription['greenlake_subscription_id'] ?? ''));

        return $id !== '' ? $id : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function licensingInventoryDevicesForTask(Task $task): array
    {
        $task->loadMissing('deployment.client');

        return app(LicensingInventoryService::class)
            ->buildFromCache($task->deployment->client, [])['devices'];
    }
}
