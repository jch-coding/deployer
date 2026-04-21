<?php

namespace App\Http\Controllers;

use App\Models\Deployment;
use App\Models\Task;
use App\TaskType;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DeploymentController extends Controller
{
    public function index(Request $request)
    {
        $currentClient = $request->user()->clients->where('current', true)->first();

        if (! $currentClient) {
            Inertia::flash('error', 'Please set current client to view deployments');

            return to_route('clients.index');
        }

        $deployments = $currentClient->deployments()->withCount('devices')->get();

        return Inertia::render('Deployment/Index', [
            'deployments' => $deployments,
        ]);
    }

    public function show(Request $request, Deployment $deployment)
    {
        $deployment->load('devices');
        $latest_tasks = $deployment->tasks()->withCount('devices')->latest()->take(5)->get()
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
        $items_with_names = $items_obj->map(fn ($group) => array_map(fn ($member) => [...$member, 'name' => $member['interface']], $group));

        return Inertia::render('Deployment/Show', [
            'deployment' => $deployment,
            'devices' => $deployment->devices()->with('interfaces')->paginate(10),
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
        $data = $request->validate([
            'name' => 'required|string|min:3|max:255',
            'description' => 'nullable|string|max:255',
        ]);

        if (Deployment::where('name', $data['name'])->exists()) {
            return redirect()->back()->withErrors(['name' => 'Deployment with this name already exists']);
        }

        $client_id = $request->user()->currentClient()->id;

        Deployment::create([
            ...$data,
            'client_id' => $client_id,
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
}
