<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Services\CentralApiProxyService;
use App\Services\CentralOpenApiRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CentralApiExplorerController extends Controller
{
    public function index(Request $request, CentralOpenApiRegistry $registry)
    {
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

        $deviceOptions = Device::query()
            ->where('client_id', $currentClient->id)
            ->orderBy('name')
            ->get(['id', 'serial', 'name', 'scope_id', 'device_function'])
            ->map(fn (Device $device): array => [
                'id' => $device->id,
                'serial' => $device->serial,
                'name' => $device->name,
                'scope_id' => $device->scope_id,
                'device_function' => $device->device_function instanceof \BackedEnum
                    ? $device->device_function->value
                    : (string) $device->device_function,
            ])
            ->values()
            ->all();

        return Inertia::render('CentralApi/Explorer', [
            'tags' => $registry->tags(),
            'operations_by_tag' => $operationsByTag,
            'device_options' => $deviceOptions,
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
}
