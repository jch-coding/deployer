<?php

namespace App\Services;

use App\Http\Controllers\TaskController;
use App\JobQueueShard;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RelaunchFailedCriticalConfigService
{
    public const COMPOSITE_KIND = 'RELAUNCH_FAILED_CRITICAL_CONFIG';

    /**
     * @param  array{
     *     deployment_time: int,
     *     wait_time: int,
     *     include_ethernet: bool,
     *     failed_interface_ids: array{lag?: list<int>, vlan?: list<int>, ethernet?: list<int>},
     *     profile_device_ids: array{static_route?: list<int>, dns?: list<int>, local_management?: list<int>}
     * }  $payload
     */
    public function create(Deployment $deployment, array $payload, Request $request): Task
    {
        $lagIds = collect($payload['failed_interface_ids']['lag'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
        $vlanIds = collect($payload['failed_interface_ids']['vlan'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
        $ethernetIds = $payload['include_ethernet']
            ? collect($payload['failed_interface_ids']['ethernet'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()
            : collect();
        $staticDeviceIds = collect($payload['profile_device_ids']['static_route'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
        $dnsDeviceIds = collect($payload['profile_device_ids']['dns'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
        $localManagementDeviceIds = collect($payload['profile_device_ids']['local_management'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();

        $definitions = collect();
        if ($lagIds->isNotEmpty()) {
            $definitions->push(['task_type' => 'CONFIGURE_LAG_INTERFACE', 'interface_ids' => $lagIds]);
        }
        if ($ethernetIds->isNotEmpty()) {
            $definitions->push(['task_type' => 'CONFIGURE_ETHERNET_INTERFACE', 'interface_ids' => $ethernetIds]);
        }
        if ($vlanIds->isNotEmpty()) {
            $definitions->push(['task_type' => 'CONFIGURE_VLAN_INTERFACE', 'interface_ids' => $vlanIds]);
        }
        if ($staticDeviceIds->isNotEmpty()) {
            $definitions->push(['task_type' => 'REMOVE_LOCAL_OVERRIDE_STATIC_ROUTE', 'device_ids' => $staticDeviceIds]);
        }
        if ($dnsDeviceIds->isNotEmpty()) {
            $definitions->push(['task_type' => 'REMOVE_LOCAL_OVERRIDE_DNS_PROFILE', 'device_ids' => $dnsDeviceIds]);
        }
        if ($localManagementDeviceIds->isNotEmpty()) {
            $definitions->push(['task_type' => 'REMOVE_LOCAL_OVERRIDE_LOCAL_MANAGEMENT_PROFILE', 'device_ids' => $localManagementDeviceIds]);
        }

        if ($definitions->isEmpty()) {
            throw new \InvalidArgumentException('No failed items to relaunch.');
        }

        $compositeGroupId = (string) Str::uuid();
        $jobQueue = JobQueueShard::fromUserEntropy((int) $request->user()->id, (string) Str::uuid());
        $remediationContext = [
            'include_ethernet' => (bool) $payload['include_ethernet'],
        ];

        $createdTasks = collect();
        $order = 0;

        foreach ($definitions as $definition) {
            $order++;
            $task = $deployment->tasks()->create([
                'task_type' => $definition['task_type'],
                'name' => 'relaunch_failed_critical_'.$definition['task_type'].'_'.now()->timestamp,
                'deployment_time' => $payload['deployment_time'],
                'wait_time' => $payload['wait_time'],
                'status' => 'IN_PROGRESS',
                'job_queue' => $jobQueue,
                'composite_group_id' => $compositeGroupId,
                'composite_kind' => self::COMPOSITE_KIND,
                'composite_order' => $order,
                'remediation_context' => $remediationContext,
            ]);

            if (isset($definition['interface_ids'])) {
                $interfaceIds = $definition['interface_ids'];
                $deviceIds = DeviceInterface::query()
                    ->whereIn('id', $interfaceIds)
                    ->whereHas('device', fn ($q) => $q->where('deployment_id', $deployment->id))
                    ->pluck('device_id')
                    ->unique()
                    ->values();

                $task->devices()->attach($deviceIds);
                $attachData = $interfaceIds
                    ->mapWithKeys(fn (int $id) => [$id => ['status' => 'PENDING']])
                    ->all();
                $task->deviceInterfaces()->attach($attachData);
            } else {
                $deviceIds = Device::query()
                    ->where('deployment_id', $deployment->id)
                    ->whereIn('id', $definition['device_ids'])
                    ->pluck('id');
                $task->devices()->attach($deviceIds);
            }

            $batchId = app(TaskController::class)->dispatchJob($task);
            if ($batchId !== null) {
                $task->forceFill(['batch_id' => $batchId])->save();
            }

            $createdTasks->push($task);
        }

        /** @var Task $first */
        $first = $createdTasks->first();

        return $first;
    }
}
