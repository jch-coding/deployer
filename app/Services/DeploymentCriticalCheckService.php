<?php

namespace App\Services;

use App\Helper\CentralAPIHelper;
use App\InterfaceKind;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\DeviceInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;

class DeploymentCriticalCheckService
{
    private const DEFAULT_DNS_SCOPE_ID = '73800600944427008';

    private const WCD_SITE_COLLECTION_NAME = 'WCD';

    public function __construct(
        protected LagInterfaceCentralVerifier $lagVerifier = new LagInterfaceCentralVerifier,
        protected VlanInterfaceCentralVerifier $vlanVerifier = new VlanInterfaceCentralVerifier,
    ) {}

    public function totalSteps(Deployment $deployment): int
    {
        return 1 + ($this->loadDevices($deployment)->count() * 4);
    }

    /**
     * @return array<string, mixed>
     */
    public function emptyResults(): array
    {
        return [
            'lag_device_errors' => [],
            'vlan_device_errors' => [],
            'lag_results' => [],
            'vlan_results' => [],
            'static_routes' => [],
            'dns_scope_id' => null,
            'dns_scope_error' => null,
            'dns_results' => [],
            'summary' => [
                'lag_total' => 0,
                'lag_passed' => 0,
                'lag_failed' => 0,
                'vlan_total' => 0,
                'vlan_passed' => 0,
                'vlan_failed' => 0,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{
     *     progress: array{current: int, total: int, percent: int, message: string},
     *     partial: array<string, mixed>,
     *     context: array<string, mixed>,
     *     done: bool
     * }
     */
    public function runStep(Deployment $deployment, CentralAPIHelper $helper, int $step, array $context = []): array
    {
        $devices = $this->loadDevices($deployment);
        $total = $this->totalStepsForDevices($devices);
        $message = $this->messageForStep($devices, $step);
        $partial = [];
        $context = array_merge([
            'dns_scope_id' => null,
            'dns_scope_error' => null,
        ], $context);

        if ($step === 0) {
            $dnsScope = $this->resolveDnsScopeForDevices($devices, $helper);
            $context['dns_scope_id'] = $dnsScope['dns_scope_id'];
            $context['dns_scope_error'] = $dnsScope['dns_scope_error'];
            $partial = [
                'dns_scope_id' => $dnsScope['dns_scope_id'],
                'dns_scope_error' => $dnsScope['dns_scope_error'],
            ];
        } elseif ($devices->isEmpty()) {
            $partial = [];
        } else {
            $deviceIndex = intdiv($step - 1, 4);
            $phase = ($step - 1) % 4;
            /** @var Device $device */
            $device = $devices->values()->get($deviceIndex);
            $deviceCollection = collect([$device]);

            $partial = match ($phase) {
                0 => $this->verifyLagForDevices($deviceCollection, $helper),
                1 => $this->verifyVlanForDevices($deviceCollection, $helper),
                2 => ['static_routes' => [$this->fetchStaticRouteForDevice($device, $helper)]],
                3 => $this->fetchDnsForDeviceStep($device, $helper, $context),
                default => [],
            };
        }

        $current = min($step + 1, $total);

        return [
            'progress' => [
                'current' => $current,
                'total' => $total,
                'percent' => $total > 0 ? (int) round(($current / $total) * 100) : 100,
                'message' => $message,
            ],
            'partial' => $partial,
            'context' => $context,
            'done' => $current >= $total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function run(Deployment $deployment, CentralAPIHelper $helper): array
    {
        $results = $this->emptyResults();
        $context = [];
        $total = $this->totalSteps($deployment);

        for ($step = 0; $step < $total; $step++) {
            $stepResult = $this->runStep($deployment, $helper, $step, $context);
            $context = $stepResult['context'];
            $results = $this->mergePartialResults($results, $stepResult['partial']);
        }

        $results['summary'] = $this->buildSummary(
            $results['lag_results'],
            $results['vlan_results'],
        );

        return $results;
    }

    /**
     * @param  array<string, mixed>  $accumulated
     * @param  array<string, mixed>  $partial
     * @return array<string, mixed>
     */
    public function mergePartialResults(array $accumulated, array $partial): array
    {
        foreach (['lag_device_errors', 'vlan_device_errors', 'lag_results', 'vlan_results', 'static_routes', 'dns_results'] as $listKey) {
            if (! array_key_exists($listKey, $partial)) {
                continue;
            }
            $accumulated[$listKey] = array_merge($accumulated[$listKey] ?? [], $partial[$listKey]);
        }

        if (array_key_exists('dns_scope_id', $partial)) {
            $accumulated['dns_scope_id'] = $partial['dns_scope_id'];
        }
        if (array_key_exists('dns_scope_error', $partial)) {
            $accumulated['dns_scope_error'] = $partial['dns_scope_error'];
        }

        $accumulated['summary'] = $this->buildSummary(
            $accumulated['lag_results'] ?? [],
            $accumulated['vlan_results'] ?? [],
        );

        return $accumulated;
    }

    /**
     * @param  list<array<string, mixed>>  $lagResults
     * @param  list<array<string, mixed>>  $vlanResults
     * @return array{lag_total: int, lag_passed: int, lag_failed: int, vlan_total: int, vlan_passed: int, vlan_failed: int}
     */
    public function buildSummary(array $lagResults, array $vlanResults): array
    {
        return [
            'lag_total' => count($lagResults),
            'lag_passed' => collect($lagResults)->where('ok', true)->count(),
            'lag_failed' => collect($lagResults)->where('ok', false)->count(),
            'vlan_total' => count($vlanResults),
            'vlan_passed' => collect($vlanResults)->where('ok', true)->count(),
            'vlan_failed' => collect($vlanResults)->where('ok', false)->count(),
        ];
    }

    /**
     * @param  Collection<int, Device>  $devices
     */
    protected function totalStepsForDevices(Collection $devices): int
    {
        return 1 + ($devices->count() * 4);
    }

    /**
     * @param  Collection<int, Device>  $devices
     */
    protected function messageForStep(Collection $devices, int $step): string
    {
        if ($step === 0) {
            return 'Resolving DNS scope ID...';
        }

        if ($devices->isEmpty()) {
            return 'No devices in deployment.';
        }

        $deviceIndex = intdiv($step - 1, 4);
        $phase = ($step - 1) % 4;
        $device = $devices->values()->get($deviceIndex);
        $name = $device?->name ?? 'device';

        return match ($phase) {
            0 => "Checking LAG interfaces for {$name}...",
            1 => "Checking VLAN interfaces for {$name}...",
            2 => "Fetching static routes for {$name}...",
            3 => "Fetching DNS profiles for {$name}...",
            default => 'Running check...',
        };
    }

    /**
     * @return Collection<int, Device>
     */
    protected function loadDevices(Deployment $deployment): Collection
    {
        return $deployment->devices()
            ->with([
                'interfaces.lacp_profile',
                'interfaces.switch_port',
                'interfaces.stp_profile',
                'site',
            ])
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @return array{lag_device_errors: array, lag_results: array}
     */
    protected function verifyLagForDevices(Collection $devices, CentralAPIHelper $helper): array
    {
        $interfaces = $this->collectLagInterfaces($devices);
        $verification = $this->lagVerifier->verifyInterfaces($interfaces, $helper);

        return [
            'lag_device_errors' => $verification['device_errors'],
            'lag_results' => $verification['results'],
        ];
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @return array{vlan_device_errors: array, vlan_results: array}
     */
    protected function verifyVlanForDevices(Collection $devices, CentralAPIHelper $helper): array
    {
        $interfaces = $this->collectVlanInterfaces($devices);
        $verification = $this->vlanVerifier->verifyInterfaces($interfaces, $helper);

        return [
            'vlan_device_errors' => $verification['device_errors'],
            'vlan_results' => $verification['results'],
        ];
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @return array{dns_scope_id: string|null, dns_scope_error: string|null}
     */
    protected function resolveDnsScopeForDevices(Collection $devices, CentralAPIHelper $helper): array
    {
        $probeDevice = $devices->first(fn (Device $device) => filled($device->device_function));

        if ($probeDevice === null) {
            return [
                'dns_scope_id' => self::DEFAULT_DNS_SCOPE_ID,
                'dns_scope_error' => 'No devices with a device function found in this deployment.',
            ];
        }

        $scopeResolution = $this->resolveDnsScopeId($helper, $probeDevice);

        if (isset($scopeResolution['error'])) {
            return [
                'dns_scope_id' => $scopeResolution['attempted_scope_id'] ?? self::DEFAULT_DNS_SCOPE_ID,
                'dns_scope_error' => $scopeResolution['error'],
            ];
        }

        return [
            'dns_scope_id' => $scopeResolution['scope_id'],
            'dns_scope_error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{dns_results: list<array<string, mixed>>}
     */
    protected function fetchDnsForDeviceStep(Device $device, CentralAPIHelper $helper, array $context): array
    {
        if (! empty($context['dns_scope_error'])) {
            return [
                'dns_results' => [],
            ];
        }

        $dnsScopeId = $context['dns_scope_id'] ?? self::DEFAULT_DNS_SCOPE_ID;

        if (! $device->device_function) {
            return [
                'dns_results' => [[
                    'device_id' => $device->id,
                    'device_name' => $device->name,
                    'error' => 'Device function not available for this device.',
                    'profiles' => [],
                ]],
            ];
        }

        $response = $helper->get_dns_profiles([
            'object-type' => 'SHARED',
            'view-type' => 'LOCAL',
            'scope-id' => $dnsScopeId,
            'device-function' => CentralAPIHelper::deviceFunctionQueryValue($device),
        ]);

        if (! $this->responseOk($response)) {
            return [
                'dns_results' => [[
                    'device_id' => $device->id,
                    'device_name' => $device->name,
                    'error' => $this->responseErrorMessage($response, 'Failed to fetch DNS profiles from Central.'),
                    'profiles' => [],
                ]],
            ];
        }

        return [
            'dns_results' => [[
                'device_id' => $device->id,
                'device_name' => $device->name,
                'error' => null,
                'profiles' => $this->parseDnsProfiles($response instanceof Response ? $response->json() : []),
            ]],
        ];
    }

    /**
     * @return array{device_id: int, device_name: string, error: string|null, routes: list<array{profile_name: string, prefix: string}>}
     */
    protected function fetchStaticRouteForDevice(Device $device, CentralAPIHelper $helper): array
    {
        $site = $device->site;
        if ($site === null || blank($site->scope_id)) {
            return [
                'device_id' => $device->id,
                'device_name' => $device->name,
                'error' => 'Site or site scope ID not available for this device.',
                'routes' => [],
            ];
        }

        if (! $device->device_function) {
            return [
                'device_id' => $device->id,
                'device_name' => $device->name,
                'error' => 'Device function not available for this device.',
                'routes' => [],
            ];
        }

        $response = $helper->get_static_route([
            'object-type' => 'SHARED',
            'view-type' => 'LOCAL',
            'scope-id' => $site->scope_id,
            'device-function' => CentralAPIHelper::deviceFunctionQueryValue($device),
        ]);

        if (! $this->responseOk($response)) {
            return [
                'device_id' => $device->id,
                'device_name' => $device->name,
                'error' => $this->responseErrorMessage($response, 'Failed to fetch static routes from Central.'),
                'routes' => [],
            ];
        }

        return [
            'device_id' => $device->id,
            'device_name' => $device->name,
            'error' => null,
            'routes' => $this->parseStaticRouteProfiles($response instanceof Response ? $response->json() : []),
        ];
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @return Collection<int, DeviceInterface>
     */
    protected function collectLagInterfaces(Collection $devices): Collection
    {
        return $devices->flatMap(function (Device $device) {
            return $device->interfaces->filter(
                fn (DeviceInterface $iface) => $iface->interface_kind === InterfaceKind::LAG
                    || $iface->lacp_profile_id !== null
            );
        })->values();
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @return Collection<int, DeviceInterface>
     */
    protected function collectVlanInterfaces(Collection $devices): Collection
    {
        return $devices->flatMap(function (Device $device) {
            return $device->interfaces->filter(
                fn (DeviceInterface $iface) => $iface->interface_kind === InterfaceKind::VLAN
            );
        })->values();
    }

    /**
     * @return array{scope_id: string}|array{error: string, attempted_scope_id?: string}
     */
    protected function resolveDnsScopeId(CentralAPIHelper $helper, Device $probeDevice): array
    {
        $dnsScopeId = self::DEFAULT_DNS_SCOPE_ID;

        $probeResponse = $helper->get_dns_profiles([
            'object-type' => 'SHARED',
            'view-type' => 'LOCAL',
            'scope-id' => $dnsScopeId,
            'device-function' => CentralAPIHelper::deviceFunctionQueryValue($probeDevice),
        ]);

        if ($this->responseOk($probeResponse)) {
            return ['scope_id' => $dnsScopeId];
        }

        $collectionsResponse = $helper->get_site_collections();

        if (! $this->responseOk($collectionsResponse)) {
            return [
                'error' => $this->responseErrorMessage($collectionsResponse, 'Failed to fetch site collections from Central for DNS scope resolution.'),
                'attempted_scope_id' => $dnsScopeId,
            ];
        }

        $items = $collectionsResponse instanceof Response ? $collectionsResponse->json('items', []) : [];
        if (! is_array($items)) {
            $items = [];
        }

        $wcd = collect($items)->first(
            fn ($item) => is_array($item) && ($item['scopeName'] ?? '') === self::WCD_SITE_COLLECTION_NAME
        );

        if (! is_array($wcd) || blank($wcd['scopeId'] ?? null)) {
            return [
                'error' => 'Site collection "'.self::WCD_SITE_COLLECTION_NAME.'" was not found in Central.',
                'attempted_scope_id' => $dnsScopeId,
            ];
        }

        $dnsScopeId = (string) $wcd['scopeId'];

        $retryResponse = $helper->get_dns_profiles([
            'object-type' => 'SHARED',
            'view-type' => 'LOCAL',
            'scope-id' => $dnsScopeId,
            'device-function' => CentralAPIHelper::deviceFunctionQueryValue($probeDevice),
        ]);

        if (! $this->responseOk($retryResponse)) {
            return [
                'error' => $this->responseErrorMessage($retryResponse, 'Failed to fetch DNS profiles from Central after resolving WCD scope ID.'),
                'attempted_scope_id' => $dnsScopeId,
            ];
        }

        return ['scope_id' => $dnsScopeId];
    }

    /**
     * @param  array<string, mixed>  $json
     * @return list<array{profile_name: string, prefix: string}>
     */
    protected function parseStaticRouteProfiles(array $json): array
    {
        $profiles = $json['profile'] ?? [];
        if (! is_array($profiles)) {
            return [];
        }

        if ($this->isAssociativeArray($profiles) && array_key_exists('name', $profiles)) {
            $profiles = [$profiles];
        }

        $routes = [];
        foreach ($profiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }
            $profileName = (string) ($profile['name'] ?? '');
            $ipv4 = $profile['ipv4'] ?? [];
            $routes = array_merge($routes, $this->parseStaticRouteIpv4Entries($profileName, $ipv4));
        }

        return $routes;
    }

    /**
     * @return list<array{profile_name: string, prefix: string}>
     */
    protected function parseStaticRouteIpv4Entries(string $profileName, mixed $ipv4): array
    {
        if (! is_array($ipv4)) {
            return [];
        }

        if ($this->isAssociativeArray($ipv4) && array_key_exists('prefix', $ipv4)) {
            return [[
                'profile_name' => $profileName,
                'prefix' => (string) ($ipv4['prefix'] ?? ''),
            ]];
        }

        $routes = [];
        foreach ($ipv4 as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $routes[] = [
                'profile_name' => $profileName,
                'prefix' => (string) ($entry['prefix'] ?? ''),
            ];
        }

        return $routes;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return list<array{name: string, resolvers: list<array{vrf: string, name_server_ips: list<string>}>}>
     */
    protected function parseDnsProfiles(array $json): array
    {
        $profiles = $json['profile'] ?? [];
        if (! is_array($profiles)) {
            return [];
        }

        if ($this->isAssociativeArray($profiles) && array_key_exists('name', $profiles)) {
            $profiles = [$profiles];
        }

        $parsed = [];
        foreach ($profiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $resolvers = [];
            foreach ($profile['resolver'] ?? [] as $resolver) {
                if (! is_array($resolver)) {
                    continue;
                }
                $ips = [];
                foreach ($resolver['name-server'] ?? [] as $nameServer) {
                    if (is_array($nameServer) && isset($nameServer['ip'])) {
                        $ips[] = (string) $nameServer['ip'];
                    }
                }
                $resolvers[] = [
                    'vrf' => (string) ($resolver['vrf'] ?? ''),
                    'name_server_ips' => $ips,
                ];
            }

            $parsed[] = [
                'name' => (string) ($profile['name'] ?? ''),
                'resolvers' => $resolvers,
            ];
        }

        return $parsed;
    }

    protected function responseOk(mixed $response): bool
    {
        if ($response instanceof Response) {
            return $response->ok();
        }

        if (is_array($response) && array_key_exists('error', $response)) {
            return false;
        }

        return false;
    }

    protected function responseErrorMessage(mixed $response, string $fallback): string
    {
        if ($response instanceof Response) {
            $message = (string) ($response->json('message') ?? $response->body());

            return $message !== '' ? $message : $fallback;
        }

        if (is_array($response) && isset($response['error'])) {
            return (string) $response['error'];
        }

        return $fallback;
    }

    protected function isAssociativeArray(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }
}
