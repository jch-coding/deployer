<?php

namespace App\Http\Controllers;

use App\Helper\CentralAPIHelper;
use App\JobQueueShard;
use App\Models\Deployment;
use App\Models\Device;
use App\Services\LicensingInventoryService;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class LicensingController extends Controller
{
    private const SERIALS_PER_REQUEST = 25;

    public function index(Request $request, LicensingInventoryService $inventoryService)
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            session()->flash('error', 'Please set current client to view licensing');

            return to_route('clients.index');
        }

        $validated = $request->validate([
            'start_date_from' => ['nullable', 'date'],
            'start_date_to' => ['nullable', 'date'],
            'end_date_from' => ['nullable', 'date'],
            'end_date_to' => ['nullable', 'date'],
            'license_type' => ['nullable', 'string', 'max:255'],
            'subscription_sku' => ['nullable', 'string', 'max:255'],
            'service' => ['nullable', 'string', 'max:255'],
        ]);

        $filters = [
            'start_date_from' => trim((string) ($validated['start_date_from'] ?? '')),
            'start_date_to' => trim((string) ($validated['start_date_to'] ?? '')),
            'end_date_from' => trim((string) ($validated['end_date_from'] ?? '')),
            'end_date_to' => trim((string) ($validated['end_date_to'] ?? '')),
            'license_type' => trim((string) ($validated['license_type'] ?? '')),
            'subscription_sku' => trim((string) ($validated['subscription_sku'] ?? '')),
            'service' => trim((string) ($validated['service'] ?? '')),
        ];

        $helper = new CentralAPIHelper($currentClient);
        $payload = $inventoryService->build($currentClient, $helper, $filters);

        $deployments = $currentClient->deployments()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Deployment $deployment): array => [
                'id' => $deployment->id,
                'name' => $deployment->name,
            ])
            ->values()
            ->all();

        return Inertia::render('Licensing/Index', [
            'devices' => $payload['devices'],
            'enabled_services' => $payload['enabled_services'],
            'subscription_summary' => $payload['subscription_summary'],
            'filter_options' => $payload['filter_options'],
            'filters' => $filters,
            'has_active_filters' => $this->hasActiveFilters($filters),
            'central_error' => $payload['central_error'],
            'deployments' => $deployments,
        ]);
    }

    public function assign(Request $request)
    {
        return $this->runSubscriptionAction($request, assign: true);
    }

    public function unassign(Request $request)
    {
        return $this->runSubscriptionAction($request, assign: false);
    }

    public function queue(Request $request, TaskController $taskController)
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            session()->flash('error', 'Please set current client to queue licensing tasks');

            return to_route('clients.index');
        }

        $validated = $request->validate([
            'action' => ['required', Rule::in(['assign', 'unassign'])],
            'service_name' => ['required', 'string', 'max:255'],
            'deployment_id' => ['required', 'integer'],
            'device_ids' => ['required', 'array', 'min:1'],
            'device_ids.*' => ['integer'],
            'deployment_time' => ['nullable', 'integer', 'min:1'],
        ]);

        $deployment = Deployment::query()
            ->where('client_id', $currentClient->id)
            ->findOrFail($validated['deployment_id']);

        $helper = new CentralAPIHelper($currentClient);
        $enabledServices = $this->resolveEnabledServices($helper);
        if (isset($enabledServices['error'])) {
            return back()->withErrors(['service_name' => $enabledServices['error']]);
        }

        if (! in_array($validated['service_name'], $enabledServices['services'], true)) {
            return back()->withErrors(['service_name' => 'Selected service is not enabled for this client.']);
        }

        $devices = Device::query()
            ->where('client_id', $currentClient->id)
            ->whereIn('id', $validated['device_ids'])
            ->get();

        if ($devices->isEmpty()) {
            return back()->withErrors(['device_ids' => 'Select at least one device that exists in Deployer for this client.']);
        }

        $taskType = $validated['action'] === 'assign' ? 'ASSIGN_SUBSCRIPTION' : 'UNASSIGN_SUBSCRIPTION';

        $task = $deployment->tasks()->create([
            'task_type' => $taskType,
            'name' => strtolower($taskType).'_for_'.$deployment->name.now(),
            'deployment_time' => $validated['deployment_time'] ?? 3,
            'status' => 'IN_PROGRESS',
            'job_queue' => JobQueueShard::fromUserEntropy((int) $request->user()->id, (string) Str::uuid()),
            'licensing_service_name' => $validated['service_name'],
        ]);

        $task->devices()->attach($devices->pluck('id'));

        $batchId = $taskController->dispatchJob($task);
        if ($batchId !== null) {
            $task->forceFill(['batch_id' => $batchId])->save();
        }

        session()->flash('success', 'Licensing task queued.');

        return to_route('tasks.show', $task);
    }

    private function runSubscriptionAction(Request $request, bool $assign)
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            session()->flash('error', 'Please set current client to manage licensing');

            return to_route('clients.index');
        }

        $validated = $request->validate([
            'service_name' => ['required', 'string', 'max:255'],
            'serials' => ['required', 'array', 'min:1'],
            'serials.*' => ['string', 'max:255'],
        ]);

        $helper = new CentralAPIHelper($currentClient);
        $enabledServices = $this->resolveEnabledServices($helper);
        if (isset($enabledServices['error'])) {
            return back()->withErrors(['service_name' => $enabledServices['error']]);
        }

        if (! in_array($validated['service_name'], $enabledServices['services'], true)) {
            return back()->withErrors(['service_name' => 'Selected service is not enabled for this client.']);
        }

        $serials = array_values(array_unique(array_filter(
            array_map(fn ($serial) => trim((string) $serial), $validated['serials']),
            fn ($serial) => $serial !== '',
        )));

        $failures = [];
        $successCount = 0;

        foreach (array_chunk($serials, self::SERIALS_PER_REQUEST) as $chunk) {
            $response = $assign
                ? $helper->classic_assign_subscription($chunk, $validated['service_name'])
                : $helper->classic_unassign_subscription($chunk, $validated['service_name']);

            if (! is_array($response) && $response instanceof Response && $response->ok()) {
                $successCount += count($chunk);

                continue;
            }

            $failures[] = $this->formatSubscriptionError($response, $chunk);
        }

        if ($failures !== [] && $successCount === 0) {
            session()->flash('error', implode(' ', $failures));

            return back();
        }

        $actionLabel = $assign ? 'assigned' : 'unassigned';
        if ($failures !== []) {
            session()->flash('success', "{$successCount} device(s) {$actionLabel}. Some batches failed: ".implode(' ', $failures));
        } else {
            session()->flash('success', count($serials)." device(s) {$actionLabel} successfully.");
        }

        return back();
    }

    /**
     * @return array{services: array<int, string>}|array{error: string}
     */
    private function resolveEnabledServices(CentralAPIHelper $helper): array
    {
        return $helper->classic_parse_enabled_services($helper->classic_get_enabled_services());
    }

    /**
     * @param  array<int, string>  $serials
     */
    private function formatSubscriptionError(mixed $response, array $serials): string
    {
        $prefix = 'Batch ('.implode(', ', $serials).'): ';

        if (is_array($response)) {
            return $prefix.($response['error'] ?? json_encode($response));
        }

        if ($response instanceof Response) {
            $json = $response->json();
            if (is_array($json) && isset($json['message'])) {
                return $prefix.(string) $json['message'];
            }

            return $prefix.$response->body();
        }

        return $prefix.'unknown error';
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function hasActiveFilters(array $filters): bool
    {
        foreach ($filters as $value) {
            if ($value !== '') {
                return true;
            }
        }

        return false;
    }
}
