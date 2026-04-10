<?php

namespace App\Http\Controllers;

use App\Events\TestEvent;
use App\Helper\CentralAPIHelper;
use App\Jobs\AssignDeviceFunctionJob;
use App\Jobs\AssociateDeviceToSiteJob;
use App\Jobs\AssociateSiteAndNameJob;
use App\Jobs\ConfigureEthernetInterface;
use App\Jobs\ConfigureLagInterfaceJob;
use App\Jobs\ConfigureVlanInterfaceJob;
use App\Jobs\CreateVSFProfileJob;
use App\Jobs\MoveDevicesToGroupJob;
use App\Jobs\PreprovisionDevicesToGroupJob;
use App\Jobs\TestJob;
use App\Jobs\UpdateSystemInfo;
use App\Models\Deployment;
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
        'TEST_TASK' => [],
    ];

    public function show(Task $task)
    {
        $isDeviceBasedTask = $task->getTaskCategory($task->task_type) === 'DEVICE';
        $inertia_component = $isDeviceBasedTask ? 'Task/DeviceTask' : 'Task/InterfaceTask';

        return Inertia::render($inertia_component, [
            'task' => $task,
            'task_friendly_name' => Task::getTaskFriendlyName($task->task_type),
            'task_friendly_description' => Task::getTaskFriendlyDescription($task->task_type),
            'devices' => $task->devices,
            'interfaces' => $isDeviceBasedTask ? [] : $task->deviceInterfaces()->withPivot('status')->get(),
            'deployment' => $task->deployment,
            'display_columns' => $this->display_columns[$task->task_type],
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
        $batch = $this->dispatchJob($task);
        $task->update(['batch_id' => $batch]);

        return to_route('tasks.show', $task);
    }

    public function destroy(Task $task)
    {
        $task->devices()->detach();
        $task->delete();
        return back();
    }

    public function force_restart(Task $task)
    {
        $this->cancel($task);
        $task->update(['status' => 'IN_PROGRESS']);
        $batch = $this->dispatchJob($task);
        $task->update(['batch_id' => $batch]);

        return to_route('tasks.show', $task);
    }

    public function cancel(Task $task)
    {
        $task->update(['status' => 'CANCELLED']);
        // queue clear is not 100% successful on first try
        $queue_cleared = false;
        $tries = 3;
        while (! $queue_cleared && $tries > 0) {
            Artisan::call('queue:clear');
            if (str_contains(Artisan::output(), 'Cleared 0 jobs')) {
                $queue_cleared = true;
                $tries -= 1;
                sleep(random_int(1, 6));
            }
        }
        Inertia::flash('success', 'Task cancelled successfully.');
        return back();
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

    public function dispatchJob(Task $task)
    {
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
                $jobs = array_merge($jobs, $devices_by_group_jobs);
                break;
            case 'MOVE_DEVICE_TO_GROUP':
                $in_progress = $task->devices->filter(fn ($device) => $device->pivot->status !== 'COMPLETED');
                $devices_by_group = $in_progress->groupBy('group');
                $chunked_devices_by_group_with_keys = $this->chunk_devices($devices_by_group);
                $devices_by_group_jobs = $this->create_jobs_by_grouped_chunks($chunked_devices_by_group_with_keys, $task, $centralAPIHelper, MoveDevicesToGroupJob::class);
                $jobs = array_merge($jobs, $devices_by_group_jobs);
                break;
            case 'ASSIGN_DEVICE_FUNCTION':
                $in_progress = $task->devices->filter(fn ($device) => $device->pivot->status !== 'COMPLETED');
                $devices_by_device_function = $in_progress->groupBy('device_function');
                $chunked_devices_by_group_with_keys = $this->chunk_devices($devices_by_device_function);
                $devices_by_device_function_jobs = $this->create_jobs_by_grouped_chunks($chunked_devices_by_group_with_keys, $task, $centralAPIHelper, AssignDeviceFunctionJob::class);
                $jobs = array_merge($jobs, $devices_by_device_function_jobs);
                break;
            case 'ASSIGN_DEVICE_TO_SITE':
                $in_progress = $task->devices->filter(fn ($device) => $device->pivot->status !== 'COMPLETED');
                $jobs[] = $in_progress->map(fn ($device) => new AssociateDeviceToSiteJob($device, $task, $centralAPIHelper))->toArray();
                break;
            case 'CREATE_VSF_PROFILE':
                $devices_with_vsf_profile = $task->devices->filter(fn ($device) => $device->sku && $device->pivot->status !== 'COMPLETED');
                $jobs[] = $devices_with_vsf_profile->map(fn ($device) => new CreateVSFProfileJob($device, $task, $centralAPIHelper))->toArray();
                break;
            case 'CONFIGURE_ETHERNET_INTERFACE':
                $devices_with_port_profiles = $task->devices->map(function ($device) {
                    $device->interfaces_sw_profiles = $device->interfaces->filter(fn ($interface) => $interface->sw_profile && str_contains($interface->interface, '/'));

                    return $device;
                });
                $task = $this->attach_interfaces($task, $devices_with_port_profiles->map(fn ($device) => $device->interfaces->filter(fn ($interface) => str_contains($interface->interface, '/')))->collapse());
                $in_progress = $task->deviceInterfaces->filter(fn ($device_interface) => $device_interface->pivot->status !== 'COMPLETED');
                $jobs[] = $in_progress->map(fn ($interface) => new ConfigureEthernetInterface($interface, $task, $centralAPIHelper))->toArray();
                break;
            case 'CONFIGURE_VLAN_INTERFACE':
                $vlan_interfaces = $task->devices->map(fn ($device) => $device->interfaces->filter(fn ($interface) => ! str_contains($interface->interface, '/') && $interface->ip_address !== null))->collapse();
                $task = $this->attach_interfaces($task, $vlan_interfaces);
                $in_progress = $task->deviceInterfaces->filter(fn ($device_interface) => $device_interface->pivot->status !== 'COMPLETED');
                $jobs[] = $in_progress->map(fn ($vlan_interface) => new ConfigureVlanInterfaceJob($vlan_interface, $task, $centralAPIHelper))->toArray();
                break;
            case 'CONFIGURE_LAG_INTERFACE':
                $lag_interfaces = $task->devices->map(fn ($device) => $device->interfaces->filter(fn ($interface) => ! str_contains($interface->interface, '/') && $interface->lacp_profile !== null))->collapse();
                $task = $this->attach_interfaces($task, $lag_interfaces);
                $in_progress = $task->deviceInterfaces->filter(fn ($device_interface) => $device_interface->pivot->status !== 'COMPLETED');
                $jobs[] = $in_progress->map(fn ($lag_interface) => new ConfigureLagInterfaceJob($lag_interface, $task, $centralAPIHelper))->toArray();
                break;
            case 'ASSOCIATE_SITE_AND_NAME':
                $in_progress = $task->devices->filter(fn ($device) => $device->pivot->status !== 'COMPLETED');
                $jobs[] = $in_progress->map(fn ($device) => new AssociateSiteAndNameJob($device, $task, $centralAPIHelper))->toArray();
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

        return Bus::chain(array_map(fn ($j) => Bus::batch($j)->allowFailures(), $jobs))->dispatch();
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
