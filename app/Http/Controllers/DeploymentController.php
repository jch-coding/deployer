<?php

namespace App\Http\Controllers;

use App\Helper\CentralAPIHelper;
use App\Models\Deployment;
use App\Models\Task;
use App\Services\DeploymentCriticalCheckService;
use App\Services\FinalizeExpiredTasksService;
use App\TaskType;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class DeploymentController extends Controller
{
    public function index(Request $request)
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            session()->flash('error', 'Please set current client to view deployments');

            return to_route('clients.index');
        }

        $deployments = $currentClient->deployments()->withCount('devices')->get();

        return Inertia::render('Deployment/Index', [
            'deployments' => $deployments,
        ]);
    }

    public function show(Request $request, Deployment $deployment, FinalizeExpiredTasksService $finalizeExpiredTasks)
    {
        $deployment->load('devices');

        $finalizeExpiredTasks->run((int) $deployment->id);

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

        $rawSearch = $request->query('search');
        $search = is_string($rawSearch) ? mb_substr(trim($rawSearch), 0, 255) : '';

        $devicesQuery = $deployment->devices()->with('interfaces');
        if ($search !== '') {
            $pattern = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';
            $devicesQuery->where(function ($query) use ($pattern) {
                $query->whereRaw('lower(name) LIKE ?', [$pattern])
                    ->orWhereRaw('lower(serial) LIKE ?', [$pattern])
                    ->orWhereRaw('lower(device_function) LIKE ?', [$pattern]);
            });
        }

        return Inertia::render('Deployment/Show', [
            'deployment' => $deployment,
            'devices' => $devicesQuery->paginate(10)->withQueryString(),
            'device_search' => $search,
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
        $currentClient = $request->user()->currentClient();
        if (! $currentClient) {
            return redirect()->route('clients.index')->with('error', 'Please set current client before creating deployments');
        }

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'min:3',
                'max:255',
                Rule::unique('deployments', 'name')->where(
                    fn ($query) => $query->where('client_id', $currentClient->id)
                ),
            ],
            'description' => 'nullable|string|max:255',
        ]);

        Deployment::create([
            ...$data,
            'client_id' => $currentClient->id,
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

    public function criticalCheck(Request $request, Deployment $deployment, DeploymentCriticalCheckService $criticalCheckService)
    {
        if ($response = $this->criticalCheckClientGuard($request, $deployment)) {
            return $response;
        }

        return Inertia::render('Deployment/CriticalCheck', [
            'deployment' => $deployment->only(['id', 'name']),
            'device_count' => $deployment->devices()->count(),
            'total_steps' => $criticalCheckService->totalSteps($deployment),
            ...$criticalCheckService->emptyResults(),
        ]);
    }

    public function criticalCheckStep(
        Request $request,
        Deployment $deployment,
        int $step,
        DeploymentCriticalCheckService $criticalCheckService,
    ) {
        if ($response = $this->criticalCheckClientGuard($request, $deployment, json: true)) {
            return $response;
        }

        $validated = $request->validate([
            'dns_scope_id' => ['nullable', 'string'],
            'dns_scope_error' => ['nullable', 'string'],
            'include_ethernet' => ['sometimes', 'boolean'],
        ]);

        $includeEthernet = $request->boolean('include_ethernet');

        $context = [
            'include_ethernet' => $includeEthernet,
        ];
        if (array_key_exists('dns_scope_id', $validated) && $validated['dns_scope_id'] !== null) {
            $context['dns_scope_id'] = $validated['dns_scope_id'];
        }
        if (array_key_exists('dns_scope_error', $validated) && $validated['dns_scope_error'] !== null) {
            $context['dns_scope_error'] = $validated['dns_scope_error'];
        }

        $helper = new CentralAPIHelper($deployment->client);
        $total = $criticalCheckService->totalSteps($deployment, $includeEthernet);

        if ($step < 0 || $step >= $total) {
            abort(404);
        }

        return response()->json(
            $criticalCheckService->runStep($deployment, $helper, $step, $context)
        );
    }

    protected function criticalCheckClientGuard(Request $request, Deployment $deployment, bool $json = false)
    {
        $deployment->loadMissing('client');

        $currentClient = $request->user()?->currentClient();
        if (! $currentClient || (int) $deployment->client_id !== (int) $currentClient->id) {
            $message = 'Please set current client to match this deployment before running critical configuration check.';

            if ($json) {
                return response()->json(['message' => $message], 403);
            }

            session()->flash('error', $message);

            return redirect()->route('deployments.index');
        }

        return null;
    }
}
