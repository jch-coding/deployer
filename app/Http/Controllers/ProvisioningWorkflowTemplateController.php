<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ProvisioningWorkflowTemplate;
use App\Services\Provisioning\CustomWorkflowStepOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProvisioningWorkflowTemplateController extends Controller
{
    public function store(Request $request, Client $client): RedirectResponse
    {
        $this->authorizeClient($request, $client);

        $validated = $this->validateTemplatePayload($request);
        $steps = CustomWorkflowStepOrder::validate($validated['steps']);

        ProvisioningWorkflowTemplate::query()->create([
            'client_id' => $client->id,
            'user_id' => $request->user()?->id,
            'name' => $validated['name'],
            'steps' => array_map(fn ($step) => $step->value, $steps),
        ]);

        return back()->with('success', 'Workflow template saved.');
    }

    public function update(Request $request, ProvisioningWorkflowTemplate $template): RedirectResponse
    {
        $this->authorizeTemplate($request, $template);

        $validated = $this->validateTemplatePayload($request, $template);
        $steps = CustomWorkflowStepOrder::validate($validated['steps']);

        $template->update([
            'name' => $validated['name'],
            'steps' => array_map(fn ($step) => $step->value, $steps),
        ]);

        return back()->with('success', 'Workflow template updated.');
    }

    public function destroy(Request $request, ProvisioningWorkflowTemplate $template): RedirectResponse
    {
        $this->authorizeTemplate($request, $template);
        $template->delete();

        return back()->with('success', 'Workflow template deleted.');
    }

    /**
     * @return array{name: string, steps: list<mixed>}
     */
    private function validateTemplatePayload(Request $request, ?ProvisioningWorkflowTemplate $template = null): array
    {
        $client = $template?->client ?? $request->route('client');
        $clientId = $client instanceof Client ? (int) $client->id : (int) $client;

        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('provisioning_workflow_templates', 'name')
                    ->where(fn ($query) => $query->where('client_id', $clientId))
                    ->ignore($template?->id),
            ],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*' => ['string'],
        ]);
    }

    private function authorizeClient(Request $request, Client $client): void
    {
        $current = $request->user()?->currentClient();
        if (! $current || (int) $current->id !== (int) $client->id) {
            abort(403);
        }
    }

    private function authorizeTemplate(Request $request, ProvisioningWorkflowTemplate $template): void
    {
        $template->loadMissing('client');
        $this->authorizeClient($request, $template->client);
    }
}
