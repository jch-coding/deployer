<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\Task;
use App\VsxRole;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreateVsxProfileJob extends BaseTaskJob
{
    /**
     * @param  Collection<int, Device>  $devices
     */
    public function __construct(
        public string $vsxProfileName,
        public Collection $devices,
        public Task $task,
        public CentralAPIHelper $centralAPIHelper
    ) {
        $this->initTaskTiming($task, defaultDeploymentMinutes: 3, defaultWaitMinutes: 3);
    }

    public function handle(): void
    {
        $this->handleSafely(function (): void {
            $validationError = $this->validatePeerPair();
            if ($validationError !== null) {
                $this->abortPair($validationError, true);

                return;
            }

            $primary = $this->devices->first(fn (Device $device) => CentralAPIHelper::deviceVsxRole($device) === VsxRole::VSX_PRIMARY);
            $secondary = $this->devices->first(fn (Device $device) => CentralAPIHelper::deviceVsxRole($device) === VsxRole::VSX_SECONDARY);

            if ($primary === null || $secondary === null) {
                $this->abortPair('VSX profile requires one VSX_PRIMARY and one VSX_SECONDARY device.', true);

                return;
            }

            $siteScopeId = $this->resolveSiteScopeId($primary);
            if ($siteScopeId === null) {
                $this->abortPair('Could not resolve site scope ID for VSX profile creation.', true);

                return;
            }

            foreach ($this->devices as $device) {
                if (! $device->scope_id) {
                    $scopeIdResponse = $this->centralAPIHelper->getScopeIdFromCentral($device);
                    if (array_key_exists('error', $scopeIdResponse)) {
                        $this->abortPair('Failed to get scope id for device '.$device->name, true);

                        return;
                    }
                    $scopeEntries = array_values($scopeIdResponse);
                    if ($scopeEntries === [] || ! isset($scopeEntries[0]['scopeId'])) {
                        $this->abortPair('Failed to get scope id for device '.$device->name, true);

                        return;
                    }
                    $device->scope_id = $scopeEntries[0]['scopeId'];
                    $device->save();
                }
            }

            foreach ($this->devices as $device) {
                $peerDevice = $device->is($primary) ? $secondary : $primary;
                $role = CentralAPIHelper::deviceVsxRole($device);
                if ($role === null) {
                    $this->abortPair('Missing VSX role for device '.$device->name, true);

                    return;
                }

                $portSelections = CentralAPIHelper::getVsxPortSelections($device);
                if (array_key_exists('error', $portSelections)) {
                    $this->abortPair((string) $portSelections['error'], true);

                    return;
                }

                [$islPorts, $keepalivePorts] = $portSelections;

                $vrfResult = $this->centralAPIHelper->ensureVsxKeepAliveVrf($device);
                if (array_key_exists('error', $vrfResult)) {
                    $this->abortPair($vrfResult['error'], true);

                    return;
                }

                $islResult = $this->centralAPIHelper->ensureVsxIslLag($device, $peerDevice, $islPorts);
                if (array_key_exists('error', $islResult)) {
                    $this->abortPair($islResult['error'], true);

                    return;
                }

                $keepaliveResult = $this->centralAPIHelper->ensureVsxKeepaliveLag($device, $peerDevice, $role, $keepalivePorts);
                if (array_key_exists('error', $keepaliveResult)) {
                    $this->abortPair($keepaliveResult['error'], true);

                    return;
                }
            }

            $payload = CentralAPIHelper::buildVsxProfilePayload($primary, $secondary);
            $message = 'Creating VSX profile '.$this->vsxProfileName;
            Log::info($message);
            $this->task->processTaskStatusLog($message);

            $response = $this->centralAPIHelper->post_vsx_profile(
                $payload,
                $siteScopeId
            );

            if (is_array($response) && array_key_exists('error', $response)) {
                $this->task->processTaskStatusLog($response['error'], true);
                $this->release($this->wait_time * 60);

                return;
            }

            if (! $response->ok()) {
                $errorMessage = (string) ($response->json('message') ?? $response->body());
                $this->task->processTaskStatusLog('VSX profile creation failed: '.$errorMessage, true);
                $this->release($this->wait_time * 60);

                return;
            }

            $successMessage = '\nVSX profile created: '.$this->vsxProfileName;
            Log::info($successMessage);
            $this->task->processTaskStatusLog($successMessage);

            foreach ($this->devices as $device) {
                $this->task->devices()->find($device->id)?->pivot?->update(['status' => 'COMPLETED']);
            }

            $this->task->load('devices');
            if ($this->task->allTrackedItemsCompleted()) {
                $this->task->update(['status' => 'COMPLETED']);
            }
        }, 'Create VSX profile');
    }

    public function failed(?Throwable $exception): void
    {
        $this->logFailedException($exception);
        $this->markAllDevicesFailed();
        $this->failTask('VSX profile creation timed out or failed.', true);
    }

    private function validatePeerPair(): ?string
    {
        if ($this->devices->count() !== 2) {
            return 'VSX profile '.$this->vsxProfileName.' requires exactly 2 devices, found '.$this->devices->count().'.';
        }

        $roles = $this->devices->map(fn (Device $device) => CentralAPIHelper::deviceVsxRole($device));
        if ($roles->contains(null)) {
            return 'All devices in VSX profile '.$this->vsxProfileName.' must have vsx_profile, vsx_role, and vsx_system_mac set.';
        }

        if ($roles->filter(fn (?VsxRole $role) => $role === VsxRole::VSX_PRIMARY)->count() !== 1
            || $roles->filter(fn (?VsxRole $role) => $role === VsxRole::VSX_SECONDARY)->count() !== 1) {
            return 'VSX profile '.$this->vsxProfileName.' requires exactly one VSX_PRIMARY and one VSX_SECONDARY device.';
        }

        $profileNames = $this->devices->pluck('vsx_profile')->unique();
        if ($profileNames->count() !== 1 || (string) $profileNames->first() !== $this->vsxProfileName) {
            return 'Devices in VSX profile '.$this->vsxProfileName.' have mismatched vsx_profile values.';
        }

        $systemMacs = $this->devices->pluck('vsx_system_mac')->unique();
        if ($systemMacs->count() !== 1) {
            return 'Devices in VSX profile '.$this->vsxProfileName.' have mismatched vsx_system_mac values.';
        }

        $islPortOverrides = $this->devices->pluck('vsx_isl_ports')->map(fn ($value) => filled($value) ? (string) $value : null);
        if ($islPortOverrides->unique()->count() > 1) {
            return 'Devices in VSX profile '.$this->vsxProfileName.' have mismatched vsx_isl_ports values.';
        }

        $keepalivePortOverrides = $this->devices->pluck('vsx_keepalive_ports')->map(fn ($value) => filled($value) ? (string) $value : null);
        if ($keepalivePortOverrides->unique()->count() > 1) {
            return 'Devices in VSX profile '.$this->vsxProfileName.' have mismatched vsx_keepalive_ports values.';
        }

        $hasIslOverride = $islPortOverrides->contains(fn (?string $value) => $value !== null);
        $hasKeepaliveOverride = $keepalivePortOverrides->contains(fn (?string $value) => $value !== null);
        if ($hasIslOverride xor $hasKeepaliveOverride) {
            return 'Devices in VSX profile '.$this->vsxProfileName.' must both specify vsx_isl_ports and vsx_keepalive_ports when overriding LAG member ports.';
        }

        foreach ($this->devices as $device) {
            if (! filled($device->group)) {
                return 'Device '.$device->name.' has no group set (required for VRF ensure).';
            }
        }

        $siteIds = $this->devices->pluck('site_id')->unique();
        if ($siteIds->count() !== 1 || $siteIds->first() === null) {
            return 'VSX profile peers must belong to the same site.';
        }

        return null;
    }

    private function resolveSiteScopeId(Device $device): ?string
    {
        $site = $device->site;
        if ($site === null) {
            return null;
        }

        if (! filled($site->scope_id)) {
            $siteScopeId = $this->centralAPIHelper->get_site_scope_id($site);
            if ($siteScopeId === null || $siteScopeId === '') {
                return null;
            }
            $site->scope_id = $siteScopeId;
            $site->save();
        }

        return (string) $site->scope_id;
    }

    private function abortPair(string $message, bool $withTimestamp = false): void
    {
        Log::error($message);
        $this->task->processTaskStatusLog($message, $withTimestamp);
        foreach ($this->devices as $device) {
            $this->markDeviceFailed($device);
        }
        $this->failTask($message, $withTimestamp);
    }
}
