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

    /**
     * @return array<string, mixed>
     */
    public function run(Deployment $deployment, CentralAPIHelper $helper): array
    {
        $devices = $deployment->devices()
            ->with([
                'interfaces.lacp_profile',
                'interfaces.switch_port',
                'interfaces.stp_profile',
                'site',
            ])
            ->get();

        $lagInterfaces = $this->collectLagInterfaces($devices);
        $vlanInterfaces = $this->collectVlanInterfaces($devices);

        $lagVerification = $this->lagVerifier->verifyInterfaces($lagInterfaces, $helper);
        $vlanVerification = $this->vlanVerifier->verifyInterfaces($vlanInterfaces, $helper);

        $staticRoutes = $this->fetchStaticRoutesForDevices($devices, $helper);
        $dnsResult = $this->fetchDnsForDevices($devices, $helper);

        $lagResults = $lagVerification['results'];
        $vlanResults = $vlanVerification['results'];

        return [
            'lag_device_errors' => $lagVerification['device_errors'],
            'vlan_device_errors' => $vlanVerification['device_errors'],
            'lag_results' => $lagResults,
            'vlan_results' => $vlanResults,
            'static_routes' => $staticRoutes,
            'dns_scope_id' => $dnsResult['dns_scope_id'],
            'dns_scope_error' => $dnsResult['dns_scope_error'],
            'dns_results' => $dnsResult['dns_results'],
            'summary' => [
                'lag_total' => count($lagResults),
                'lag_passed' => collect($lagResults)->where('ok', true)->count(),
                'lag_failed' => collect($lagResults)->where('ok', false)->count(),
                'vlan_total' => count($vlanResults),
                'vlan_passed' => collect($vlanResults)->where('ok', true)->count(),
                'vlan_failed' => collect($vlanResults)->where('ok', false)->count(),
            ],
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
     * @param  Collection<int, Device>  $devices
     * @return list<array{device_id: int, device_name: string, error: string|null, routes: list<array{profile_name: string, prefix: string}>}>
     */
    protected function fetchStaticRoutesForDevices(Collection $devices, CentralAPIHelper $helper): array
    {
        $results = [];

        foreach ($devices as $device) {
            $site = $device->site;
            if ($site === null || blank($site->scope_id)) {
                $results[] = [
                    'device_id' => $device->id,
                    'device_name' => $device->name,
                    'error' => 'Site or site scope ID not available for this device.',
                    'routes' => [],
                ];

                continue;
            }

            if (! $device->device_function) {
                $results[] = [
                    'device_id' => $device->id,
                    'device_name' => $device->name,
                    'error' => 'Device function not available for this device.',
                    'routes' => [],
                ];

                continue;
            }

            $response = $helper->get_static_route([
                'object-type' => 'SHARED',
                'view-type' => 'LOCAL',
                'scope-id' => $site->scope_id,
                'device-function' => LagInterfaceCentralVerifier::deviceFunctionQueryValue($device),
            ]);

            if (! $this->responseOk($response)) {
                $message = $this->responseErrorMessage($response, 'Failed to fetch static routes from Central.');

                $results[] = [
                    'device_id' => $device->id,
                    'device_name' => $device->name,
                    'error' => $message,
                    'routes' => [],
                ];

                continue;
            }

            $results[] = [
                'device_id' => $device->id,
                'device_name' => $device->name,
                'error' => null,
                'routes' => $this->parseStaticRouteProfiles($response instanceof Response ? $response->json() : []),
            ];
        }

        return $results;
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @return array{dns_scope_id: string|null, dns_scope_error: string|null, dns_results: list<array<string, mixed>>}
     */
    protected function fetchDnsForDevices(Collection $devices, CentralAPIHelper $helper): array
    {
        $probeDevice = $devices->first(fn (Device $device) => filled($device->device_function));

        if ($probeDevice === null) {
            return [
                'dns_scope_id' => self::DEFAULT_DNS_SCOPE_ID,
                'dns_scope_error' => 'No devices with a device function found in this deployment.',
                'dns_results' => [],
            ];
        }

        $scopeResolution = $this->resolveDnsScopeId($helper, $probeDevice);

        if (isset($scopeResolution['error'])) {
            return [
                'dns_scope_id' => $scopeResolution['attempted_scope_id'] ?? self::DEFAULT_DNS_SCOPE_ID,
                'dns_scope_error' => $scopeResolution['error'],
                'dns_results' => [],
            ];
        }

        $dnsScopeId = $scopeResolution['scope_id'];
        $dnsResults = [];

        foreach ($devices as $device) {
            if (! $device->device_function) {
                $dnsResults[] = [
                    'device_id' => $device->id,
                    'device_name' => $device->name,
                    'error' => 'Device function not available for this device.',
                    'profiles' => [],
                ];

                continue;
            }

            $response = $helper->get_dns_profiles([
                'object-type' => 'SHARED',
                'view-type' => 'LOCAL',
                'scope-id' => $dnsScopeId,
                'device-function' => LagInterfaceCentralVerifier::deviceFunctionQueryValue($device),
            ]);

            if (! $this->responseOk($response)) {
                $dnsResults[] = [
                    'device_id' => $device->id,
                    'device_name' => $device->name,
                    'error' => $this->responseErrorMessage($response, 'Failed to fetch DNS profiles from Central.'),
                    'profiles' => [],
                ];

                continue;
            }

            $dnsResults[] = [
                'device_id' => $device->id,
                'device_name' => $device->name,
                'error' => null,
                'profiles' => $this->parseDnsProfiles($response instanceof Response ? $response->json() : []),
            ];
        }

        return [
            'dns_scope_id' => $dnsScopeId,
            'dns_scope_error' => null,
            'dns_results' => $dnsResults,
        ];
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
            'device-function' => LagInterfaceCentralVerifier::deviceFunctionQueryValue($probeDevice),
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
            'device-function' => LagInterfaceCentralVerifier::deviceFunctionQueryValue($probeDevice),
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
