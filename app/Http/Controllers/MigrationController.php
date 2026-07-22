<?php

namespace App\Http\Controllers;

use App\DeviceFunction;
use App\Helper\CentralAPIHelper;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\Site;
use App\Services\ArubaControllerConfigParser;
use App\Services\CentralScopeCacheService;
use App\Services\MigrationDeployService;
use App\Services\MigrationNamedVlanService;
use App\Support\MacAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MigrationController extends Controller
{
    public function index(Request $request, CentralScopeCacheService $centralScopeCacheService)
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            session()->flash('error', 'Please set current client to view migrations');

            return to_route('clients.index');
        }

        return Inertia::render('Migration/Index', $this->migrationPageProps(
            $currentClient,
            $centralScopeCacheService,
        ));
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

        return Inertia::render('Migration/Index', $this->migrationPageProps(
            $currentClient,
            $centralScopeCacheService,
            parsedControllers: $parsedControllers,
        ));
    }

    public function createDeployment(Request $request, CentralScopeCacheService $centralScopeCacheService): Response|\Illuminate\Http\RedirectResponse
    {
        $user = $request->user();
        $currentClient = $user->currentClient();

        if (! $currentClient) {
            session()->flash('error', 'Please set current client before creating deployments');

            return to_route('clients.index');
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'min:3',
                'max:255',
                Rule::unique('deployments', 'name')->where(
                    fn ($query) => $query->where('client_id', $currentClient->id)
                ),
            ],
            'devices' => ['required', 'array', 'min:1'],
            'devices.*.name' => ['required', 'string', 'min:3', 'max:255'],
            'devices.*.serial' => ['required', 'string', 'min:12', 'max:255'],
            'devices.*.mac_address' => [
                'nullable',
                'string',
                'max:17',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || trim($value) === '') {
                        return;
                    }

                    if (! MacAddress::isValid($value)) {
                        $fail('The mac address format is invalid.');
                    }
                },
            ],
            'devices.*.site' => ['nullable', 'string', 'max:255'],
            'devices.*.group' => ['nullable', 'string', 'max:255'],
            'parsed_controllers' => ['sometimes', 'array'],
        ]);

        $parsedControllers = $request->input('parsed_controllers', []);

        $result = DB::transaction(function () use ($validated, $currentClient, $user) {
            $deployment = Deployment::create([
                'name' => $validated['name'],
                'client_id' => $currentClient->id,
            ]);

            foreach ($validated['devices'] as $devicePayload) {
                $mac = $devicePayload['mac_address'] ?? null;
                $normalizedMac = is_string($mac) && trim($mac) !== ''
                    ? MacAddress::normalize($mac)
                    : null;

                $attributes = [
                    'name' => $devicePayload['name'],
                    'serial' => $devicePayload['serial'],
                    'device_function' => DeviceFunction::CAMPUS_AP->name,
                    'client_id' => $currentClient->id,
                    'user_id' => $user->id,
                    'deployment_id' => $deployment->id,
                    'mac_address' => $normalizedMac,
                    'group' => filled($devicePayload['group'] ?? null)
                        ? $devicePayload['group']
                        : null,
                ];

                $device = Device::query()
                    ->where('serial', $devicePayload['serial'])
                    ->where('user_id', $user->id)
                    ->first();

                if ($device) {
                    $device->update($attributes);
                } else {
                    $device = Device::create($attributes);
                }

                $this->applyDeviceSiteLocally($device, $devicePayload['site'] ?? null);
            }

            $deviceCount = $deployment->devices()->count();

            return [
                'deployment' => $deployment,
                'device_count' => $deviceCount,
            ];
        });

        return Inertia::render('Migration/Index', $this->migrationPageProps(
            $currentClient,
            $centralScopeCacheService,
            parsedControllers: is_array($parsedControllers) ? $parsedControllers : [],
            lastCreatedDeployment: [
                'name' => $result['deployment']->name,
                'device_count' => $result['device_count'],
            ],
        ));
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
        $selectedSiteName = collect($centralScopeCacheService->getSiteOptions($currentClient))
            ->firstWhere('siteId', $validated['scope_id'])['siteName'] ?? '';
        $isFreezer = MigrationNamedVlanService::isFreezerSite($selectedSiteName);

        $results = $migrationDeployService->deployAll(
            new CentralAPIHelper($currentClient),
            $validated['scope_id'],
            $validated['profiles'],
            $isFreezer,
        );

        $parsedControllers = $request->input('parsed_controllers', []);

        return Inertia::render('Migration/Index', $this->migrationPageProps(
            $currentClient,
            $centralScopeCacheService,
            parsedControllers: is_array($parsedControllers) ? $parsedControllers : [],
            deployResults: $results['deploy_results'],
            namedVlanDeployResults: $results['named_vlan_deploy_results'],
            selectedScopeId: $validated['scope_id'],
        ));
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
        $selectedSiteName = collect($centralScopeCacheService->getSiteOptions($currentClient))
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

    /**
     * @param  array<int, mixed>  $parsedControllers
     * @param  array<int, mixed>  $deployResults
     * @param  array<int, mixed>  $namedVlanDeployResults
     * @param  array{name: string, device_count: int}|null  $lastCreatedDeployment
     * @return array<string, mixed>
     */
    private function migrationPageProps(
        Client $currentClient,
        CentralScopeCacheService $centralScopeCacheService,
        array $parsedControllers = [],
        array $deployResults = [],
        array $namedVlanDeployResults = [],
        ?array $lastCreatedDeployment = null,
        ?string $selectedScopeId = null,
    ): array {
        $props = [
            'site_options' => $centralScopeCacheService->getSiteOptions($currentClient),
            'device_group_options' => $centralScopeCacheService->getGroups($currentClient)['device_group_options'],
            'parsed_controllers' => $parsedControllers,
            'deploy_results' => $deployResults,
            'named_vlan_deploy_results' => $namedVlanDeployResults,
            'last_created_deployment' => $lastCreatedDeployment,
            ...$centralScopeCacheService->getCacheMetadata($currentClient),
        ];

        if ($selectedScopeId !== null) {
            $props['selected_scope_id'] = $selectedScopeId;
        }

        return $props;
    }

    private function applyDeviceSiteLocally(Device $device, ?string $siteName): void
    {
        if ($siteName === null || $siteName === '') {
            $device->update(['site_id' => null]);

            return;
        }

        $site = Site::firstOrCreateForClient($device->client, $siteName);
        $device->update(['site_id' => $site->id]);
    }
}
