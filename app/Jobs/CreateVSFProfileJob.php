<?php

namespace App\Jobs;

use App\Jobs\Concerns\HandlesUncaughtTaskExceptions;
use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\Task;
use DateTime;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreateVSFProfileJob implements ShouldQueue
{
    use Batchable, HandlesUncaughtTaskExceptions, Queueable;

    /**
     * Create a new job instance.
     */
    public int $deployment_time;

    public int $wait_time;
    public int $tries = 1;

    public function __construct(public Device $device, public Task $task, public CentralAPIHelper $centralAPIHelper)
    {
        $this->deployment_time = $task->deployment_time ?? 3;
        $this->wait_time = $task->wait_time ?? 3;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            if (! $this->device->scope_id) {
                $scopeid_response = $this->centralAPIHelper->getScopeIdFromCentral($this->device);
                if (array_key_exists('error', $scopeid_response)) {
                    return;
                }
                $this->device->scope_id = $scopeid_response[0]['scopeId'];
                $this->device->save();
            }
            if (! $this->device->sku) {
                $error_message = 'SKU not found for device: '.$this->device->name;
                Log::error($error_message);
                $this->task->processTaskStatusLog($error_message);
            } else {
                if (! $this->device->site->scope_id) {
                    $site_scope_id = $this->centralAPIHelper->get_site_scope_id($this->device->site);
                    if (! $site_scope_id) {
                        $message = 'failed to retrieve scope ID for site '.$this->device->site->name;
                        $this->task->processTaskStatusLog($message);
                        Log::error($message);

                        return;
                    }
                    $this->device->site->scope_id = $site_scope_id;
                    $this->device->site->save();
                }
                $response = $this->centralAPIHelper->post_vsf_profile($this->device);
                if (! $response->ok()) {
                    $message = 'VSF Profile Creation Failed with error '.$response->json()['message'];
                    Log::error($response->json('message'));
                    $this->task->processTaskStatusLog($message, true);
                    $this->release($this->wait_time * 60);
                } else {
                    $success_message = '\nVSF profile created for stack: '.$this->device->name.'-STACK';
                    Log::info($success_message);
                    $this->task->processTaskStatusLog($success_message);
                    $this->task->devices()->find($this->device)->pivot->update(['status' => 'COMPLETED']);
                }
            }
        } catch (Throwable $exception) {
            $this->failTaskOnUnhandledException($exception, 'Create VSF profile');
        }
    }

    public function retryUntil(): DateTime
    {
        return now()->addMinutes($this->deployment_time)->toDateTime();
    }

    public function failed(?Throwable $exception)
    {
        Log::error('VSF profile creation timed out or failed.');
        $this->task->processTaskStatusLog('VSF profile creation timed out or failed.', true);
        $this->task->devices()->find($this->device)->pivot->update(['status' => 'FAILED']);
        $this->task->update(['status' => 'FAILED']);
    }
}
