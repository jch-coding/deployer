<?php

namespace App\Services\Provisioning;

use App\Enums\ProvisioningStep;
use App\Helper\CentralAPIHelper;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\LicensingInventoryDevice;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ProvisioningPreflightService
{
    public function __construct(
        private readonly ClassicDeviceOnlineService $classicDeviceOnlineService,
    ) {}

    /**
     * @param  array<int, int>  $deviceIds
     * @param  array<string, mixed>  $options
     * @return array{
     *     has_warnings: bool,
     *     devices: list<array{device_id: int, name: string, serial: string, steps: list<array{step_key: string, label: string, status: string, message: string}>}>,
     *     remediations: list<array{step_key: string, task_type: string, label: string, device_ids: list<int>, options: list<string>}>
     * }
     */
    public function run(Deployment $deployment, array $deviceIds, array $options): array
    {
        $deviceIds = array_values(array_unique(array_map('intval', $deviceIds)));
        if ($deviceIds === []) {
            throw ValidationException::withMessages(['device_ids' => 'Select at least one device.']);
        }

        $devices = Device::query()
            ->where('deployment_id', $deployment->id)
            ->whereIn('id', $deviceIds)
            ->with('site')
            ->get();

        if ($devices->count() !== count($deviceIds)) {
            throw ValidationException::withMessages(['device_ids' => 'One or more selected devices are invalid for this deployment.']);
        }

        $startStep = ProvisioningStep::tryFrom((string) ($options['start_step'] ?? ''))
            ?? ProvisioningStep::VerifyLicensing;

        $customSteps = null;
        if (array_key_exists('steps', $options) && $options['steps'] !== null) {
            if (! is_array($options['steps'])) {
                throw ValidationException::withMessages(['steps' => 'Steps must be an array.']);
            }
            $customSteps = CustomWorkflowStepOrder::validate($options['steps']);
        }

        $provisioningNames = $this->provisioningNamesFromOptions($options);
        $stepContext = new ProvisioningStepContext(
            CentralAPIHelper::deploymentUsesVsxFallbackMode($devices),
            CentralAPIHelper::deploymentUsesMirrorFallbackMode($devices),
            $provisioningNames,
        );

        $deployment->loadMissing('client');
        $centralAPIHelper = new CentralAPIHelper($deployment->client);

        $inventoryBySerial = $this->loadClassicInventoryBySerial($centralAPIHelper, $devices);
        $switchStatuses = $this->classicDeviceOnlineService->fetchSwitchStatuses($centralAPIHelper);
        $apStatuses = $this->classicDeviceOnlineService->fetchApStatuses($centralAPIHelper);

        $deviceResults = [];
        /** @var array<string, array{step_key: string, task_type: string, label: string, device_ids: list<int>, options: list<string>}> $remediationMap */
        $remediationMap = [];
        $hasWarnings = false;

        foreach ($devices as $device) {
            $steps = [];
            $stepsToCheck = $customSteps !== null
                ? $customSteps
                : $this->stepsBeforeStart($startStep);

            foreach ($stepsToCheck as $step) {
                if ($step->shouldSkipForDevice($device, $stepContext)) {
                    continue;
                }

                $check = $this->checkStep(
                    $step,
                    $device,
                    $deployment,
                    $centralAPIHelper,
                    $inventoryBySerial,
                    $switchStatuses,
                    $apStatuses,
                    $stepContext,
                );

                if ($check['status'] !== 'ok') {
                    $hasWarnings = true;
                }

                $steps[] = [
                    'step_key' => $step->value,
                    'label' => $step->label(),
                    'status' => $check['status'],
                    'message' => $check['message'],
                ];

                if ($check['status'] === 'warn' && $check['remediation'] !== null) {
                    $key = $check['remediation']['task_type'].'|'.$step->value;
                    if (! isset($remediationMap[$key])) {
                        $remediationMap[$key] = [
                            'step_key' => $step->value,
                            'task_type' => $check['remediation']['task_type'],
                            'label' => $check['remediation']['label'],
                            'device_ids' => [],
                            'options' => $check['remediation']['options'],
                        ];
                    }
                    $remediationMap[$key]['device_ids'][] = $device->id;
                }
            }

            $deviceResults[] = [
                'device_id' => $device->id,
                'name' => (string) $device->name,
                'serial' => (string) $device->serial,
                'steps' => $steps,
            ];
        }

        $remediations = array_values(array_map(function (array $row) {
            $row['device_ids'] = array_values(array_unique($row['device_ids']));
            $count = count($row['device_ids']);
            $row['label'] = $row['label'].' ('.$count.' device'.($count === 1 ? '' : 's').')';

            return $row;
        }, $remediationMap));

        return [
            'has_warnings' => $hasWarnings,
            'devices' => $deviceResults,
            'remediations' => $remediations,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $inventoryBySerial
     * @param  array<string, string>  $switchStatuses
     * @param  array<string, string>  $apStatuses
     * @return array{status: string, message: string, remediation: ?array{task_type: string, label: string, options: list<string>}}
     */
    private function checkStep(
        ProvisioningStep $step,
        Device $device,
        Deployment $deployment,
        CentralAPIHelper $centralAPIHelper,
        array $inventoryBySerial,
        array $switchStatuses,
        array $apStatuses,
        ProvisioningStepContext $context,
    ): array {
        return match ($step) {
            ProvisioningStep::VerifyLicensing => $this->checkLicensing($device, $deployment),
            ProvisioningStep::PreprovisionGroup => $this->checkPreprovisionGroup($device, $inventoryBySerial, $centralAPIHelper),
            ProvisioningStep::AssignDeviceFunction => $this->checkDeviceFunction($device, $inventoryBySerial),
            ProvisioningStep::WaitForOnline => $this->checkOnline($device, $switchStatuses, $apStatuses),
            ProvisioningStep::AssociateSite => $this->checkSite($device, $inventoryBySerial),
            ProvisioningStep::ResolveScopeId => $this->checkScopeId($device, $centralAPIHelper),
            ProvisioningStep::NameDevice => $this->checkName($device, $centralAPIHelper, $context),
            ProvisioningStep::CreateStackProfile => $this->checkStackProfile($device),
            ProvisioningStep::WaitForVsfStackScope => $this->checkVsfStackScope($device),
            ProvisioningStep::ConfigureVlanInterfaces,
            ProvisioningStep::ConfigureLagInterfaces,
            ProvisioningStep::ConfigureEthernetInterfaces,
            ProvisioningStep::ConfigureMirrorSessions,
            ProvisioningStep::ClearLocalOverrides => [
                'status' => 'unchecked',
                'message' => 'No prerequisite check in this version; continuing may fail later.',
                'remediation' => null,
            ],
        };
    }

    /**
     * @return array{status: string, message: string, remediation: ?array{task_type: string, label: string, options: list<string>}}
     */
    private function checkLicensing(Device $device, Deployment $deployment): array
    {
        $client = $deployment->client;
        if ($client === null) {
            return $this->warn(
                'Could not resolve client for licensing check.',
                'ASSIGN_SUBSCRIPTION',
                'License devices',
                ['licensing'],
            );
        }

        $inventoryRow = $client->licensingInventoryDevices()
            ->where('serial', $device->serial)
            ->first();

        if (! $inventoryRow instanceof LicensingInventoryDevice) {
            return $this->warn(
                "Device {$device->serial} is not in GreenLake inventory.",
                'ASSIGN_SUBSCRIPTION',
                'License devices',
                ['licensing'],
            );
        }

        if ($inventoryRow->licensed && trim((string) $inventoryRow->subscription_key) !== '') {
            return $this->ok('Device already has an active subscription.');
        }

        return $this->warn(
            "Device {$device->serial} is not licensed.",
            'ASSIGN_SUBSCRIPTION',
            'License devices',
            ['licensing'],
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $inventoryBySerial
     * @return array{status: string, message: string, remediation: ?array{task_type: string, label: string, options: list<string>}}
     */
    private function checkPreprovisionGroup(Device $device, array $inventoryBySerial, CentralAPIHelper $centralAPIHelper): array
    {
        $group = trim((string) $device->group);
        if ($group === '') {
            return $this->warn(
                'Device has no group configured.',
                'PREPROVISION_DEVICE_TO_GROUP',
                'Preprovision to group',
                [],
            );
        }

        $groupsResult = $centralAPIHelper->classic_collect_all_group_names();
        if (isset($groupsResult['error'])) {
            return $this->warn(
                'Could not load groups from Central.',
                'PREPROVISION_DEVICE_TO_GROUP',
                'Preprovision to group',
                [],
            );
        }

        if (! in_array($group, $groupsResult['names'] ?? [], true)) {
            return $this->warn(
                "Group \"{$group}\" not found in Central.",
                'PREPROVISION_DEVICE_TO_GROUP',
                'Preprovision to group',
                [],
            );
        }

        $row = $inventoryBySerial[(string) $device->serial] ?? null;
        if ($row === null) {
            return $this->warn(
                "Device not found in Central inventory for group \"{$group}\".",
                'PREPROVISION_DEVICE_TO_GROUP',
                'Preprovision to group',
                [],
            );
        }

        $actualGroup = (string) ($row['group_name'] ?? $row['group'] ?? '');
        if ($actualGroup !== '' && $actualGroup !== $group) {
            return $this->warn(
                "Device group is \"{$actualGroup}\", expected \"{$group}\".",
                'PREPROVISION_DEVICE_TO_GROUP',
                'Preprovision to group',
                [],
            );
        }

        return $this->ok("Device is present for group \"{$group}\".");
    }

    /**
     * @param  array<string, array<string, mixed>>  $inventoryBySerial
     * @return array{status: string, message: string, remediation: ?array{task_type: string, label: string, options: list<string>}}
     */
    private function checkDeviceFunction(Device $device, array $inventoryBySerial): array
    {
        $expected = trim((string) $device->device_function);
        if ($expected === '') {
            return $this->warn(
                'Device has no device function configured.',
                'ASSIGN_DEVICE_FUNCTION',
                'Assign device function',
                [],
            );
        }

        $row = $inventoryBySerial[(string) $device->serial] ?? null;
        if ($row === null) {
            return $this->warn(
                'Device not found in Central inventory; function not verified.',
                'ASSIGN_DEVICE_FUNCTION',
                'Assign device function',
                [],
            );
        }

        $actual = trim((string) ($row['device_function'] ?? $row['persona'] ?? $row['type'] ?? ''));
        if ($actual !== '' && strcasecmp($actual, $expected) !== 0) {
            // Classic inventory often reports SWITCH/AP rather than full persona — treat category match as ok.
            $expectedCategory = $this->functionCategory($expected);
            $actualCategory = $this->functionCategory($actual);
            if ($expectedCategory === null || $actualCategory === null || $expectedCategory !== $actualCategory) {
                return $this->warn(
                    "Device function appears to be \"{$actual}\", expected \"{$expected}\".",
                    'ASSIGN_DEVICE_FUNCTION',
                    'Assign device function',
                    [],
                );
            }
        }

        return $this->ok("Device function looks correct ({$expected}).");
    }

    /**
     * @param  array<string, string>  $switchStatuses
     * @param  array<string, string>  $apStatuses
     * @return array{status: string, message: string, remediation: ?array{task_type: string, label: string, options: list<string>}}
     */
    private function checkOnline(Device $device, array $switchStatuses, array $apStatuses): array
    {
        if ($this->classicDeviceOnlineService->isDeviceUp($device, $switchStatuses, $apStatuses)) {
            return $this->ok('Device is online (Up).');
        }

        $status = $this->classicDeviceOnlineService->currentStatus($device, $switchStatuses, $apStatuses);

        return [
            'status' => 'warn',
            'message' => "Device is not online (status: {$status}). Bring the device online, then re-run checks.",
            'remediation' => null,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $inventoryBySerial
     * @return array{status: string, message: string, remediation: ?array{task_type: string, label: string, options: list<string>}}
     */
    private function checkSite(Device $device, array $inventoryBySerial): array
    {
        $device->loadMissing('site');
        if ($device->site === null) {
            return $this->warn(
                'Device has no site configured.',
                'ASSOCIATE_DEVICE_TO_SITE',
                'Associate to site',
                [],
            );
        }

        $row = $inventoryBySerial[(string) $device->serial] ?? null;
        if ($row === null) {
            return $this->warn(
                'Device not found in Central inventory; site association not verified.',
                'ASSOCIATE_DEVICE_TO_SITE',
                'Associate to site',
                [],
            );
        }

        $siteName = (string) ($row['site'] ?? $row['site_name'] ?? '');
        if ($siteName !== '' && strcasecmp($siteName, (string) $device->site->name) !== 0) {
            return $this->warn(
                "Device site is \"{$siteName}\", expected \"{$device->site->name}\".",
                'ASSOCIATE_DEVICE_TO_SITE',
                'Associate to site',
                [],
            );
        }

        return $this->ok("Device is associated to site {$device->site->name}.");
    }

    /**
     * @return array{status: string, message: string, remediation: ?array{task_type: string, label: string, options: list<string>}}
     */
    private function checkScopeId(Device $device, CentralAPIHelper $centralAPIHelper): array
    {
        if (filled($device->scope_id)) {
            return $this->ok('Scope ID already present.');
        }

        $response = $centralAPIHelper->getScopeIdFromCentral($device);
        if (! array_key_exists('error', $response)) {
            $scopeEntries = array_values($response);
            if ($scopeEntries !== [] && isset($scopeEntries[0]['scopeId'])) {
                return $this->ok('Scope ID is available in Central.');
            }
        }

        return [
            'status' => 'warn',
            'message' => 'Scope ID not available yet. Re-run checks after the device appears in New Central.',
            'remediation' => null,
        ];
    }

    /**
     * @return array{status: string, message: string, remediation: ?array{task_type: string, label: string, options: list<string>}}
     */
    private function checkName(Device $device, CentralAPIHelper $centralAPIHelper, ProvisioningStepContext $context): array
    {
        $expected = trim((string) ($context->provisioningNames[$device->id] ?? $device->name));
        if ($expected === '') {
            return $this->warn(
                'No hostname configured for device.',
                'UPDATE_SYSTEM_INFO',
                'Update system info',
                [],
            );
        }

        if (! filled($device->scope_id)) {
            return $this->warn(
                'Cannot verify hostname without a scope ID.',
                'UPDATE_SYSTEM_INFO',
                'Update system info',
                [],
            );
        }

        $response = $centralAPIHelper->getSystemInfo($device);
        if (is_array($response) || ! $response instanceof Response || ! $response->successful()) {
            return $this->warn(
                'Could not read system info from Central.',
                'UPDATE_SYSTEM_INFO',
                'Update system info',
                [],
            );
        }

        $profiles = $response->json('profile', []);
        $hostname = '';
        if (is_array($profiles) && isset($profiles[0]) && is_array($profiles[0])) {
            $hostname = trim((string) ($profiles[0]['hostname'] ?? ''));
        }

        if ($hostname !== '' && strcasecmp($hostname, $expected) === 0) {
            return $this->ok("Hostname matches \"{$expected}\".");
        }

        return $this->warn(
            $hostname === ''
                ? "Hostname not set in Central (expected \"{$expected}\")."
                : "Hostname is \"{$hostname}\", expected \"{$expected}\".",
            'UPDATE_SYSTEM_INFO',
            'Update system info',
            [],
        );
    }

    /**
     * @return array{status: string, message: string, remediation: ?array{task_type: string, label: string, options: list<string>}}
     */
    private function checkStackProfile(Device $device): array
    {
        if ($device->sku) {
            if (filled($device->stack_id)) {
                return $this->ok('VSF stack ID is present.');
            }

            return $this->warn(
                'VSF stack profile / stack ID not found.',
                'CREATE_VSF_PROFILE',
                'Create VSF profile',
                [],
            );
        }

        if (filled($device->vsx_profile) || CentralAPIHelper::deviceMatchesVsxNamePattern($device)) {
            return $this->warn(
                'VSX profile presence could not be confirmed; create/verify the VSX pair if needed.',
                'CREATE_VSX_PROFILE',
                'Create VSX profile',
                [],
            );
        }

        return $this->ok('No stack profile required.');
    }

    /**
     * @return array{status: string, message: string, remediation: ?array{task_type: string, label: string, options: list<string>}}
     */
    private function checkVsfStackScope(Device $device): array
    {
        if (! $device->sku) {
            return $this->ok('Not a VSF device.');
        }

        if (filled($device->scope_id) && filled($device->stack_id)) {
            return $this->ok('VSF stack scope and stack ID are present.');
        }

        return [
            'status' => 'warn',
            'message' => 'VSF stack scope / stack ID not ready yet. Re-run checks after stacking completes.',
            'remediation' => null,
        ];
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @return array<string, array<string, mixed>>
     */
    private function loadClassicInventoryBySerial(CentralAPIHelper $centralAPIHelper, Collection $devices): array
    {
        $bySerial = [];

        $needsSwitches = $devices->contains(
            fn (Device $device) => str_contains((string) $device->device_function, 'SWITCH')
                || ! str_contains((string) $device->device_function, 'AP')
        );
        $needsAps = $devices->contains(
            fn (Device $device) => str_contains((string) $device->device_function, 'AP')
        );

        if ($needsSwitches) {
            $result = $centralAPIHelper->classic_collect_all_switches();
            foreach ($result['switches'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $serial = (string) ($row['serial'] ?? '');
                if ($serial !== '') {
                    $bySerial[$serial] = $row;
                }
            }
        }

        if ($needsAps) {
            $result = $centralAPIHelper->classic_collect_all_aps();
            foreach ($result['aps'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $serial = (string) ($row['serial'] ?? '');
                if ($serial !== '') {
                    $bySerial[$serial] = $row;
                }
            }
        }

        return $bySerial;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<int, string>
     */
    private function provisioningNamesFromOptions(array $options): array
    {
        $names = [];
        foreach (($options['devices'] ?? []) as $row) {
            $deviceId = (int) ($row['id'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));
            if ($deviceId > 0 && $name !== '') {
                $names[$deviceId] = $name;
            }
        }

        return $names;
    }

    private function functionCategory(string $function): ?string
    {
        $upper = strtoupper($function);
        if (str_contains($upper, 'SWITCH')) {
            return 'SWITCH';
        }
        if (str_contains($upper, 'AP')) {
            return 'AP';
        }

        return null;
    }

    /**
     * @param  list<string>  $options
     * @return array{status: string, message: string, remediation: array{task_type: string, label: string, options: list<string>}}
     */
    private function warn(string $message, string $taskType, string $label, array $options): array
    {
        return [
            'status' => 'warn',
            'message' => $message,
            'remediation' => [
                'task_type' => $taskType,
                'label' => $label,
                'options' => $options,
            ],
        ];
    }

    /**
     * @return array{status: string, message: string, remediation: null}
     */
    private function ok(string $message): array
    {
        return [
            'status' => 'ok',
            'message' => $message,
            'remediation' => null,
        ];
    }

    /**
     * @return list<ProvisioningStep>
     */
    private function stepsBeforeStart(ProvisioningStep $startStep): array
    {
        $steps = [];
        foreach (ProvisioningStep::ordered() as $step) {
            if ($step->order() >= $startStep->order()) {
                break;
            }
            $steps[] = $step;
        }

        return $steps;
    }
}
