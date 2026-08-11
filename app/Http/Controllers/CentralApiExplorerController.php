<?php

namespace App\Http\Controllers;

use App\DeviceFunction;
use App\Helper\CentralAPIHelper;
use App\Services\CentralApiProxyService;
use App\Services\CentralOpenApiRegistry;
use App\Services\CentralScopeCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CentralApiExplorerController extends Controller
{
    public function index(
        Request $request,
        CentralOpenApiRegistry $registry,
        CentralScopeCacheService $centralScopeCacheService,
    ) {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            session()->flash('error', 'Please set current client to use the Central API explorer.');

            return to_route('clients.index');
        }

        $operationsByTag = [];

        foreach ($registry->operations() as $operation) {
            $tag = $operation['tags'][0] ?? 'Uncategorized';
            $operationsByTag[$tag][] = $operation;
        }

        ksort($operationsByTag);

        $centralApiHelper = new CentralAPIHelper($currentClient);
        $deviceOptionsPayload = $centralApiHelper->collectCentralDeviceOptions();
        $sitesPayload = $centralScopeCacheService->getSites($currentClient);
        $groupsPayload = $centralScopeCacheService->getGroups($currentClient);
        $siteCollectionsPayload = $centralApiHelper->collectScopeManagementSiteCollections();
        $cacheMetadata = $centralScopeCacheService->getCacheMetadata($currentClient);

        return Inertia::render('CentralApi/Explorer', [
            'tags' => $registry->tags(),
            'operations_by_tag' => $operationsByTag,
            'device_options' => $deviceOptionsPayload['devices'],
            'device_options_error' => $deviceOptionsPayload['error'],
            'scope_sites' => $sitesPayload['sites'],
            'scope_groups' => $groupsPayload['central_device_groups'],
            'scope_site_collections' => $siteCollectionsPayload['site_collections'],
            'scope_sites_error' => $sitesPayload['error'],
            'scope_groups_error' => $groupsPayload['error'],
            'scope_site_collections_error' => $siteCollectionsPayload['error'],
            'central_sites_cache' => $cacheMetadata['central_sites_cache'],
            'central_groups_cache' => $cacheMetadata['central_groups_cache'],
            'device_function_options' => array_map(
                fn (DeviceFunction $deviceFunction): string => $deviceFunction->name,
                DeviceFunction::cases(),
            ),
            'base_url_display' => $currentClient->base_url,
            'docs_url' => 'https://developer.arubanetworks.com/new-central-config/reference/getactiveissues',
        ]);
    }

    public function execute(
        Request $request,
        CentralApiProxyService $proxy,
    ): JsonResponse {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            return response()->json(['message' => 'No current client selected.'], 403);
        }

        $validated = $request->validate([
            'operation_id' => ['required', 'string', 'max:255'],
            'query' => ['nullable', 'array'],
            'body' => ['nullable', 'array'],
        ]);

        $result = $proxy->execute(
            $currentClient,
            $validated['operation_id'],
            $validated['query'] ?? [],
            $validated['body'] ?? null,
        );

        return response()->json([
            'ok' => $result['ok'],
            'status' => $result['status'],
            'duration_ms' => $result['duration_ms'],
            'headers' => $result['headers'],
            'body' => $result['body'],
            'request_url' => $result['request_url'],
            'error' => $result['error'],
        ]);
    }

    public function deviceContext(Request $request): JsonResponse
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            return response()->json(['message' => 'No current client selected.'], 403);
        }

        $validated = $request->validate([
            'serial' => ['required', 'string', 'max:255'],
            'device_function' => ['nullable', 'string', 'max:255'],
        ]);

        $result = (new CentralAPIHelper($currentClient))->resolveCentralDeviceContext(
            $validated['serial'],
            (string) ($validated['device_function'] ?? ''),
        );

        if (array_key_exists('error', $result)) {
            return response()->json(['message' => $result['error']], 422);
        }

        return response()->json($result);
    }
}
