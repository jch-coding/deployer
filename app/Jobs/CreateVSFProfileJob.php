<?php

namespace App\Jobs;

use App\Helper\CentralAPIHelper;
use App\Models\Device;
use App\Models\Task;
use DateTime;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CreateVSFProfileJob implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * Create a new job instance.
     */
    public int $deployment_time;

    public int $wait_time;

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
            $this->fail($error_message);
        } else {
            $response = $this->centralAPIHelper->post_vsf_profile($this->device);
            if (! $response->ok()) {
                Log::error($response->json('message'));
                $this->task->update(['status_log' => $this->task->status_log.'\nVSF Profile Creation Failed. Next attempt at '.now()->addMinutes($this->wait_time)->format('Y-m-d H:i:s').'']);
                $this->release($this->wait_time * 60);
            } else {
                $success_message = '\nVSF profile created for stack: '.$this->device->name.'-STACK';
                Log::info($success_message);
                $status_log = $this->task->status_log;
                $this->task->update(['status_log' => $status_log.$success_message]);
                $this->task->devices()->find($this->device)->pivot->update(['status' => 'COMPLETED']);
            }
        }
    }

    public function retryUntil(): DateTime
    {
        return now()->addMinutes($this->deployment_time)->toDateTime();
    }
}
