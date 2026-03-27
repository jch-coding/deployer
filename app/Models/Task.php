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
        ];

        switch ($task_type) {
            case in_array($task_type, $interface_based):
                return 'INTERFACE';
            case in_array($task_type, $device_based):
                return 'DEVICE';
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
}
