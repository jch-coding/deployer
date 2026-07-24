<?php

namespace App\Http\Controllers;

use App\Enums\OnlineDetectionMode;
use App\Enums\ProvisioningStep;
use App\Helper\CentralAPIHelper;
use App\Helper\GreenLakeAPIHelper;
use App\Models\Deployment;
use App\Models\ProvisioningWorkflow;
use App\Models\ProvisioningWorkflowDevice;
use App\Models\ProvisioningWorkflowTemplate;
use App\Services\LicensingInventoryService;
use App\Services\Provisioning\CustomWorkflowStepOrder;
use App\Services\Provisioning\ProvisioningPreflightService;
use App\Services\Provisioning\ProvisioningWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
        return $this->renderProvisionPage(
            $request,
            $deployment,
            $workflowService,
            $licensingInventoryService,
            'Deployment/Provision',
        );
    }

    public function customShow(
        Request $request,
        Deployment $deployment,
        ProvisioningWorkflowService $workflowService,
        LicensingInventoryService $licensingInventoryService,
    ): Response {
        return $this->renderProvisionPage(
            $request,
            $deployment,
            $workflowService,
            $licensingInventoryService,
            'Deployment/CustomProvision',
            includeTemplates: true,
        );
    }

    public function preflight(
        Request $request,
        Deployment $deployment,
        ProvisioningWorkflowService $workflowService,
        ProvisioningPreflightService $preflightService,
    ): JsonResponse {
        $this->authorizeDeployment($request, $deployment);

        $stepValues = array_map(fn (ProvisioningStep $step) => $step->value, ProvisioningStep::cases());

        $validated = $request->validate([
            'device_ids' => ['required', 'array', 'min:1'],
            'device_ids.*' => ['integer'],
            'start_step' => ['nullable', 'string', Rule::in($stepValues)],
            'steps' => ['nullable', 'array', 'min:1'],
            'steps.*' => ['string', Rule::in($stepValues)],
            'devices' => ['nullable', 'array'],
            'devices.*.id' => ['nullable', 'integer'],
            'devices.*.name' => ['nullable', 'string', 'max:255'],
        ]);

        if (isset($validated['steps'])) {
            $workflowService->resolveCustomSteps($validated);
        } else {
            $workflowService->resolveStartAndOmitSteps([
                'start_step' => $validated['start_step'] ?? ProvisioningStep::VerifyLicensing->value,
                'omit_steps' => [],
            ]);
        }

        $result = $preflightService->run($deployment, $validated['device_ids'], $validated);

        return response()->json($result);
    }

    public function store(Request $request, Deployment $deployment, ProvisioningWorkflowService $workflowService)
    {
        $this->authorizeDeployment($request, $deployment);

        $stepValues = array_map(fn (ProvisioningStep $step) => $step->value, ProvisioningStep::cases());

        $validated = $request->validate([
            'device_ids' => ['required', 'array', 'min:1'],
            'device_ids.*' => ['integer'],
            'deployment_time' => ['required', 'integer', 'min:1', 'max:1440'],
            'wait_time' => ['required', 'integer', 'min:1', 'max:60'],
            'online_detection_mode' => ['nullable', Rule::in(array_map(
                fn (OnlineDetectionMode $mode) => $mode->value,
                OnlineDetectionMode::cases(),
            ))],
            'licensing_mode' => ['nullable', Rule::in(['uniform', 'per_device'])],
            'license_tag' => ['nullable', 'string'],
            'license_type' => ['nullable', 'string'],
            'devices' => ['nullable', 'array'],
            'devices.*.id' => ['nullable', 'integer'],
            'devices.*.license_tag' => ['nullable', 'string'],
            'devices.*.license_type' => ['nullable', 'string'],
            'devices.*.name' => ['nullable', 'string', 'max:255'],
            'start_step' => ['nullable', 'string', Rule::in($stepValues)],
            'omit_steps' => ['nullable', 'array'],
            'omit_steps.*' => ['string', Rule::in($stepValues)],
            'steps' => ['nullable', 'array', 'min:1'],
            'steps.*' => ['string', Rule::in($stepValues)],
            'name' => ['nullable', 'string', 'max:255'],
            'template_id' => ['nullable', 'integer'],
            'save_as_template' => ['nullable', 'boolean'],
            'template_name' => ['nullable', 'string', 'max:255'],
            'preflight_results' => ['nullable', 'array'],
        ]);

        if (isset($validated['steps'])) {
            $workflowService->resolveCustomSteps($validated);
        } else {
            $workflowService->resolveStartAndOmitSteps($validated);
        }

        $mode = OnlineDetectionMode::tryFrom((string) ($validated['online_detection_mode'] ?? ''))
            ?? OnlineDetectionMode::Poll;

        $deployment->loadMissing('client');

        if ($mode === OnlineDetectionMode::Webhook) {
            if (! filled($deployment->client?->classic_webhook_secret)) {
                throw ValidationException::withMessages([
                    'online_detection_mode' => 'Webhook online detection requires a Classic Central webhook secret on this client.',
                ]);
            }
        }

        if ($mode === OnlineDetectionMode::Stream) {
            if (! $deployment->client?->hasClassicStreamingCredentials()) {
                throw ValidationException::withMessages([
                    'online_detection_mode' => 'Streaming online detection requires Classic Central streaming hostname, username, and key on this client.',
                ]);
            }
        }

        $validated['online_detection_mode'] = $mode->value;

        $templateId = isset($validated['template_id']) ? (int) $validated['template_id'] : null;
        if ($templateId) {
            $template = ProvisioningWorkflowTemplate::query()
                ->where('client_id', $deployment->client_id)
                ->whereKey($templateId)
                ->first();
            if ($template === null) {
                throw ValidationException::withMessages([
                    'template_id' => 'Selected workflow template was not found for this client.',
                ]);
            }
            if (! isset($validated['steps'])) {
                $validated['steps'] = $template->steps;
                $workflowService->resolveCustomSteps($validated);
            }
            $validated['template_id'] = $template->id;
        } else {
            unset($validated['template_id']);
        }

        if ($request->boolean('save_as_template')) {
            $templateName = trim((string) ($validated['template_name'] ?? $validated['name'] ?? ''));
            if ($templateName === '') {
                throw ValidationException::withMessages([
                    'template_name' => 'A template name is required when saving as a template.',
                ]);
            }
            $steps = CustomWorkflowStepOrder::validate($validated['steps'] ?? []);
            $stepKeys = array_map(fn (ProvisioningStep $step) => $step->value, $steps);

            $existing = ProvisioningWorkflowTemplate::query()
                ->where('client_id', $deployment->client_id)
                ->where('name', $templateName)
                ->first();

            if ($existing) {
                $existing->update([
                    'steps' => $stepKeys,
                    'user_id' => $request->user()?->id,
                ]);
                $validated['template_id'] = $existing->id;
            } else {
                $created = ProvisioningWorkflowTemplate::query()->create([
                    'client_id' => $deployment->client_id,
                    'user_id' => $request->user()?->id,
                    'name' => $templateName,
                    'steps' => $stepKeys,
                ]);
                $validated['template_id'] = $created->id;
            }
        }

        $workflow = $workflowService->start(
            $deployment,
            $request->user(),
            $validated['device_ids'],
            $validated,
        );

        $redirectRoute = isset($validated['steps'])
            ? 'deployments.custom_provision'
            : 'deployments.provision';

        return redirect()
            ->route($redirectRoute, $deployment)
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

    private function renderProvisionPage(
        Request $request,
        Deployment $deployment,
        ProvisioningWorkflowService $workflowService,
        LicensingInventoryService $licensingInventoryService,
        string $component,
        bool $includeTemplates = false,
    ): Response {
        $this->authorizeDeployment($request, $deployment);

        $workflow = $workflowService->latestForDeployment($deployment);
        $deployment->load('devices');

        $licensingOptions = $this->resolveLicensingOptions($request, $deployment, $licensingInventoryService);
        $client = $request->user()?->currentClient();

        $props = [
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
            'available_steps' => $workflowService->availableStepsForUi(),
            'selected_device_ids' => $this->parseSelectedDeviceIds($request),
            'license_tags' => $licensingOptions['license_tags'] ?? [],
            'available_subscriptions' => $licensingOptions['available_subscriptions'] ?? [],
            'license_type_options' => array_map(
                fn (\App\LicenseType $type) => $type->value,
                \App\LicenseType::cases(),
            ),
            'licensing_synced_at' => $licensingOptions['licensing_synced_at'] ?? null,
            'licensing_error' => $licensingOptions['central_error'] ?? null,
            'has_classic_webhook_secret' => filled($client?->classic_webhook_secret),
            'has_classic_streaming_credentials' => (bool) $client?->hasClassicStreamingCredentials(),
        ];

        if ($includeTemplates) {
            $props['templates'] = ProvisioningWorkflowTemplate::query()
                ->where('client_id', $deployment->client_id)
                ->orderBy('name')
                ->get()
                ->map(fn (ProvisioningWorkflowTemplate $template) => [
                    'id' => $template->id,
                    'name' => $template->name,
                    'steps' => $template->steps,
                ])
                ->values();
        }

        return Inertia::render($component, $props);
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
