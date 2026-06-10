<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\Task;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class ConfigureMirrorSessionJob extends BaseTaskJob
{
    public function __construct(
        public Device $device,
        public Task $task,
        public CentralAPIHelper $centralAPIHelper,
        public bool $fallbackMode,
    ) {
        $this->initTaskTiming($task, defaultWaitMinutes: 1);
    }

    public function handle(): void
    {
        $this->handleSafely(function (): void {
            $pivotForDevice = $this->task->devices()->find($this->device->id)?->pivot;
            if ($pivotForDevice === null) {
                Log::error('Device '.$this->device->name.' is not attached to task '.$this->task->id);

                return;
            }

            if (! $this->device->scope_id) {
                $scopeIdResponse = $this->centralAPIHelper->getScopeIdFromCentral($this->device);
                if (array_key_exists('error', $scopeIdResponse)) {
                    $message = 'Failed to retrieve scope ID for '.$this->device->name;
                    Log::error($message);
                    $this->task->processTaskStatusLog($message, true);
                    $this->release($this->wait_time * 60);

                    return;
                }

                $scopeEntries = array_values($scopeIdResponse);
                if ($scopeEntries === [] || ! isset($scopeEntries[0]['scopeId'])) {
                    $message = 'No scope id in hierarchy response for '.$this->device->name;
                    Log::error($message);
                    $this->task->processTaskStatusLog($message, true);
                    $this->release($this->wait_time * 60);

                    return;
                }

                $this->device->scope_id = $scopeEntries[0]['scopeId'];
                $this->device->save();
            }

            $settings = $this->centralAPIHelper->resolveMirrorSettings($this->device, $this->fallbackMode);
            if (array_key_exists('error', $settings)) {
                $message = $settings['error'];
                Log::error($message);
                $this->task->processTaskStatusLog($message, true);
                $this->failDeviceAndTaskIfNeeded($this->device);

                return;
            }

            $payload = CentralAPIHelper::buildMirrorPayload(
                $this->device,
                $settings['name'],
                $settings['session_id'],
                $settings['dst_ports'],
                $settings['vlan_ids'],
            );

            $queryParameters = [
                'object-type' => 'LOCAL',
                'scope-id' => $this->device->scope_id,
                'device-function' => CentralAPIHelper::deviceFunctionQueryValue($this->device),
            ];

            $message = 'Creating mirror session '.$settings['name'].' for '.$this->device->name;
            Log::info($message);
            $this->task->processTaskStatusLog($message);

            $response = $this->centralAPIHelper->post_mirror($payload, $queryParameters);
            if ($this->mirrorRequestSucceeded($response)) {
                $this->markMirrorSessionCompleted($pivotForDevice, $settings, 'created');

                return;
            }

            $postFailureDetail = $this->mirrorRequestFailureDetail($response);
            Log::error('post_mirror failed for '.$this->device->name.': '.$postFailureDetail);
            $this->task->processTaskStatusLog(
                'post_mirror failed for '.$this->device->name.': '.$postFailureDetail.'. Trying patch_mirror...',
                true
            );

            $patchResponse = $this->centralAPIHelper->patch_mirror($payload, $queryParameters);
            if ($this->mirrorRequestSucceeded($patchResponse)) {
                $this->markMirrorSessionCompleted($pivotForDevice, $settings, 'updated');

                return;
            }

            $patchFailureDetail = $this->mirrorRequestFailureDetail($patchResponse);
            $message = 'Failed to update mirror session for '.$this->device->name.': '.$patchFailureDetail;
            Log::error($message);
            $this->task->processTaskStatusLog($message, true);
            $this->release($this->wait_time * 60);
        }, 'Configure mirror session');
    }

    private function mirrorRequestSucceeded(mixed $response): bool
    {
        return $response instanceof Response && $response->successful();
    }

    /**
     * @param  'created'|'updated'  $action
     */
    private function markMirrorSessionCompleted(mixed $pivotForDevice, array $settings, string $action): void
    {
        $pivotForDevice->update(['status' => 'COMPLETED']);
        $verb = $action === 'created' ? 'created' : 'updated';
        $successMessage = 'Mirror session '.$settings['name'].' '.$verb.' for '.$this->device->name;
        Log::info($successMessage);
        $this->task->processTaskStatusLog($successMessage);
        $this->task->load('devices');
        if ($this->task->allTrackedItemsCompleted()) {
            $this->task->update(['status' => 'COMPLETED']);
        }
    }

    private function mirrorRequestFailureDetail(mixed $response): string
    {
        if (is_array($response) && array_key_exists('error', $response)) {
            return (string) $response['error'];
        }

        if (! $response instanceof Response) {
            return 'Invalid response from Central';
        }

        return $this->responseMessageString($response);
    }

    private function responseMessageString(Response $response): string
    {
        $msg = $response->json('message');
        if (is_string($msg)) {
            return $msg;
        }
        if (is_array($msg)) {
            return json_encode($msg);
        }

        return (string) $response->body();
    }

    public function failed(?Throwable $exception): void
    {
        $this->logFailedException($exception);
        $message = 'Failed configuring mirror session for '.$this->device->name;
        $this->task->processTaskStatusLog($message, true);
        $this->failDeviceAndTaskIfNeeded($this->device);
    }
}
