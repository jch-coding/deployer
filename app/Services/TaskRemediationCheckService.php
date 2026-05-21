<?php

namespace App\Services;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\Task;
use Illuminate\Support\Collection;

class TaskRemediationCheckService
{
    public function __construct(
        protected DeploymentCriticalCheckService $deploymentCheck = new DeploymentCriticalCheckService,
        protected LagInterfaceCentralVerifier $lagVerifier = new LagInterfaceCentralVerifier,
        protected EthernetInterfaceCentralVerifier $ethernetVerifier = new EthernetInterfaceCentralVerifier,
        protected VlanInterfaceCentralVerifier $vlanVerifier = new VlanInterfaceCentralVerifier,
    ) {}

    /**
     * @param  Collection<int, Task>  $siblings
     */
    public function totalSteps(Collection $siblings): int
    {
        $scope = $this->buildScope($siblings);

        return 1 + ($scope['devices']->count() * $this->phasesPerDevice($scope['include_ethernet']));
    }

    /**
     * @param  Collection<int, Task>  $siblings
     * @param  array<string, mixed>  $context
     * @return array{
     *     progress: array{current: int, total: int, percent: int, message: string},
     *     partial: array<string, mixed>,
     *     context: array<string, mixed>,
     *     done: bool
     * }
     */
    public function runStep(Collection $siblings, CentralAPIHelper $helper, int $step, array $context = []): array
    {
        $scope = $this->buildScope($siblings);
        $devices = $scope['devices'];
        $includeEthernet = $scope['include_ethernet'];
        $total = $this->totalSteps($siblings);
        $context = array_merge([
            'dns_scope_id' => null,
            'dns_scope_error' => null,
            'include_ethernet' => $includeEthernet,
        ], $context);

        $partial = [];

        if ($step === 0) {
            $dnsScope = $this->resolveDnsScopePartial($devices, $helper);
            $partial = $dnsScope;
            $context['dns_scope_id'] = $dnsScope['dns_scope_id'] ?? null;
            $context['dns_scope_error'] = $dnsScope['dns_scope_error'] ?? null;
            $message = 'Resolving DNS scope ID...';
        } elseif ($devices->isEmpty()) {
            $message = 'No devices in remediation scope.';
        } else {
            $phasesPerDevice = $this->phasesPerDevice($includeEthernet);
            $deviceIndex = intdiv($step - 1, $phasesPerDevice);
            $phase = ($step - 1) % $phasesPerDevice;
            /** @var Device $device */
            $device = $devices->values()->get($deviceIndex);
            $phaseName = $this->phaseNameForIndex($includeEthernet, $phase);
            $message = $this->messageForPhase($device, $phaseName);

            $partial = match ($phaseName) {
                'lag' => $this->verifyLagForDevice($device, $scope['lag_interface_ids'], $helper),
                'ethernet' => $this->verifyEthernetForDevice($device, $scope['ethernet_interface_ids'], $helper),
                'vlan' => $this->verifyVlanForDevice($device, $scope['vlan_interface_ids'], $helper),
                'static' => $this->staticPartialForDevice($device, $scope['static_device_ids'], $helper),
                'dns' => $this->dnsPartialForDevice($device, $scope['dns_device_ids'], $helper, $context),
                default => [],
            };
        }

        $partial = is_array($partial) && array_key_exists('lag_results', $partial)
            ? $partial
            : $this->deploymentCheck->mergePartialResults($this->deploymentCheck->emptyResults(), $partial);

        $current = min($step + 1, $total);

        return [
            'progress' => [
                'current' => $current,
                'total' => $total,
                'percent' => $total > 0 ? (int) round(($current / $total) * 100) : 100,
                'message' => $message ?? 'Running remediation check...',
            ],
            'partial' => $partial,
            'context' => $context,
            'done' => $current >= $total,
        ];
    }

    /**
     * @param  Collection<int, Task>  $siblings
     * @return array{
     *     devices: Collection<int, Device>,
     *     include_ethernet: bool,
     *     lag_interface_ids: Collection<int, int>,
     *     ethernet_interface_ids: Collection<int, int>,
     *     vlan_interface_ids: Collection<int, int>,
     *     static_device_ids: Collection<int, int>,
     *     dns_device_ids: Collection<int, int>
     * }
     */
    public function buildScope(Collection $siblings): array
    {
        $siblings->load(['deviceInterfaces', 'devices']);

        $first = $siblings->first();
        $context = is_array($first?->remediation_context) ? $first->remediation_context : [];
        $includeEthernet = (bool) ($context['include_ethernet'] ?? false);

        $lagInterfaceIds = collect();
        $ethernetInterfaceIds = collect();
        $vlanInterfaceIds = collect();
        $staticDeviceIds = collect();
        $dnsDeviceIds = collect();

        foreach ($siblings as $sub) {
            match ($sub->task_type) {
                'CONFIGURE_LAG_INTERFACE' => $lagInterfaceIds = $lagInterfaceIds->merge(
                    $sub->deviceInterfaces->pluck('id')
                ),
                'CONFIGURE_ETHERNET_INTERFACE' => $ethernetInterfaceIds = $ethernetInterfaceIds->merge(
                    $sub->deviceInterfaces->pluck('id')
                ),
                'CONFIGURE_VLAN_INTERFACE' => $vlanInterfaceIds = $vlanInterfaceIds->merge(
                    $sub->deviceInterfaces->pluck('id')
                ),
                'REMOVE_LOCAL_OVERRIDE_STATIC_ROUTE' => $staticDeviceIds = $staticDeviceIds->merge(
                    $sub->devices->pluck('id')
                ),
                'REMOVE_LOCAL_OVERRIDE_DNS_PROFILE' => $dnsDeviceIds = $dnsDeviceIds->merge(
                    $sub->devices->pluck('id')
                ),
                default => null,
            };
        }

        $lagInterfaceIds = $lagInterfaceIds->unique()->values();
        $ethernetInterfaceIds = $ethernetInterfaceIds->unique()->values();
        $vlanInterfaceIds = $vlanInterfaceIds->unique()->values();
        $staticDeviceIds = $staticDeviceIds->unique()->values();
        $dnsDeviceIds = $dnsDeviceIds->unique()->values();

        $deviceIds = collect()
            ->merge(
                DeviceInterface::query()->whereIn('id', $lagInterfaceIds->merge($ethernetInterfaceIds)->merge($vlanInterfaceIds))->pluck('device_id')
            )
            ->merge($staticDeviceIds)
            ->merge($dnsDeviceIds)
            ->unique()
            ->values();

        $devices = Device::query()
            ->whereIn('id', $deviceIds)
            ->with(['site', 'interfaces.lacp_profile', 'interfaces.switch_port', 'interfaces.stp_profile'])
            ->orderBy('name')
            ->get();

        return [
            'devices' => $devices,
            'include_ethernet' => $includeEthernet,
            'lag_interface_ids' => $lagInterfaceIds,
            'ethernet_interface_ids' => $ethernetInterfaceIds,
            'vlan_interface_ids' => $vlanInterfaceIds,
            'static_device_ids' => $staticDeviceIds,
            'dns_device_ids' => $dnsDeviceIds,
        ];
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @return array<string, mixed>
     */
    /**
     * @param  Collection<int, Device>  $devices
     * @return array<string, mixed>
     */
    protected function resolveDnsScopePartial(Collection $devices, CentralAPIHelper $helper): array
    {
        $reflection = new \ReflectionClass($this->deploymentCheck);
        $method = $reflection->getMethod('resolveDnsScopeForDevices');
        $method->setAccessible(true);
        $dnsScope = $method->invoke($this->deploymentCheck, $devices, $helper);

        return [
            'dns_scope_id' => $dnsScope['dns_scope_id'],
            'dns_scope_error' => $dnsScope['dns_scope_error'],
            'dns_site_collection_name' => $dnsScope['dns_site_collection_name'],
        ];
    }

    /**
     * @param  Collection<int, int>  $interfaceIds
     * @return array<string, mixed>
     */
    protected function verifyLagForDevice(Device $device, Collection $interfaceIds, CentralAPIHelper $helper): array
    {
        $interfaces = $device->interfaces->whereIn('id', $interfaceIds);
        if ($interfaces->isEmpty()) {
            return [];
        }

        $verification = $this->lagVerifier->verifyInterfaces($interfaces, $helper);

        return [
            'lag_device_errors' => $verification['device_errors'],
            'lag_results' => $verification['results'],
        ];
    }

    /**
     * @param  Collection<int, int>  $interfaceIds
     * @return array<string, mixed>
     */
    protected function verifyEthernetForDevice(Device $device, Collection $interfaceIds, CentralAPIHelper $helper): array
    {
        $interfaces = $device->interfaces->whereIn('id', $interfaceIds);
        if ($interfaces->isEmpty()) {
            return [];
        }

        $verification = $this->ethernetVerifier->verifyInterfaces($interfaces, $helper);

        return [
            'ethernet_device_errors' => $verification['device_errors'],
            'ethernet_results' => $verification['results'],
        ];
    }

    /**
     * @param  Collection<int, int>  $interfaceIds
     * @return array<string, mixed>
     */
    protected function verifyVlanForDevice(Device $device, Collection $interfaceIds, CentralAPIHelper $helper): array
    {
        $interfaces = $device->interfaces->whereIn('id', $interfaceIds);
        if ($interfaces->isEmpty()) {
            return [];
        }

        $verification = $this->vlanVerifier->verifyInterfaces($interfaces, $helper);

        return [
            'vlan_device_errors' => $verification['device_errors'],
            'vlan_results' => $verification['results'],
        ];
    }

    /**
     * @param  Collection<int, int>  $staticDeviceIds
     * @return array<string, mixed>
     */
    protected function staticPartialForDevice(Device $device, Collection $staticDeviceIds, CentralAPIHelper $helper): array
    {
        if (! $staticDeviceIds->contains($device->id)) {
            return [];
        }

        $reflection = new \ReflectionClass($this->deploymentCheck);
        $method = $reflection->getMethod('fetchStaticRouteForDevice');
        $method->setAccessible(true);

        return [
            'static_routes' => [$method->invoke($this->deploymentCheck, $device, $helper)],
        ];
    }

    /**
     * @param  Collection<int, int>  $dnsDeviceIds
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function dnsPartialForDevice(Device $device, Collection $dnsDeviceIds, CentralAPIHelper $helper, array $context): array
    {
        if (! $dnsDeviceIds->contains($device->id)) {
            return [];
        }

        $reflection = new \ReflectionClass($this->deploymentCheck);
        $method = $reflection->getMethod('fetchDnsForDeviceStep');
        $method->setAccessible(true);

        return $method->invoke($this->deploymentCheck, $device, $helper, $context);
    }

    protected function phasesPerDevice(bool $includeEthernet): int
    {
        return $includeEthernet ? 5 : 4;
    }

    protected function phaseNameForIndex(bool $includeEthernet, int $phase): ?string
    {
        if ($includeEthernet) {
            return match ($phase) {
                0 => 'lag',
                1 => 'ethernet',
                2 => 'vlan',
                3 => 'static',
                4 => 'dns',
                default => null,
            };
        }

        return match ($phase) {
            0 => 'lag',
            1 => 'vlan',
            2 => 'static',
            3 => 'dns',
            default => null,
        };
    }

    protected function messageForPhase(Device $device, ?string $phaseName): string
    {
        return match ($phaseName) {
            'lag' => "Checking LAG interfaces for {$device->name}...",
            'ethernet' => "Checking ethernet interfaces for {$device->name}...",
            'vlan' => "Checking VLAN interfaces for {$device->name}...",
            'static' => "Fetching static routes for {$device->name}...",
            'dns' => "Fetching DNS profiles for {$device->name}...",
            default => 'Running remediation check...',
        };
    }
}
