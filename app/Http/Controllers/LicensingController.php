<?php

namespace App\Http\Controllers;

use App\Helper\CentralAPIHelper;
use App\Models\Client;
use App\Services\LicensingInventoryService;
use App\Services\LicensingSubscriptionResolver;
use App\Services\LicensingSyncException;
use App\Services\LicensingSyncService;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
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

        return Inertia::render('Licensing/Index', [
            'devices' => $payload['devices'],
            'enabled_services' => $payload['enabled_services'],
            'available_subscriptions' => $payload['available_subscriptions'],
            'subscription_summary' => $payload['subscription_summary'],
            'filter_options' => $payload['filter_options'],
            'filters' => $filters,
            'has_active_filters' => $this->hasActiveFilters($filters),
            'central_error' => $payload['central_error'],
            'licensing_synced_at' => $payload['licensing_synced_at'],
        ]);
    }

    public function renew(
        Request $request,
        LicensingSyncService $licensingSyncService,
    ) {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            session()->flash('error', 'Please set current client to renew licensing');

            return to_route('clients.index');
        }

        $helper = new CentralAPIHelper($currentClient);

        try {
            $licensingSyncService->syncFromCentral($currentClient, $helper);
            $currentClient->refresh();
            session()->flash(
                'success',
                'Licensing data renewed from Central at '.$currentClient->licensing_synced_at?->format('M j, Y g:i A').'.',
            );
        } catch (LicensingSyncException $e) {
            session()->flash('error', $e->getMessage());
        }

        return back();
    }

    public function assign(
        Request $request,
        LicensingInventoryService $inventoryService,
        LicensingSubscriptionResolver $resolver,
    ) {
        return $this->runAssignAction($request, $inventoryService, $resolver);
    }

    public function unassign(Request $request, LicensingInventoryService $inventoryService)
    {
        return $this->runUnassignAction($request, $inventoryService);
    }

    private function runAssignAction(
        Request $request,
        LicensingInventoryService $inventoryService,
        LicensingSubscriptionResolver $resolver,
    ) {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            session()->flash('error', 'Please set current client to manage licensing');

            return to_route('clients.index');
        }

        $validated = $request->validate([
            'subscription_key' => ['required', 'string', 'max:255'],
            'serials' => ['required', 'array', 'min:1'],
            'serials.*' => ['string', 'max:255'],
        ]);

        $serials = $this->normalizeSerials($validated['serials']);
        $helper = new CentralAPIHelper($currentClient);
        $licensingContext = $this->loadLicensingContext($currentClient, $helper, $inventoryService);

        if ($licensingContext['central_error'] !== null) {
            return back()->withErrors(['subscription_key' => $licensingContext['central_error']]);
        }

        $capacityError = $resolver->validateCapacity(
            $validated['subscription_key'],
            count($serials),
            $licensingContext['subscriptions_by_key'],
        );
        if ($capacityError !== null) {
            return back()->withErrors(['subscription_key' => $capacityError['error']]);
        }

        $resolved = $resolver->resolveServiceName(
            $validated['subscription_key'],
            $licensingContext['enabled_services'],
            $licensingContext['subscriptions_by_key'],
            $licensingContext['inventory_devices'],
        );
        if (isset($resolved['error'])) {
            return back()->withErrors(['subscription_key' => $resolved['error']]);
        }

        $serviceName = $resolved['service_name'];
        $failures = [];
        $successCount = 0;

        foreach (array_chunk($serials, self::SERIALS_PER_REQUEST) as $chunk) {
            $response = $helper->classic_assign_subscription($chunk, $serviceName);

            if (! is_array($response) && $response instanceof Response && $response->ok()) {
                $successCount += count($chunk);

                continue;
            }

            $failures[] = $this->formatSubscriptionError($response, $chunk);
        }

        return $this->finishSubscriptionAction($failures, $successCount, count($serials), 'assigned');
    }

    private function runUnassignAction(Request $request, LicensingInventoryService $inventoryService)
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

        $serials = $this->normalizeSerials($validated['serials']);
        $helper = new CentralAPIHelper($currentClient);
        $licensingContext = $this->loadLicensingContext($currentClient, $helper, $inventoryService);

        if ($licensingContext['central_error'] !== null) {
            return back()->withErrors(['service_name' => $licensingContext['central_error']]);
        }

        if (! in_array($validated['service_name'], $licensingContext['enabled_services'], true)) {
            return back()->withErrors(['service_name' => 'Selected service is not enabled for this client.']);
        }

        $inventoryBySerial = collect($licensingContext['inventory_devices'])->keyBy('serial');
        foreach ($serials as $serial) {
            $device = $inventoryBySerial->get($serial);
            $assigned = is_array($device) ? ($device['assigned_services'] ?? []) : [];
            if (! is_array($assigned) || ! in_array($validated['service_name'], $assigned, true)) {
                return back()->withErrors([
                    'service_name' => "Service {$validated['service_name']} is not assigned to device {$serial}.",
                ]);
            }
        }

        $failures = [];
        $successCount = 0;

        foreach (array_chunk($serials, self::SERIALS_PER_REQUEST) as $chunk) {
            $response = $helper->classic_unassign_subscription($chunk, $validated['service_name']);

            if (! is_array($response) && $response instanceof Response && $response->ok()) {
                $successCount += count($chunk);

                continue;
            }

            $failures[] = $this->formatSubscriptionError($response, $chunk);
        }

        return $this->finishSubscriptionAction($failures, $successCount, count($serials), 'unassigned');
    }

    /**
     * @param  array<int, string>  $serials
     * @return array<int, string>
     */
    private function normalizeSerials(array $serials): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn ($serial) => trim((string) $serial), $serials),
            fn ($serial) => $serial !== '',
        )));
    }

    /**
     * @return array{
     *     enabled_services: array<int, string>,
     *     subscriptions_by_key: array<string, array<string, mixed>>,
     *     inventory_devices: array<int, array<string, mixed>>,
     *     central_error: string|null
     * }
     */
    private function loadLicensingContext(
        Client $client,
        CentralAPIHelper $helper,
        LicensingInventoryService $inventoryService,
    ): array {
        $payload = $inventoryService->buildFromCache($client, []);

        if ($payload['central_error'] !== null) {
            return [
                'enabled_services' => $payload['enabled_services'],
                'subscriptions_by_key' => [],
                'inventory_devices' => [],
                'central_error' => $payload['central_error'],
            ];
        }

        $inventoryDevices = array_map(function (array $device): array {
            return [
                'serial' => $device['serial'],
                'subscription_key' => $device['subscription_key'],
                'services' => $device['assigned_services'],
                'assigned_services' => $device['assigned_services'],
            ];
        }, $payload['devices']);

        return [
            'enabled_services' => $payload['enabled_services'],
            'subscriptions_by_key' => $payload['subscriptions_by_key'],
            'inventory_devices' => $inventoryDevices,
            'central_error' => null,
        ];
    }

    /**
     * @param  array<int, string>  $failures
     */
    private function finishSubscriptionAction(array $failures, int $successCount, int $total, string $actionLabel)
    {
        if ($failures !== [] && $successCount === 0) {
            session()->flash('error', implode(' ', $failures));

            return back();
        }

        if ($failures !== []) {
            session()->flash('success', "{$successCount} device(s) {$actionLabel}. Some batches failed: ".implode(' ', $failures));
        } else {
            session()->flash('success', "{$total} device(s) {$actionLabel} successfully.");
        }

        return back();
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
