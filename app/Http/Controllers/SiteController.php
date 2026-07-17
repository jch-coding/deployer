<?php

namespace App\Http\Controllers;

use App\Helper\CentralAPIHelper;
use App\Services\CentralScopeCacheService;
use App\Services\DeviceCentralFilterBuilder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SiteController extends Controller
{
    private const DEVICE_TYPES = ['ACCESS_POINT', 'SWITCH', 'GATEWAY'];

    private const STATUSES = ['ONLINE', 'OFFLINE'];

    private const DEPLOYMENTS = ['Standalone', 'Cluster', 'Stack'];

    public function index(Request $request, DeviceCentralFilterBuilder $filterBuilder, CentralScopeCacheService $centralScopeCacheService)
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            session()->flash('error', 'Please set current client to view sites');

            return to_route('clients.index');
        }

        $validated = $request->validate([
            'site_id' => ['nullable', 'string', 'max:255'],
            'site_name' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'device_type' => ['nullable', 'string', Rule::in(self::DEVICE_TYPES)],
            'status' => ['nullable', 'string', Rule::in(self::STATUSES)],
            'model' => ['nullable', 'string', 'max:255'],
            'firmware_version' => ['nullable', 'string', 'max:255'],
            'deployment' => ['nullable', 'string', Rule::in(self::DEPLOYMENTS)],
            'submitted' => ['nullable', 'boolean'],
        ]);

        $filters = [
            'site_id' => trim((string) ($validated['site_id'] ?? '')),
            'site_name' => trim((string) ($validated['site_name'] ?? '')),
            'serial_number' => trim((string) ($validated['serial_number'] ?? '')),
            'device_name' => trim((string) ($validated['device_name'] ?? '')),
            'device_type' => trim((string) ($validated['device_type'] ?? '')),
            'status' => trim((string) ($validated['status'] ?? '')),
            'model' => trim((string) ($validated['model'] ?? '')),
            'firmware_version' => trim((string) ($validated['firmware_version'] ?? '')),
            'deployment' => trim((string) ($validated['deployment'] ?? '')),
        ];

        $submitted = (bool) ($validated['submitted'] ?? false);

        $helper = new CentralAPIHelper($currentClient);
        $sitesPayload = $centralScopeCacheService->getSites($currentClient);

        $siteOptions = array_map(
            fn (array $site): array => [
                'siteId' => $site['scopeId'],
                'siteName' => $site['scopeName'],
            ],
            $sitesPayload['sites'],
        );

        $centralError = $sitesPayload['error'];
        $devices = [];
        $hasActiveFilters = $this->hasActiveFilters($filters);

        if ($centralError === null && $hasActiveFilters && $submitted) {
            $filter = $filterBuilder->build([
                'siteId' => $filters['site_id'],
                'siteName' => $filters['site_name'],
                'serialNumber' => $filters['serial_number'],
                'deviceName' => $filters['device_name'],
                'deviceType' => $filters['device_type'],
                'status' => $filters['status'],
                'model' => $filters['model'],
                'firmwareVersion' => $filters['firmware_version'],
                'deployment' => $filters['deployment'],
            ]);

            if ($filter !== null) {
                $result = $helper->get_all_devices(['filter' => $filter]);

                if (array_key_exists('error', $result)) {
                    $centralError = (string) $result['error'];
                } else {
                    $devices = array_map(
                        fn (array $item): array => [
                            'deviceName' => (string) ($item['deviceName'] ?? ''),
                            'serialNumber' => (string) ($item['serialNumber'] ?? ''),
                            'deviceFunction' => (string) ($item['deviceFunction'] ?? ''),
                            'model' => (string) ($item['model'] ?? ''),
                            'ipv4' => (string) ($item['ipv4'] ?? ''),
                            'status' => (string) ($item['status'] ?? ''),
                            'deployment' => (string) ($item['deployment'] ?? ''),
                            'siteName' => (string) ($item['siteName'] ?? ''),
                        ],
                        $result,
                    );
                }
            }
        }

        return Inertia::render('Site/Index', [
            'devices' => $devices,
            'filters' => $filters,
            'site_options' => $siteOptions,
            'central_error' => $centralError,
            'has_active_filters' => $hasActiveFilters,
            'device_type_options' => self::DEVICE_TYPES,
            'status_options' => self::STATUSES,
            'deployment_options' => self::DEPLOYMENTS,
            ...$centralScopeCacheService->getCacheMetadata($currentClient),
        ]);
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
