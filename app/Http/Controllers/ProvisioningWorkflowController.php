<?php

namespace App\Http\Controllers;

use App\Enums\ProvisioningStep;
use App\Helper\CentralAPIHelper;
use App\Helper\GreenLakeAPIHelper;
use App\Models\Deployment;
use App\Models\ProvisioningWorkflow;
use App\Models\ProvisioningWorkflowDevice;
use App\Services\LicensingInventoryService;
use App\Services\Provisioning\ProvisioningWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProvisioningWorkflowController extends Controller
{
    public function show(
        Request $request,
        Deployment $deployment,
        ProvisioningWorkflowService $workflowService,
        LicensingInventoryService $licensingInventoryService,
    ): Response {
        $this->authorizeDeployment($request, $deployment);

        $workflow = $workflowService->latestForDeployment($deployment);
        $deployment->load('devices');

        $licensingOptions = $this->resolveLicensingOptions($request, $deployment, $licensingInventoryService);

        return Inertia::render('Deployment/Provision', [
            'deployment' => [
                'id' => $deployment->id,
                'name' => $deployment->name,
                'devices' => $deployment->devices->map(fn ($device) => [
                    'id' => $device->id,
                    'name' => $device->name,
                    'serial' => $device->serial,
                    'device_function' => $device->device_function,
                    'license_tag' => $device->license_tag,
                    'license_type' => $device->license_type,
                    'group' => $device->group,
                ])->values(),
            ],
            'workflow' => $workflow ? $workflowService->serializeForUi($workflow) : null,
            'selected_device_ids' => $this->parseSelectedDeviceIds($request),
            'license_tags' => $licensingOptions['license_tags'] ?? [],
            'available_subscriptions' => $licensingOptions['available_subscriptions'] ?? [],
            'license_type_options' => array_map(
                fn (\App\LicenseType $type) => $type->value,
                \App\LicenseType::cases(),
            ),
            'licensing_synced_at' => $licensingOptions['licensing_synced_at'] ?? null,
            'licensing_error' => $licensingOptions['central_error'] ?? null,
        ]);
    }

    public function store(Request $request, Deployment $deployment, ProvisioningWorkflowService $workflowService)
    {
        $this->authorizeDeployment($request, $deployment);

        $validated = $request->validate([
            'device_ids' => ['required', 'array', 'min:1'],
            'device_ids.*' => ['integer'],
            'deployment_time' => ['required', 'integer', 'min:1', 'max:1440'],
            'wait_time' => ['required', 'integer', 'min:1', 'max:60'],
            'licensing_mode' => ['nullable', Rule::in(['uniform', 'per_device'])],
            'license_tag' => ['nullable', 'string'],
            'license_type' => ['nullable', 'string'],
            'devices' => ['nullable', 'array'],
            'devices.*.id' => ['nullable', 'integer'],
            'devices.*.license_tag' => ['nullable', 'string'],
            'devices.*.license_type' => ['nullable', 'string'],
            'devices.*.name' => ['nullable', 'string', 'max:255'],
        ]);

        $workflow = $workflowService->start(
            $deployment,
            $request->user(),
            $validated['device_ids'],
            $validated,
        );

        return redirect()
            ->route('deployments.provision', $deployment)
            ->with('success', 'Provisioning workflow started for '.$workflow->workflowDevices()->count().' device(s).');
    }

    public function cancel(Request $request, ProvisioningWorkflow $workflow, ProvisioningWorkflowService $workflowService)
    {
        $this->authorizeWorkflow($request, $workflow);
        $workflowService->cancel($workflow);

        return back()->with('success', 'Provisioning workflow cancelled.');
    }

    public function restart(Request $request, ProvisioningWorkflowDevice $workflowDevice, ProvisioningWorkflowService $workflowService)
    {
        $workflowDevice->loadMissing('workflow.deployment');
        $this->authorizeWorkflow($request, $workflowDevice->workflow);

        $validated = $request->validate([
            'from_step' => ['required', 'string', Rule::in(array_map(fn (ProvisioningStep $step) => $step->value, ProvisioningStep::cases()))],
        ]);

        $workflowService->restartFromStep(
            $workflowDevice,
            ProvisioningStep::from($validated['from_step']),
        );

        return back()->with('success', 'Device workflow restarted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveLicensingOptions(
        Request $request,
        Deployment $deployment,
        LicensingInventoryService $licensingInventoryService,
    ): array {
        $client = $request->user()?->currentClient();
        if (! $client || (int) $client->id !== (int) $deployment->client_id) {
            return [
                'license_tags' => [],
                'available_subscriptions' => [],
                'central_error' => null,
                'licensing_synced_at' => null,
            ];
        }

        return $licensingInventoryService->resolveLicensingOptions(
            $client,
            new CentralAPIHelper($client),
            new GreenLakeAPIHelper($client),
        );
    }

    private function authorizeDeployment(Request $request, Deployment $deployment): void
    {
        $client = $request->user()?->currentClient();
        if (! $client || (int) $client->id !== (int) $deployment->client_id) {
            abort(403);
        }
    }

    private function authorizeWorkflow(Request $request, ProvisioningWorkflow $workflow): void
    {
        $workflow->loadMissing('deployment');
        $this->authorizeDeployment($request, $workflow->deployment);
    }

    /**
     * @return list<int>
     */
    private function parseSelectedDeviceIds(Request $request): array
    {
        $raw = $request->query('device_ids');
        if (is_string($raw) && $raw !== '') {
            return array_values(array_filter(array_map('intval', explode(',', $raw))));
        }

        if (is_array($raw)) {
            return array_values(array_filter(array_map('intval', $raw)));
        }

        return [];
    }
}
