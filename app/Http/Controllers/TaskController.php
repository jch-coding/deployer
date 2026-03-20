<?php

namespace App\Http\Controllers;

use App\Events\DeviceConfigFailedEvent;
use App\Events\DeviceGetScopeIdEvent;
use App\Events\DeviceSystemInfoUpdateEvent;
use App\Events\TestEvent;
use App\Helper\CentralAPIHelper;
use App\Jobs\ConfigureEthernetInterface;
use App\Jobs\CreateLocalOverrideForPortProfile;
use App\Jobs\TestJob;
use App\Jobs\UpdateSystemInfo;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\Task;
use App\TaskType;
use Illuminate\Bus\Batch;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class TaskController extends Controller
{
    public $task_to_action = [
        'UPDATE_SYSTEM_INFO' => 'show-system-info',
        'CONFIGURE_ETHERNET_INTERFACE' => 'show-ethernet-interface',
    ];
    public function config_system_info(Device $device)
    {
        $central_api_helper = new CentralAPIHelper($device->client);
        if (! $device->scope_id) {
            DeviceGetScopeIdEvent::dispatch($device);
            $scope_id_response = $central_api_helper->getScopeIdFromCentral($device);
            if (! count($scope_id_response) > 0) {
                return response()->json(['error' => ' failed to get scope-id from Central']);
            }
            $device->update(['scope_id' => $scope_id_response[0]['scopeId']]);
        }

        $this->devices()->attach($device);
        $attached_device = $this->devices()->find($device);
        $attached_device->pivot->update(['status' => 'IN_PROGRESS']);

        $response = $central_api_helper->updateSystemInfo($device);

        if (! $response->ok()) {
            DeviceConfigFailedEvent::dispatch($device);

            return response()->json(['error' => 'code:'.$response->status().' failed to configure system info from central.']);
        }

        DeviceSystemInfoUpdateEvent::dispatch($device);

        $attached_device->pivot->update(['status' => 'COMPLETED']);

        return $response;
    }

    public static function orderInterfaces(Collection $interfaces)
    {
        $portchannels = $interfaces->filter(fn ($interface) => str_contains($interface['interface'], 'lag'));
        $ethernets = $interfaces->filter(fn ($interface) => ! str_contains($interface['interface'], 'lag'));
        $ordered_interfaces = $portchannels->merge($ethernets);

        return $ordered_interfaces;
    }

    public function showSystemInfo(Task $task)
    {
        return Inertia::render('Task/SystemInfo', [
            'task' => $task,
            'devices' => $task->devices,
            'deployment' => $task->deployment,
        ]);
    }

    public function showEthernetInterface(Task $task)
    {
        return Inertia::render('Task/EthernetInterface', [
            'task' => $task,
            'devices' => $task->devices->load('interfaces'),
            'deployment' => $task->deployment,
            'interfaces' => $task->deviceInterfaces()->withPivot('status')->get(),
        ]);
    }

    public function store(Request $request, Deployment $deployment)
    {
        $validated = $request->validate([
            'task_type' => ['required', Rule::in(array_map(fn ($task) => $task->name, TaskType::cases()))],
            'devices' => 'required|array',
            'deployment_time' => 'required|integer',
        ]);

        $task = $deployment->tasks()->create([
            'task_type' => $validated['task_type'],
            'name' => 'task_for_'.$deployment->name.now(),
            'deployment_time' => $validated['deployment_time'],
            'status' => 'IN_PROGRESS',
        ]);

        $device_collection = Collection::make($validated['devices']);
        $task->devices()->attach($device_collection->pluck('id'));
        $device_interfaces = $task->devices->map(fn ($device) => $device->interfaces)->collapse()->pluck('id');
        $task->deviceInterfaces()->attach($device_interfaces);
        $batch = $this->dispatchJob($task);
        $task->update(['batch_id' => $batch]);

        return to_route('tasks.'.$this->task_to_action[$validated['task_type']], $task);
    }

    public function cancel(Task $task)
    {
        $task->update(['status' => 'CANCELLED']);
        //queue clear is not 100% successful on first try
        $queue_cleared = false;
        $tries = 10;
        while (! $queue_cleared && $tries > 0) {
            Artisan::call('queue:clear');
            if (str_contains(Artisan::output(), 'Cleared 0 jobs')) {
                $queue_cleared = true;
                $tries -= 1;
                sleep(random_int(1,6));
            }
        }

        return back()->with(['success' => 'Task is cancelled']);
    }

    public function dispatchJob(Task $task)
    {
        $centralAPIHelper = new CentralAPIHelper($task->deployment->client);
        $jobs = [];
        switch ($task->task_type) {
            case 'UPDATE_SYSTEM_INFO':
                $jobs[] = $task->devices->map(fn ($device) => new UpdateSystemInfo($device, $task, $centralAPIHelper))->toArray();
                break;
            case 'CONFIGURE_ETHERNET_INTERFACE':
                $devices_with_port_profiles = $task->devices->map(function ($device) {
                    $device->interfaces_sw_profiles = $device->interfaces->filter(fn ($interface) => $interface->sw_profile);

                    return $device;
                });
                $unique_interfaces_sw_profiles = static::get_unique_sw_profiles($devices_with_port_profiles);
                if (count($unique_interfaces_sw_profiles) > 0) {
                    $jobs[] = $unique_interfaces_sw_profiles->map(fn ($unique_interface_sw_profiles) => new CreateLocalOverrideForPortProfile(
                            [
                                'sw_profile' => $unique_interface_sw_profiles->sw_profile,
                                'device_function' => $unique_interface_sw_profiles->device->device_function,
                                'site' => $unique_interface_sw_profiles->device->site,
                            ],
                            $task,
                            $centralAPIHelper
                        )
                    )->toArray();
                }
                $jobs[] = $task->devices->map(fn ($device) => $device->interfaces->map(fn ($interface) => new ConfigureEthernetInterface($interface, $task, $centralAPIHelper)))->collapse()->toArray();
                break;
            case 'TEST_TASK':
                $jobs[] = $task->devices->map(fn ($device) => new TestJob([
                    'device_name' => $device->id,
                    'task_id' => $task->id,
                    'message' => 'message '.random_int(1, 10),
                    'task_type' => $task->task_type,
                    'deployment_name' => $task->deployment->name,
                ]))->toArray();
                break;
        }
        return Bus::chain(array_map(fn($j) => Bus::batch($j), $jobs))->dispatch();
    }

    public static function get_unique_sw_profiles(Collection $devices)
    {
        return $devices->map(fn ($device) => $device->interfaces_sw_profiles->unique('sw_profile'))->collapse()->unique('sw_profile');
    }

    public function test()
    {
        $numJobs = request()->input('numJobs') ?? 20;
        $batch = Bus::batch(array_map(fn () => new TestJob('test job '.now()), range(1, $numJobs)))
            ->progress(function (Batch $batch) {
                TestEvent::dispatch($batch->progress());
            })
            ->finally(function (Batch $batch) {
                TestEvent::dispatch($batch->processedJobs().' jobs');
            })
            ->dispatch();

        return back()->with(['job_batch_id' => $batch->id, 'message' => 'dispatched']);
    }
}
