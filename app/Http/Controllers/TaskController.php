<?php

namespace App\Http\Controllers;

use App\Events\DeploymentEvent;
use App\Events\DeviceConfigFailedEvent;
use App\Events\DeviceGetScopeIdEvent;
use App\Events\DeviceSystemInfoUpdateEvent;
use App\Events\TestEvent;
use App\Jobs\TestJob;
use App\Jobs\UpdateSystemInfo;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\Task;
use App\TaskType;
use Illuminate\Bus\Batch;
use Illuminate\Http\Request;
use App\Events\DeviceConfigEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use App\Helper\CentralAPIHelper;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class TaskController extends Controller
{
    public function config_system_info(Device $device)
    {
        $central_api_helper = new CentralAPIHelper($device->client);
        if(!$device->scope_id) {
            DeviceGetScopeIdEvent::dispatch($device);
            $scope_id_response = $central_api_helper->getScopeIdFromCentral($device);
            if(!count($scope_id_response) > 0) {
                return response()->json(['error' => ' failed to get scope-id from Central']);
            }
            $device->update(['scope_id' => $scope_id_response[0]['scopeId']]);
        }

        $this->devices()->attach($device);
        $attached_device = $this->devices()->find($device);
        $attached_device->pivot->update(['status' => 'IN_PROGRESS']);

        $response = $central_api_helper->updateSystemInfo($device);

        if(!$response->ok()) {
            DeviceConfigFailedEvent::dispatch($device);
            return response()->json(['error' => 'code:' . $response->status() .' failed to configure system info from central.']);
        }

        DeviceSystemInfoUpdateEvent::dispatch($device);

        $attached_device->pivot->update(['status' => 'COMPLETED']);

        return $response;
    }

    public static function orderInterfaces(Collection $interfaces)
    {
        $portchannels = $interfaces->filter(fn($interface) => str_contains($interface['interface'], 'lag'));
        $ethernets = $interfaces->filter(fn($interface) => !str_contains($interface['interface'], 'lag'));
        $ordered_interfaces = $portchannels->merge($ethernets);
        return $ordered_interfaces;
    }

    public function store(Request $request, Deployment $deployment)
    {
        $validated = $request->validate([
            'task_type' => ['required', Rule::in(array_map(fn($task) => $task->name, TaskType::cases()))],
            'devices' => 'required|array',
        ]);

        $task = $deployment->tasks()->create([
            'task_type' => $validated['task_type'],
            'name' => 'task_for_' . $deployment->name . now(),
        ]);

        $device_collection = Collection::make($validated['devices']);
        $task->devices()->attach($device_collection->pluck('id'));
        $this->dispatchJob($task);
        return back();
    }

    public function dispatchTask(Task $task)
    {
        switch ($task->task_type) {
            case 'TEST_TASK' :
                $task_to_perform = fn ($data) => $this->TestTask($data);
                break;
        }
        $task->devices->each(fn ($device) => $task_to_perform([
            'device_id' => $device->id,
            'message' => 'message '.random_int(1,10),
            'task_type' => $task->task_type,
            'deployment_name' => $task->deployment->name
        ]));
    }

    public function UpdateSystemInfo()
    {

    }

    public function TestTask(array $data)
    {
        TestEvent::dispatch($data);
        DeploymentEvent::dispatch($data);
        sleep(random_int(1,5));
    }

    public function dispatchJob(Task $task)
    {
        $jobs = [];
        switch ($task->task_type) {
            case 'UPDATE_SYSTEM_INFO':
                $jobs = $task->devices->map(fn($device) => new UpdateSystemInfo($device, $task));
                break;
            case 'TEST_TASK':
                $jobs = $task->devices->map(fn($device) => new TestJob(['device_id' => $device->id, 'message' => 'message '.random_int(1,10), 'task_type' => $task->task_type, 'deployment_name' => $task->deployment->name]));
                break;
        }
        $job_batch = Bus::chain(
            $jobs
        )
            ->dispatch();

        return $job_batch;
    }

    public function test()
    {
        $numJobs = request()->input('numJobs') ?? 20;
        $batch = Bus::batch(array_map(fn() => new TestJob('test job '. now()), range(1, $numJobs)))
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
