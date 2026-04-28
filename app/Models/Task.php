<?php

namespace App\Models;

use App\TaskJobQueue;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Task extends Model
{
    /** @use HasFactory<\Database\Factories\TaskFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'task_type',
        'deployment_time',
        'batch_id',
        'status',
        'composite_group_id',
        'composite_kind',
        'composite_order',
        'job_queue',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'job_queue' => TaskJobQueue::class,
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(Device::class)->withPivot('status')->withTimestamps();
    }

    public function deviceInterfaces(): BelongsToMany
    {
        return $this->belongsToMany(DeviceInterface::class)->withPivot('status')->withTimestamps();
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
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
            'UPDATE_SYSTEM_INFO',
            'MOVE_DEVICE_TO_GROUP',
            'REMOVE_VSF_PROFILE_LOCAL_OVERRIDES',
            'REMOVE_LOCAL_OVERRIDE_VLANS',
            'REMOVE_LOCAL_OVERRIDE_DNS_PROFILE',
            'REMOVE_LOCAL_OVERRIDE_NTP_PROFILE',
            'REMOVE_LOCAL_OVERRIDE_STATIC_ROUTE',
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
            case 'CONFIGURE_ETHERNET_INTERFACE':
                return 'Configure Ethernet Interfaces';
            case 'CONFIGURE_LAG_INTERFACE':
                return 'Configure Portchannel/LAG interface';
            case 'CONFIGURE_VLAN_INTERFACE':
                return 'Configure SVI';
            case 'CREATE_VSF_PROFILE':
                return 'Create VSF Profile';
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
            case 'TEST_TASK':
                return 'Test Task';
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
            case 'CONFIGURE_ETHERNET_INTERFACE':
                return 'Configure physical interfaces';
            case 'CONFIGURE_LAG_INTERFACE':
                return 'Configure aggregate interfaces';
            case 'CONFIGURE_VLAN_INTERFACE':
                return 'Configure L3 VLAN interfaces';
            case 'ASSOCIATE_DEVICE_TO_SITE':
                return 'Associate devices to a site';
            case 'ASSOCIATE_SITE_AND_NAME':
                return 'Associate devices to sites and name them';
            case 'PREPROVISION_DEVICE_TO_GROUP':
                return 'Preprovision devices to a group';
            case 'CREATE_VSF_PROFILE':
                return 'Create autostack VSF Profile';
            case 'REMOVE_VSF_PROFILE_LOCAL_OVERRIDES':
                return 'Remove VLANs, DNS, NTP and Static Route Overrides introduced by the VSF onboarding';
            case 'REMOVE_LOCAL_OVERRIDE_VLANS':
                return 'Remove VLAN overrides from devices';
            case 'REMOVE_LOCAL_OVERRIDE_DNS_PROFILE':
                return 'Remove DNS profile overrides from devices';
            case 'REMOVE_LOCAL_OVERRIDE_NTP_PROFILE':
                return 'Remove NTP profile overrides from devices';
            case 'REMOVE_LOCAL_OVERRIDE_STATIC_ROUTE':
                return 'Remove static route overrides from devices';
            case 'MOVE_DEVICE_TO_GROUP':
                return 'Move devices to a device group';
            case 'ASSIGN_DEVICE_FUNCTION':
                return 'Assign device function to devices';
            case 'TEST_TASK':
                return 'Test Task';
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
            case 'CREATE_VSF_PROFILE':
                return ['name', 'serial', 'device_function', 'interface', 'sku'];
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
            case 'TEST_TASK':
                return [];
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

        if ($category === 'DEVICE') {
            $total = $this->devices()->count();
            $completed = $this->devices()->wherePivot('status', 'COMPLETED')->count();

            return ['category' => 'DEVICE', 'completed' => $completed, 'total' => $total];
        }

        return ['category' => null, 'completed' => 0, 'total' => 0];
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

    public function effectiveDeploymentMinutes(int $defaultMinutes = 3): int
    {
        return $this->deployment_time !== null && $this->deployment_time > 0
            ? $this->deployment_time
            : $defaultMinutes;
    }

    public function expiresAt(int $defaultMinutes = 3): ?CarbonInterface
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
