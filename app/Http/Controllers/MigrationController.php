<?php

namespace App\Http\Controllers;

use App\Helper\CentralAPIHelper;
use App\Services\ArubaControllerConfigParser;
use App\Services\CentralScopeCacheService;
use App\Services\MigrationDeployService;
use App\Services\MigrationNamedVlanService;
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
            'named_vlan_deploy_results' => [],
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
            'named_vlan_deploy_results' => [],
            ...$centralScopeCacheService->getCacheMetadata($currentClient),
        ]);
    }

    public function deployWlan(
        Request $request,
        CentralScopeCacheService $centralScopeCacheService,
        MigrationDeployService $migrationDeployService,
    ) {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            session()->flash('error', 'Please set current client to deploy WLAN profiles');

            return to_route('clients.index');
        }

        $validated = $this->validateDeployRequest($request, $centralScopeCacheService);
        $siteOptions = $centralScopeCacheService->getSiteOptions($currentClient);
        $selectedSiteName = collect($siteOptions)
            ->firstWhere('siteId', $validated['scope_id'])['siteName'] ?? '';
        $isFreezer = MigrationNamedVlanService::isFreezerSite($selectedSiteName);

        $results = $migrationDeployService->deployAll(
            new CentralAPIHelper($currentClient),
            $validated['scope_id'],
            $validated['profiles'],
            $isFreezer,
        );

        $parsedControllers = $request->input('parsed_controllers', []);

        return Inertia::render('Migration/Index', [
            'site_options' => $siteOptions,
            'parsed_controllers' => is_array($parsedControllers) ? $parsedControllers : [],
            'deploy_results' => $results['deploy_results'],
            'named_vlan_deploy_results' => $results['named_vlan_deploy_results'],
            'selected_scope_id' => $validated['scope_id'],
            ...$centralScopeCacheService->getCacheMetadata($currentClient),
        ]);
    }

    public function deployWlanStep(
        Request $request,
        int $step,
        CentralScopeCacheService $centralScopeCacheService,
        MigrationDeployService $migrationDeployService,
    ) {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            return response()->json(['message' => 'Please set current client to deploy WLAN profiles.'], 403);
        }

        $validated = $this->validateDeployRequest($request, $centralScopeCacheService);
        $siteOptions = $centralScopeCacheService->getSiteOptions($currentClient);
        $selectedSiteName = collect($siteOptions)
            ->firstWhere('siteId', $validated['scope_id'])['siteName'] ?? '';
        $isFreezer = MigrationNamedVlanService::isFreezerSite($selectedSiteName);

        $context = $validated['context'] ?? [];
        $namedVlanProfiles = $context['named_vlan_profiles'] ?? null;
        $total = $migrationDeployService->totalSteps(
            $validated['profiles'],
            $isFreezer,
            is_array($namedVlanProfiles) ? $namedVlanProfiles : null,
        );

        if ($step < 0 || $step >= $total) {
            abort(404);
        }

        return response()->json(
            $migrationDeployService->runStep(
                new CentralAPIHelper($currentClient),
                $validated['scope_id'],
                $validated['profiles'],
                $step,
                is_array($context) ? $context : [],
                $isFreezer,
            ),
        );
    }

    /**
     * @return array{
     *     scope_id: string,
     *     profiles: array<int, array{ssid_profile_name: string, body: array<string, mixed>}>,
     *     context?: array{named_vlan_profiles?: array<int, array<string, mixed>>}
     * }
     */
    private function validateDeployRequest(Request $request, CentralScopeCacheService $centralScopeCacheService): array
    {
        $currentClient = $request->user()->currentClient();

        $validated = $request->validate([
            'scope_id' => ['required', 'string', 'max:255'],
            'profiles' => ['required', 'array', 'min:1'],
            'profiles.*.ssid_profile_name' => ['required', 'string', 'max:255'],
            'profiles.*.body' => ['required', 'array'],
            'context' => ['sometimes', 'array'],
            'context.named_vlan_profiles' => ['sometimes', 'array'],
        ]);

        $siteOptions = $centralScopeCacheService->getSiteOptions($currentClient);
        $validScopeIds = array_column($siteOptions, 'siteId');

        if (! in_array($validated['scope_id'], $validScopeIds, true)) {
            abort(422, 'Selected site is not valid for the current client.');
        }

        return $validated;
    }
}
