<?php

namespace App\Http\Controllers;

use App\Helper\CentralAPIHelper;
use App\Helper\GreenLakeAPIHelper;
use App\Models\Client;
use App\Models\LicensingInventoryDevice;
use App\Services\LicensingInventoryService;
use App\Services\LicensingSubscriptionResolver;
use App\Services\LicensingSyncException;
use App\Services\LicensingSyncService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LicensingController extends Controller
{
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
            'serial_number' => ['nullable', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'subscription_key' => ['nullable', 'string', 'max:255'],
            'subscription_tags' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
        ]);

        $filters = [
            'start_date_from' => trim((string) ($validated['start_date_from'] ?? '')),
            'start_date_to' => trim((string) ($validated['start_date_to'] ?? '')),
            'end_date_from' => trim((string) ($validated['end_date_from'] ?? '')),
            'end_date_to' => trim((string) ($validated['end_date_to'] ?? '')),
            'license_type' => trim((string) ($validated['license_type'] ?? '')),
            'subscription_sku' => trim((string) ($validated['subscription_sku'] ?? '')),
            'service' => trim((string) ($validated['service'] ?? '')),
            'serial_number' => trim((string) ($validated['serial_number'] ?? '')),
            'device_name' => trim((string) ($validated['device_name'] ?? '')),
            'subscription_key' => trim((string) ($validated['subscription_key'] ?? '')),
            'subscription_tags' => trim((string) ($validated['subscription_tags'] ?? '')),
            'model' => trim((string) ($validated['model'] ?? '')),
        ];

        $centralHelper = new CentralAPIHelper($currentClient);
        $greenLakeHelper = new GreenLakeAPIHelper($currentClient);
        $payload = $inventoryService->build($currentClient, $centralHelper, $greenLakeHelper, $filters);

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

        $centralHelper = new CentralAPIHelper($currentClient);
        $greenLakeHelper = new GreenLakeAPIHelper($currentClient);

        try {
            $licensingSyncService->syncFromCentral($currentClient, $centralHelper, $greenLakeHelper);
            $currentClient->refresh();
            session()->flash(
                'success',
                'Licensing data renewed at '.$currentClient->licensing_synced_at?->format('M j, Y g:i A').'.',
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

    public function removeFromWorkspace(Request $request, LicensingInventoryService $inventoryService)
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            session()->flash('error', 'Please set current client to manage licensing');

            return to_route('clients.index');
        }

        $validated = $request->validate([
            'serials' => ['required', 'array', 'min:1'],
            'serials.*' => ['string', 'max:255'],
        ]);

        $serials = $this->normalizeSerials($validated['serials']);
        $greenLakeHelper = new GreenLakeAPIHelper($currentClient);
        $licensingContext = $this->loadLicensingContext($currentClient, $inventoryService);

        if ($licensingContext['central_error'] !== null) {
            return back()->withErrors(['serials' => $licensingContext['central_error']]);
        }

        $inventoryBySerial = collect($licensingContext['inventory_devices'])->keyBy('serial');
        $serialByDeviceId = [];
        $deviceIds = [];
        foreach ($serials as $serial) {
            $device = $inventoryBySerial->get($serial);
            $greenlakeDeviceId = is_array($device)
                ? trim((string) ($device['greenlake_device_id'] ?? ''))
                : '';
            if ($greenlakeDeviceId === '') {
                return back()->withErrors([
                    'serials' => "Device {$serial} is not linked in GreenLake. Renew licensing and try again.",
                ]);
            }
            $deviceIds[] = $greenlakeDeviceId;
            $serialByDeviceId[$greenlakeDeviceId] = $serial;
        }

        $result = $greenLakeHelper->removeDevicesFromWorkspace($deviceIds);

        $successfulSerials = [];
        foreach ($result['results'] as $deviceResult) {
            if (! ($deviceResult['success'] ?? false)) {
                continue;
            }

            $deviceId = (string) ($deviceResult['device_id'] ?? '');
            if ($deviceId !== '' && isset($serialByDeviceId[$deviceId])) {
                $successfulSerials[] = $serialByDeviceId[$deviceId];
            }
        }

        $successCount = count($successfulSerials);

        if ($successCount > 0) {
            LicensingInventoryDevice::query()
                ->where('client_id', $currentClient->id)
                ->whereIn('serial', $successfulSerials)
                ->delete();
        }

        if ($successCount === 0 && $result['error'] !== null) {
            session()->flash('error', $result['error']);

            return back();
        }

        $failures = $successCount < count($serials)
            ? ['Some devices failed to remove from GreenLake workspace.']
            : [];

        return $this->finishRemoveFromWorkspaceAction(
            $failures,
            $successCount,
            count($serials),
        );
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
        $centralHelper = new CentralAPIHelper($currentClient);
        $greenLakeHelper = new GreenLakeAPIHelper($currentClient);
        $licensingContext = $this->loadLicensingContext($currentClient, $inventoryService);

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

        $subscription = $licensingContext['subscriptions_by_key'][$validated['subscription_key']] ?? null;
        $greenlakeSubscriptionId = is_array($subscription)
            ? trim((string) ($subscription['greenlake_subscription_id'] ?? ''))
            : '';
        if ($greenlakeSubscriptionId === '') {
            return back()->withErrors([
                'subscription_key' => 'GreenLake subscription id is missing. Renew licensing and try again.',
            ]);
        }

        $inventoryBySerial = collect($licensingContext['inventory_devices'])->keyBy('serial');
        $deviceIds = [];
        foreach ($serials as $serial) {
            $device = $inventoryBySerial->get($serial);
            $greenlakeDeviceId = is_array($device)
                ? trim((string) ($device['greenlake_device_id'] ?? ''))
                : '';
            if ($greenlakeDeviceId === '') {
                return back()->withErrors([
                    'serials' => "Device {$serial} is not linked in GreenLake. Renew licensing and try again.",
                ]);
            }
            $deviceIds[] = $greenlakeDeviceId;
        }

        $result = $greenLakeHelper->assignSubscriptionToDevices($deviceIds, $greenlakeSubscriptionId);
        if ($result['error'] !== null) {
            session()->flash('error', $result['error']);

            return back();
        }

        $failed = array_filter($result['responses'], fn ($response) => ! $response->ok());
        $successCount = count($serials) - count($failed);

        return $this->finishSubscriptionAction(
            $failed !== [] ? ['Some devices failed to assign on GreenLake.'] : [],
            $successCount,
            count($serials),
            'assigned',
        );
    }

    private function runUnassignAction(Request $request, LicensingInventoryService $inventoryService)
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            session()->flash('error', 'Please set current client to manage licensing');

            return to_route('clients.index');
        }

        $validated = $request->validate([
            'serials' => ['required', 'array', 'min:1'],
            'serials.*' => ['string', 'max:255'],
        ]);

        $serials = $this->normalizeSerials($validated['serials']);
        $greenLakeHelper = new GreenLakeAPIHelper($currentClient);
        $licensingContext = $this->loadLicensingContext($currentClient, $inventoryService);

        if ($licensingContext['central_error'] !== null) {
            return back()->withErrors(['serials' => $licensingContext['central_error']]);
        }

        $inventoryBySerial = collect($licensingContext['inventory_devices'])->keyBy('serial');
        $deviceIds = [];
        foreach ($serials as $serial) {
            $device = $inventoryBySerial->get($serial);
            $subscriptionKey = is_array($device)
                ? trim((string) ($device['subscription_key'] ?? ''))
                : '';
            if ($subscriptionKey === '') {
                return back()->withErrors([
                    'serials' => "Device {$serial} has no subscription to remove.",
                ]);
            }

            $greenlakeDeviceId = is_array($device)
                ? trim((string) ($device['greenlake_device_id'] ?? ''))
                : '';
            if ($greenlakeDeviceId === '') {
                return back()->withErrors([
                    'serials' => "Device {$serial} is not linked in GreenLake. Renew licensing and try again.",
                ]);
            }
            $deviceIds[] = $greenlakeDeviceId;
        }

        $result = $greenLakeHelper->unassignSubscriptionFromDevices($deviceIds);
        if ($result['error'] !== null) {
            session()->flash('error', $result['error']);

            return back();
        }

        $failed = array_filter($result['responses'], fn ($response) => ! $response->ok());
        $successCount = count($serials) - count($failed);

        return $this->finishSubscriptionAction(
            $failed !== [] ? ['Some devices failed to unassign on GreenLake.'] : [],
            $successCount,
            count($serials),
            'unassigned',
        );
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
                'greenlake_device_id' => $device['greenlake_device_id'] ?? '',
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
    private function finishRemoveFromWorkspaceAction(array $failures, int $successCount, int $total)
    {
        if ($failures !== [] && $successCount === 0) {
            session()->flash('error', implode(' ', $failures));

            return back();
        }

        if ($failures !== []) {
            session()->flash(
                'success',
                "{$successCount} device(s) removed from workspace. Some devices failed: ".implode(' ', $failures)
                .' Devices may reappear after Renew licensing if GreenLake still lists them.',
            );
        } else {
            session()->flash(
                'success',
                "{$total} device(s) removed from workspace successfully."
                .' They may reappear after Renew licensing if GreenLake still lists them.',
            );
        }

        return back();
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
