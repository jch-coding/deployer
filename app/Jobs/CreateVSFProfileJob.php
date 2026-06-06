<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\Task;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

class CreateVSFProfileJob extends BaseTaskJob
{
    /**
     * Create a new job instance.
     */
    public function __construct(public Device $device, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->initTaskTiming($task, defaultWaitMinutes: 3);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->handleSafely(function (): void {
            $message = 'Creating VSF profile for device '.$this->device->name;
            Log::info($message);
            $this->task->processTaskStatusLog($message);
            if (! $this->device->scope_id) {
                $message = 'No scope id found for device '.$this->device->name.', getting scope id from Central...';
                Log::info($message);
                $this->task->processTaskStatusLog($message);
                $scopeid_response = $this->centralAPIHelper->getScopeIdFromCentral($this->device);
                if (! $this->applyScopeIdFromHierarchyResponse($scopeid_response)) {
                    return;
                }
            }
            if (! $this->device->sku) {
                $error_message = 'SKU not found for device: '.$this->device->name;
                Log::error($error_message);
                $this->task->processTaskStatusLog($error_message);
            } else {
                if (! $this->device->site->scope_id) {
                    $message = 'No scope id found for site '.$this->device->site->name.', getting scope id from Central...';
                    Log::info($message);
                    $this->task->processTaskStatusLog($message);
                    $site_scope_id = $this->centralAPIHelper->get_site_scope_id($this->device->site);
                    if (! $site_scope_id) {
                        $message = 'failed to retrieve scope ID for site '.$this->device->site->name;
                        $this->task->processTaskStatusLog($message);
                        Log::error($message);

                        return;
                    }
                    $message = 'Scope id found for site '.$this->device->site->name.', updating site...';
                    Log::info($message);
                    $this->task->processTaskStatusLog($message);
                    $this->device->site->scope_id = $site_scope_id;
                    $this->device->site->save();
                }
                $message = 'Creating VSF profile for device '.$this->device->name;
                Log::info($message);
                $this->task->processTaskStatusLog($message);
                $response = $this->centralAPIHelper->post_vsf_profile($this->device);
                if (! $response->ok()) {
                    $message = 'VSF Profile Creation Failed with error '.$response->json()['message'];
                    Log::info($message);
                    $this->task->processTaskStatusLog($message, true);
                    $this->release($this->wait_time * 60);
                } else {
                    $message = 'VSF profile created successfully, waiting 10 seconds for scope id to refresh...';
                    Log::info($message);
                    $this->task->processTaskStatusLog($message);
                    Sleep::for(10)->seconds();
                    $message = 'Getting scope id for device '.$this->device->name;
                    Log::info($message);
                    $this->task->processTaskStatusLog($message);
                    $scope_id_response = $this->centralAPIHelper->getScopeIdFromCentral($this->device);
                    $message = 'Scope id found for device '.$this->device->name.', updating device...';
                    Log::info($message);
                    $this->task->processTaskStatusLog($message);
                    if (! $this->applyScopeIdFromHierarchyResponse($scope_id_response)) {
                        $message = 'VSF profile created but could not refresh scope ID for '.$this->device->name;
                        Log::error($message);
                        $this->task->processTaskStatusLog($message);
                    }
                    $success_message = '\nVSF profile created for stack: '.$this->device->name.'-STACK';
                    Log::info($success_message);
                    $this->task->processTaskStatusLog($success_message);
                    $this->task->devices()->find($this->device)->pivot->update(['status' => 'COMPLETED']);
                }
            }
        }, 'Create VSF profile');
    }

    public function failed(?Throwable $exception)
    {
        $this->logFailedException($exception);
        $this->markDeviceFailed($this->device);
        $this->failTask('VSF profile creation timed out or failed.', true);
    }

    /**
     * @param  array<string, mixed>  $scope_id_response
     */
    private function applyScopeIdFromHierarchyResponse(array $scope_id_response): bool
    {
        if (array_key_exists('error', $scope_id_response)) {
            return false;
        }

        $scopeEntries = array_values($scope_id_response);
        if ($scopeEntries === [] || ! isset($scopeEntries[0]['scopeId'])) {
            return false;
        }

        $this->device->scope_id = $scopeEntries[0]['scopeId'];
        $this->device->save();

        return true;
    }
}
