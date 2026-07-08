<?php

namespace App\Http\Controllers;

use App\Helper\CentralAPIHelper;
use App\Services\ArubaControllerConfigParser;
use App\Services\CentralScopeCacheService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MigrationController extends Controller
{
    public function index(Request $request, CentralScopeCacheService $centralScopeCacheService)
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            session()->flash('error', 'Please set current client to view migrations');

            return to_route('clients.index');
        }

        return Inertia::render('Migration/Index', [
            'site_options' => $centralScopeCacheService->getSiteOptions($currentClient),
            'parsed_controllers' => [],
            'deploy_results' => [],
            ...$centralScopeCacheService->getCacheMetadata($currentClient),
        ]);
    }

    public function parse(Request $request, ArubaControllerConfigParser $parser, CentralScopeCacheService $centralScopeCacheService)
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            session()->flash('error', 'Please set current client to parse migrations');

            return to_route('clients.index');
        }

        $validated = $request->validate([
            'config_file' => ['required', 'file', 'mimes:txt,log', 'max:20480'],
        ]);

        $content = file_get_contents($validated['config_file']->getPathname());
        $parsedControllers = $parser->parse($content ?: '');

        if ($parsedControllers === []) {
            return back()->withErrors([
                'config_file' => 'No controller sections found. Expected markers like (CONTROLLER-NAME) #show ap database long.',
            ]);
        }

        return Inertia::render('Migration/Index', [
            'site_options' => $centralScopeCacheService->getSiteOptions($currentClient),
            'parsed_controllers' => $parsedControllers,
            'deploy_results' => [],
            ...$centralScopeCacheService->getCacheMetadata($currentClient),
        ]);
    }

    public function deployWlan(Request $request, CentralScopeCacheService $centralScopeCacheService)
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            session()->flash('error', 'Please set current client to deploy WLAN profiles');

            return to_route('clients.index');
        }

        $validated = $request->validate([
            'scope_id' => ['required', 'string', 'max:255'],
            'profiles' => ['required', 'array', 'min:1'],
            'profiles.*.ssid_profile_name' => ['required', 'string', 'max:255'],
            'profiles.*.body' => ['required', 'array'],
        ]);

        $siteOptions = $centralScopeCacheService->getSiteOptions($currentClient);
        $validScopeIds = array_column($siteOptions, 'siteId');

        if (! in_array($validated['scope_id'], $validScopeIds, true)) {
            return back()->withErrors([
                'scope_id' => 'Selected site is not valid for the current client.',
            ]);
        }

        $helper = new CentralAPIHelper($currentClient);
        $queryParameters = [
            'object-type' => 'LOCAL',
            'view-type' => 'LOCAL',
            'scope-id' => $validated['scope_id'],
            'device-function' => 'CAMPUS_AP',
        ];

        $deployResults = [];

        foreach ($validated['profiles'] as $profile) {
            $ssidProfileName = $profile['ssid_profile_name'];
            $body = $profile['body'];

            $passphrase = $body['personal-security']['wpa-passphrase'] ?? null;
            $vlanName = $body['vlan-name'] ?? null;

            if ($passphrase === null || $passphrase === '' || $vlanName === null || $vlanName === '') {
                $deployResults[] = [
                    'ssid' => $ssidProfileName,
                    'status' => 'skipped',
                    'message' => 'Missing required wpa-passphrase or vlan-name',
                ];

                continue;
            }

            $response = $helper->post_wlan_ssid_profile($ssidProfileName, $queryParameters, $body);

            if (is_array($response) && array_key_exists('error', $response)) {
                $deployResults[] = [
                    'ssid' => $ssidProfileName,
                    'status' => 'error',
                    'message' => (string) $response['error'],
                ];

                continue;
            }

            if ($response->successful()) {
                $deployResults[] = [
                    'ssid' => $ssidProfileName,
                    'status' => 'success',
                    'message' => 'Deployed successfully',
                ];
            } else {
                $deployResults[] = [
                    'ssid' => $ssidProfileName,
                    'status' => 'error',
                    'message' => $response->body() ?: 'Request failed with status '.$response->status(),
                ];
            }
        }

        $parsedControllers = $request->input('parsed_controllers', []);

        return Inertia::render('Migration/Index', [
            'site_options' => $siteOptions,
            'parsed_controllers' => is_array($parsedControllers) ? $parsedControllers : [],
            'deploy_results' => $deployResults,
            'selected_scope_id' => $validated['scope_id'],
            ...$centralScopeCacheService->getCacheMetadata($currentClient),
        ]);
    }
}
