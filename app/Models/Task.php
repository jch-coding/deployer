<?php

namespace App\Models;

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
    ];

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
        ];

        switch ($task_type) {
            case in_array($task_type, $interface_based):
                return 'INTERFACE';
            case in_array($task_type, $device_based):
                return 'DEVICE';
        }
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
        if ($this->getTaskCategory($this->task_type) == 'INTERFACE') {
            $completed_interfaces = $this->deviceInterfaces()->where('status', 'COMPLETED')->get();

            return count($completed_interfaces) == $this->deviceInterfaces()->count();
        } elseif ($this->getTaskCategory($this->task_type) == 'DEVICE') {
            $completed_devices = $this->devices()->where('status', 'COMPLETED')->get();

            return count($completed_devices) == $this->devices()->count();
        }
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
