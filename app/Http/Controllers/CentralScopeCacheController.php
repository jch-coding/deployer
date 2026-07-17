<?php

namespace App\Http\Controllers;

use App\Services\CentralScopeCacheService;
use Illuminate\Http\Request;

class CentralScopeCacheController extends Controller
{
    public function refreshSites(Request $request, CentralScopeCacheService $centralScopeCacheService)
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            session()->flash('error', 'Please set current client to refresh Central sites');

            return to_route('clients.index');
        }

        $result = $centralScopeCacheService->refreshSites($currentClient);

        if ($result['error'] !== null) {
            return back()->with('error', $result['error']);
        }

        return back()->with('success', 'Central sites refreshed.');
    }

    public function refreshGroups(Request $request, CentralScopeCacheService $centralScopeCacheService)
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            session()->flash('error', 'Please set current client to refresh Central groups');

            return to_route('clients.index');
        }

        $result = $centralScopeCacheService->refreshGroups($currentClient);

        if ($result['error'] !== null) {
            return back()->with('error', $result['error']);
        }

        if ($result['classic_device_groups_error'] !== null) {
            return back()->with(
                'success',
                'Central groups refreshed, but Classic Central groups could not be loaded.',
            );
        }

        return back()->with('success', 'Central groups refreshed.');
    }
}
