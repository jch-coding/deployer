<?php

namespace App\Http\Controllers;

use App\Models\Deployment;
use App\TaskType;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DeploymentController extends Controller
{
    public function index(Request $request)
    {
        $currentClient= $request->user()->clients->where('current', true)->first();

        if(!$currentClient) {
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
                    if ($task->status !== 'COMPLETED' && count($task->devices->filter(fn($device) => $device->pivot->status === 'COMPLETED')) == $task->devices_count) {
                        $task->status = 'COMPLETED';
                        $task->save();
                    }
                    return $task;
            })
            ->map(function ($task) {
                $task->human_created_at = Carbon::parse($task->created_at)->diffForHumans();
                $task->human_updated_at = Carbon::parse($task->updated_at)->diffForHumans();
                return $task;
            });
        return Inertia::render('Deployment/Show', [
            'deployment' => $deployment,
            'devices' => $deployment->devices()->paginate(10),
            'tasks' => array_map(fn($task) => $task->name, TaskType::cases()),
            'latest_tasks' => $latest_tasks,
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
            'client_id' => $client_id
        ]);

        return redirect()->route('deployments.index');
    }

    public function destroy(Request $request, Deployment $deployment)
    {
        if($request->user()->cannot('delete', $deployment)) {
            abort(403);
        }
        $deployment->delete();
        return redirect()->route('deployments.index');
    }
}
