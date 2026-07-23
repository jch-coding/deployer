<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Task extends Model
{
    public const DEFAULT_DEPLOYMENT_MINUTES = 10;

    /** @use HasFactory<\Database\Factories\TaskFactory> */
    use HasFactory;

    protected $casts = [
        'remediation_context' => 'array',
        'greenlake_tags' => 'array',
        'central_static_tags' => 'array',
        'mirror_fallback_mode' => 'boolean',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(Device::class)
            ->using(DeviceTask::class)
            ->withPivot(
                'status',
                'greenlake_step_statuses',
                'licensing_service_name',
                'license_tag',
                'license_type',
            )
            ->withTimestamps();
    }

    /**
     * Applicable GreenLake post-add steps for ADD_DEVICES_TO_GREENLAKE_INVENTORY.
     *
     * @return list<string>
     */
    public function applicableGreenLakeSteps(): array
    {
        if ($this->task_type !== 'ADD_DEVICES_TO_GREENLAKE_INVENTORY') {
            return [];
        }

        $steps = ['inventory'];

        $tags = $this->greenlake_tags;
        if (is_array($tags) && $tags !== []) {
            $steps[] = 'tags';
        }

        if (trim((string) ($this->greenlake_location_id ?? '')) !== '') {
            $steps[] = 'location';
        }

        return $steps;
    }

    /**
     * Initial PENDING map for applicable GreenLake steps.
     *
     * @return array<string, string>
     */
    public function initialGreenLakeStepStatuses(): array
    {
        $statuses = [];
        foreach ($this->applicableGreenLakeSteps() as $step) {
            $statuses[$step] = 'PENDING';
        }

        return $statuses;
    }

    public function deviceInterfaces(): BelongsToMany
    {
        return $this->belongsToMany(DeviceInterface::class)->withPivot('status')->withTimestamps();
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    public function centralGroupCreationTask(): BelongsTo
    {
        return $this->belongsTo(self::class, 'central_group_creation_task_id');
    }

    public function vlanTasksAfterCentralGroupCreation(): HasMany
    {
        return $this->hasMany(self::class, 'central_group_creation_task_id');
    }

    /**
     * Set all non-completed device/interface pivot rows back to PENDING (e.g. before relaunch or force restart).
     */
    public function resetIncompletePivotRowsToPending(): void
    {
        $now = now();

        DB::table('device_task')
            ->where('task_id', $this->id)
            ->where('status', '!=', 'COMPLETED')
            ->update(['status' => 'PENDING', 'updated_at' => $now]);

        DB::table('device_interface_task')
            ->where('task_id', $this->id)
            ->where('status', '!=', 'COMPLETED')
            ->update(['status' => 'PENDING', 'updated_at' => $now]);
    }

    public static function supportsCentralCheck(string $taskType): bool
    {
        return in_array($taskType, [
            'CONFIGURE_LAG_INTERFACE',
            'CONFIGURE_ETHERNET_INTERFACE',
            'CONFIGURE_VLAN_INTERFACE',
            'ASSOCIATE_DEVICE_TO_SITE',
            'ASSOCIATE_SITE_AND_NAME',
            'UPDATE_SYSTEM_INFO',
        ], true);
    }

    public static function supportsRemediationCentralCheck(?string $compositeKind): bool
    {
        return $compositeKind === 'RELAUNCH_FAILED_CRITICAL_CONFIG';
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Task>|null  $siblings
     */
    public static function compositeCanRunRemediationCheck(?string $compositeKind, $siblings = null): bool
    {
        if (! self::supportsRemediationCentralCheck($compositeKind) || $siblings === null) {
            return false;
        }

        return $siblings->every(fn (Task $t) => $t->status === 'COMPLETED');
    }

    public function getTaskCategory($task_type)
    {
        $interface_based = [
            'CONFIGURE_ETHERNET_INTERFACE',
            'CONFIGURE_LAG_INTERFACE',
            'CONFIGURE_ALL_INTERFACE',
            'CONFIGURE_VLAN_INTERFACE',
        ];

        $device_based = [
            'PREPROVISION_DEVICE_TO_GROUP',
            'ASSIGN_DEVICE_FUNCTION',
            'ASSOCIATE_DEVICE_TO_SITE',
            'ASSOCIATE_SITE_AND_NAME',
            'CREATE_VSF_PROFILE',
            'CREATE_VSX_PROFILE',
            'UPDATE_SYSTEM_INFO',
            'MOVE_DEVICE_TO_GROUP',
            'REMOVE_VSF_PROFILE_LOCAL_OVERRIDES',
            'REMOVE_LOCAL_OVERRIDE_VLANS',
            'REMOVE_LOCAL_OVERRIDE_DNS_PROFILE',
            'REMOVE_LOCAL_OVERRIDE_NTP_PROFILE',
            'REMOVE_LOCAL_OVERRIDE_STATIC_ROUTE',
            'REMOVE_LOCAL_OVERRIDE_LOCAL_MANAGEMENT_PROFILE',
            'ASSIGN_SUBSCRIPTION',
            'UNASSIGN_SUBSCRIPTION',
            'ADD_DEVICES_TO_GREENLAKE_INVENTORY',
            'ADD_TAGS_TO_GREENLAKE_DEVICES',
            'ADD_LOCATION_TO_GREENLAKE_DEVICES',
            'EXPORT_MAC_ADDRESSES_TO_CENTRAL',
            'ADD_VLANS_FOR_DEVICE_GROUP',
            'CREATE_NEW_CENTRAL_CX_GROUP',
            'CONFIGURE_MIRROR_SESSION',
        ];

        if (in_array($task_type, $interface_based, true)) {
            return 'INTERFACE';
        }
        if (in_array($task_type, $device_based, true)) {
            return 'DEVICE';
        }

        return null;
    }

    public static function getTaskFriendlyName($task_type): string
    {
        switch ($task_type) {
            case 'UPDATE_SYSTEM_INFO':
                return 'Name Devices';
            case 'CONFIGURE_ALL_INTERFACE':
                return 'Configure LAG, Ethernet and VLAN Interfaces';
            case 'RELAUNCH_FAILED_CRITICAL_CONFIG':
                return 'Relaunch failed critical configurations';
            case 'CONFIGURE_ETHERNET_INTERFACE':
                return 'Configure Ethernet Interfaces';
            case 'CONFIGURE_LAG_INTERFACE':
                return 'Configure Portchannel/LAG interface';
            case 'CONFIGURE_VLAN_INTERFACE':
                return 'Configure SVI';
            case 'CONFIGURE_MIRROR_SESSION':
                return 'Configure Mirror Session';
            case 'CREATE_VSF_PROFILE':
                return 'Create VSF Profile';
            case 'CREATE_VSX_PROFILE':
                return 'Create VSX Profile';
            case 'REMOVE_VSF_PROFILE_LOCAL_OVERRIDES':
                return 'Remove VSF profile local overrides';
            case 'REMOVE_LOCAL_OVERRIDE_VLANS':
                return 'Remove local VLAN overrides';
            case 'REMOVE_LOCAL_OVERRIDE_DNS_PROFILE':
                return 'Remove local DNS overrides';
            case 'REMOVE_LOCAL_OVERRIDE_NTP_PROFILE':
                return 'Remove local NTP overrides';
            case 'REMOVE_LOCAL_OVERRIDE_STATIC_ROUTE':
                return 'Remove local static route overrides';
            case 'REMOVE_LOCAL_OVERRIDE_LOCAL_MANAGEMENT_PROFILE':
                return 'Remove local management profile overrides';
            case 'ASSOCIATE_DEVICE_TO_SITE':
                return 'Associate Devices to Site';
            case 'ASSOCIATE_SITE_AND_NAME':
                return 'Associate Devices to Site and Name';
            case 'PREPROVISION_DEVICE_TO_GROUP':
                return 'Preprovision Devices to Group';
            case 'MOVE_DEVICE_TO_GROUP':
                return 'Move Devices to Device Group';
            case 'ASSIGN_DEVICE_FUNCTION':
                return 'Assign Device Function to Devices';
            case 'ADD_VLANS_TO_DEVICE_GROUP':
                return 'Add VLANs to device groups';
            case 'ASSIGN_SUBSCRIPTION':
                return 'Assign Subscription';
            case 'UNASSIGN_SUBSCRIPTION':
                return 'Unassign Subscription';
            case 'ADD_DEVICES_TO_GREENLAKE_INVENTORY':
                return 'Add Devices to GreenLake Inventory';
            case 'ADD_TAGS_TO_GREENLAKE_DEVICES':
                return 'Add Tags to GreenLake Devices';
            case 'ADD_LOCATION_TO_GREENLAKE_DEVICES':
                return 'Add Location to GreenLake Devices';
            case 'EXPORT_MAC_ADDRESSES_TO_CENTRAL':
                return 'Export MAC Addresses to Central';
            case 'ADD_VLANS_FOR_DEVICE_GROUP':
                return 'Add VLANs to device group (single group)';
            case 'CREATE_NEW_CENTRAL_CX_GROUP':
                return 'Create Central CX device group';
            default:
                return 'Unknown Task';
        }
    }

    public static function getTaskFriendlyDescription($task_type): string
    {
        switch ($task_type) {
            case 'UPDATE_SYSTEM_INFO':
                return 'Name or rename devices';
            case 'CONFIGURE_ALL_INTERFACE':
                return 'Configure LAG, physical and SVIs in that order.';
            case 'RELAUNCH_FAILED_CRITICAL_CONFIG':
                return 'Retry failed LAG, ethernet, and VLAN interfaces and remove local overrides for static route and DNS profiles.';
            case 'CONFIGURE_ETHERNET_INTERFACE':
                return 'Configure physical interfaces';
            case 'CONFIGURE_LAG_INTERFACE':
                return 'Configure aggregate interfaces';
            case 'CONFIGURE_VLAN_INTERFACE':
                return 'Configure L3 VLAN interfaces';
            case 'CONFIGURE_MIRROR_SESSION':
                return 'Create a local mirror session on selected switches for Darktrace SPAN. Uses name-pattern defaults when no mirror columns are set in the deployment; otherwise uses mirror_* CSV values.';
            case 'ASSOCIATE_DEVICE_TO_SITE':
                return 'Associate devices to a site';
            case 'ASSOCIATE_SITE_AND_NAME':
                return 'Associate devices to sites and name them';
            case 'PREPROVISION_DEVICE_TO_GROUP':
                return 'Preprovision devices to a group';
            case 'CREATE_VSF_PROFILE':
                return 'Create autostack VSF Profile';
            case 'CREATE_VSX_PROFILE':
                return 'Create VSX profile for a switch pair with LAG 256/255 prerequisites';
            case 'REMOVE_VSF_PROFILE_LOCAL_OVERRIDES':
                return 'Remove VLANs, DNS, NTP, static route, and local management profile overrides introduced by the VSF onboarding';
            case 'REMOVE_LOCAL_OVERRIDE_VLANS':
                return 'Remove VLAN overrides from devices';
            case 'REMOVE_LOCAL_OVERRIDE_DNS_PROFILE':
                return 'Remove DNS profile overrides from devices';
            case 'REMOVE_LOCAL_OVERRIDE_NTP_PROFILE':
                return 'Remove NTP profile overrides from devices';
            case 'REMOVE_LOCAL_OVERRIDE_STATIC_ROUTE':
                return 'Remove static route overrides from devices';
            case 'REMOVE_LOCAL_OVERRIDE_LOCAL_MANAGEMENT_PROFILE':
                return 'Remove local management profile overrides from devices';
            case 'MOVE_DEVICE_TO_GROUP':
                return 'Move devices to a device group';
            case 'ASSIGN_DEVICE_FUNCTION':
                return 'Assign device function to devices';
            case 'ADD_VLANS_TO_DEVICE_GROUP':
                return 'Add VLAN templates to Central device groups by group name, or use a site prefix to target WHSE-{prefix}-ACCESS/CORE/MGMT/DMZ/SERVER.';
            case 'ASSIGN_SUBSCRIPTION':
                return 'Assign licenses from a tag and license type pool to selected devices.';
            case 'UNASSIGN_SUBSCRIPTION':
                return 'Remove assigned licenses from selected devices.';
            case 'ADD_DEVICES_TO_GREENLAKE_INVENTORY':
                return 'Add selected network devices to the HPE GreenLake workspace inventory using serial and MAC address. Optionally apply the same key–value tags and assign an existing GreenLake location to every selected device.';
            case 'ADD_TAGS_TO_GREENLAKE_DEVICES':
                return 'Add or update the same key–value tags on selected devices that are already in the HPE GreenLake workspace inventory.';
            case 'ADD_LOCATION_TO_GREENLAKE_DEVICES':
                return 'Assign an existing GreenLake location to selected devices that are already in the HPE GreenLake workspace inventory.';
            case 'EXPORT_MAC_ADDRESSES_TO_CENTRAL':
                return 'Import selected device MAC addresses into Central NAC MAC Registration. Optionally apply the same static tags to every selected device.';
            case 'ADD_VLANS_FOR_DEVICE_GROUP':
                return 'Adds VLAN definitions to one Central device group.';
            case 'CREATE_NEW_CENTRAL_CX_GROUP':
                return 'Creates a new AOS-CX switch group in Aruba Central (Classic).';
            default:
                return 'Unknown Task';
        }
    }

    public static function getTaskRequiredColumns($task_type): array
    {
        switch ($task_type) {
            case 'UPDATE_SYSTEM_INFO':
                return ['name', 'serial', 'device_function'];
            case 'CONFIGURE_ALL_INTERFACE':
                return ['name', 'serial', 'device_function', 'interface', 'ip_address'];
            case 'CONFIGURE_ETHERNET_INTERFACE':
                return ['name', 'serial', 'device_function', 'interface'];
            case 'CONFIGURE_LAG_INTERFACE':
                return ['name', 'serial', 'device_function', 'interface'];
            case 'CONFIGURE_VLAN_INTERFACE':
                return ['name', 'serial', 'device_function', 'interface', 'ip_address'];
            case 'CONFIGURE_MIRROR_SESSION':
                return ['name', 'serial', 'device_function'];
            case 'CREATE_VSF_PROFILE':
                return ['name', 'serial', 'device_function', 'interface', 'sku'];
            case 'CREATE_VSX_PROFILE':
                return ['name', 'serial', 'device_function', 'group', 'site', 'vsx_profile', 'vsx_role', 'vsx_system_mac'];
            case 'REMOVE_VSF_PROFILE_LOCAL_OVERRIDES':
                return ['name', 'serial', 'device_function'];
            case 'ASSOCIATE_DEVICE_TO_SITE':
                return ['name', 'serial', 'device_function', 'site'];
            case 'ASSOCIATE_SITE_AND_NAME':
                return ['name', 'serial', 'device_function', 'site', 'name'];
            case 'PREPROVISION_DEVICE_TO_GROUP':
                return ['name', 'serial', 'device_function', 'group'];
            case 'MOVE_DEVICE_TO_GROUP':
                return ['name', 'serial', 'device_function', 'group'];
            case 'ASSIGN_DEVICE_FUNCTION':
                return ['name', 'serial', 'device_function'];
            case 'ADD_VLANS_TO_DEVICE_GROUP':
                return ['group'];
            case 'ADD_VLANS_FOR_DEVICE_GROUP':
                return ['group'];
            case 'CREATE_NEW_CENTRAL_CX_GROUP':
                return ['group'];
            case 'ASSIGN_SUBSCRIPTION':
            case 'UNASSIGN_SUBSCRIPTION':
                return ['name', 'serial', 'device_function'];
            case 'ADD_DEVICES_TO_GREENLAKE_INVENTORY':
                return ['name', 'serial', 'device_function', 'mac_address'];
            case 'EXPORT_MAC_ADDRESSES_TO_CENTRAL':
                return ['mac_address'];
            case 'ADD_TAGS_TO_GREENLAKE_DEVICES':
            case 'ADD_LOCATION_TO_GREENLAKE_DEVICES':
                return ['name', 'serial', 'device_function'];
            default:
                return [];
        }
    }

    public function processTaskStatus()
    {
        return $this->allTrackedItemsCompleted();
    }

    public function trackedItemTotals(): array
    {
        $category = $this->getTaskCategory($this->task_type);

        if ($category === 'INTERFACE') {
            $total = $this->deviceInterfaces()->count();
            $completed = $this->deviceInterfaces()->wherePivot('status', 'COMPLETED')->count();

            return ['category' => 'INTERFACE', 'completed' => $completed, 'total' => $total];
        }

        if ($this->task_type === 'ADD_DEVICES_TO_GREENLAKE_INVENTORY') {
            return $this->greenLakeStepTrackedItemTotals();
        }

        if ($category === 'DEVICE') {
            $total = $this->devices()->count();
            $completed = $this->devices()->wherePivot('status', 'COMPLETED')->count();

            return ['category' => 'DEVICE', 'completed' => $completed, 'total' => $total];
        }

        return ['category' => null, 'completed' => 0, 'total' => 0];
    }

    /**
     * Progress units are per-device steps (inventory, optional tags, optional location).
     *
     * @return array{category: string, completed: int, total: int}
     */
    public function greenLakeStepTrackedItemTotals(): array
    {
        $steps = $this->applicableGreenLakeSteps();
        $stepCount = count($steps);
        $devices = $this->devices()->get();
        $deviceCount = $devices->count();
        $total = $deviceCount * $stepCount;
        $completed = 0;

        foreach ($devices as $device) {
            $statuses = $device->pivot->greenlake_step_statuses ?? [];
            if (! is_array($statuses)) {
                $statuses = [];
            }

            foreach ($steps as $step) {
                if (($statuses[$step] ?? null) === 'COMPLETED') {
                    $completed++;
                }
            }
        }

        return ['category' => 'DEVICE', 'completed' => $completed, 'total' => $total];
    }

    public function allTrackedItemsCompleted(): bool
    {
        $totals = $this->trackedItemTotals();
        $total = $totals['total'];

        if ($total === 0) {
            return false;
        }

        return $totals['completed'] === $total;
    }

    public function allTrackedItemsFailed(): bool
    {
        $category = $this->getTaskCategory($this->task_type);

        if ($category === 'INTERFACE') {
            $total = $this->deviceInterfaces()->count();
            if ($total === 0) {
                return false;
            }
            $failed = $this->deviceInterfaces()->wherePivot('status', 'FAILED')->count();

            return $failed === $total;
        }

        if ($category === 'DEVICE') {
            $total = $this->devices()->count();
            if ($total === 0) {
                return false;
            }
            $failed = $this->devices()->wherePivot('status', 'FAILED')->count();

            return $failed === $total;
        }

        return false;
    }

    public function effectiveDeploymentMinutes(int $defaultMinutes = self::DEFAULT_DEPLOYMENT_MINUTES): int
    {
        return $this->deployment_time !== null && $this->deployment_time > 0
            ? $this->deployment_time
            : $defaultMinutes;
    }

    public function expiresAt(int $defaultMinutes = self::DEFAULT_DEPLOYMENT_MINUTES): ?CarbonInterface
    {
        if ($this->created_at === null) {
            return null;
        }

        return $this->created_at->copy()->addMinutes($this->effectiveDeploymentMinutes($defaultMinutes));
    }

    public function processTaskStatusLog($message, $withTimeStamp = false)
    {
        $status_log = $this->status_log;
        $new_log = $status_log.'\n'.$message;
        if ($withTimeStamp) {
            $new_log = $new_log.'\n'.now()->addMinutes($this->wait_time)->setTimeZone('America/New_York')->format('Y-m-d H:i:s T');
        }
        $this->update(['status_log' => $new_log]);
    }
}
