<?php

namespace App\Http\Controllers;

use App\Jobs\UpdateSystemInfo;
use App\Models\Task;
use Illuminate\Bus\Batch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Inertia\Inertia;

class DispatchController extends Controller
{
    public $task_to_job = [
        'UPDATE_SYSTEM_INFO' => UpdateSystemInfo::class,
    ];

    public function dispatch(Request $request, Task $task)
    {
        $jobs = [];
        switch ($task->task_type) {
            case 'UPDATE_SYSTEM_INFO':
                $jobs[] = $task->devices->map(fn($device) => new UpdateSystemInfo($device, $task));
                break;
        }
        $job_batch = Bus::batch(
            $jobs
        )
            ->allowFailures()
            ->dispatch();

        Inertia::share('batch_id', $job_batch->id);
        return to_route('deployments.show', $task->deployment);
    }
}
