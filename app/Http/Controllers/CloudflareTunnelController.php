<?php

namespace App\Http\Controllers;

use App\Services\Cloudflare\CloudflaredTunnelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CloudflareTunnelController extends Controller
{
    public function start(Request $request, CloudflaredTunnelService $tunnels): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9-]*$/'],
        ]);

        try {
            $result = $tunnels->start($validated['name']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function stop(CloudflaredTunnelService $tunnels): JsonResponse
    {
        try {
            $result = $tunnels->stop();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function login(CloudflaredTunnelService $tunnels): JsonResponse
    {
        try {
            $result = $tunnels->startLogin();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function loginStatus(CloudflaredTunnelService $tunnels): JsonResponse
    {
        try {
            $result = $tunnels->loginStatus();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    public function create(Request $request, CloudflaredTunnelService $tunnels): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9-]*$/'],
        ]);

        try {
            $result = $tunnels->createTunnel($validated['name']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function dns(Request $request, CloudflaredTunnelService $tunnels): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9-]*$/'],
            'hostname' => ['required', 'string', 'max:253'],
        ]);

        try {
            $tunnels->assertValidHostname($validated['hostname']);
            $result = $tunnels->routeDns($validated['name'], strtolower(trim($validated['hostname'])));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function run(Request $request, CloudflaredTunnelService $tunnels): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9-]*$/'],
            'hostname' => ['required', 'string', 'max:253'],
            'update_app_url' => ['sometimes', 'boolean'],
        ]);

        try {
            $tunnels->assertValidHostname($validated['hostname']);
            $result = $tunnels->runTunnel(
                $validated['name'],
                strtolower(trim($validated['hostname'])),
                $request->boolean('update_app_url', true),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}
