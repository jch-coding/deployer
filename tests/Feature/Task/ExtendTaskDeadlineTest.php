<?php

use App\Enums\ProvisioningStep;
use App\Jobs\FailWaitForOnlineOnTimeoutJob;
use App\Jobs\RunProvisioningWorkflowStepJob;
use App\Models\Device;
use App\Models\ProvisioningWorkflow;
use App\Models\ProvisioningWorkflowDevice;
use App\Models\Task;
use App\Models\User;
use App\Models\Client;
use App\Services\Provisioning\ProvisioningWorkflowTaskSync;
use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()
        ->has(Client::factory())
        ->create();
    $this->client = $this->user->clients()->first();
    $this->client->update(['current' => true]);
    $this->deployment = $this->client->deployments()->create(['name' => 'Test Deployment']);
    $this->actingAs($this->user);
});

test('extend rejects completed tasks', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'COMPLETED',
        'deployment_time' => 10,
    ]);

    $this->post(route('tasks.extend', $task), [
        'hours' => 0,
        'minutes' => 30,
    ])->assertRedirect()
        ->assertSessionHasErrors('task');
});

test('extend updates deadline and deployment time for in progress tasks', function () {
    Bus::fake();

    Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00', 'UTC'));

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
    ]);

    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 30,
        'expires_at' => now()->addMinutes(30),
        'batch_id' => 'existing-batch',
    ]);

    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    $this->post(route('tasks.extend', $task), [
        'hours' => 1,
        'minutes' => 0,
    ])->assertRedirect()
        ->assertSessionHas('success');

    $task->refresh();

    expect($task->deployment_time)->toBe(90)
        ->and($task->expiresAt()?->equalTo(now()->addMinutes(90)))->toBeTrue()
        ->and($task->status_log)->toContain('Task deadline extended to');

    Bus::assertBatched(fn ($batch) => count($batch->jobs) === 1);

    Carbon::setTestNow();
});

test('extend adds time from now when the current deadline is already past', function () {
    Bus::fake();

    Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00', 'UTC'));

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
    ]);

    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 10,
        'expires_at' => now()->subMinutes(5),
    ]);

    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    $this->post(route('tasks.extend', $task), [
        'hours' => 0,
        'minutes' => 20,
    ])->assertRedirect()
        ->assertSessionHas('success');

    $task->refresh();

    expect($task->expiresAt()?->equalTo(now()->addMinutes(20)))->toBeTrue();

    Carbon::setTestNow();
});

test('task show includes deadline props for device tasks', function () {
    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 15,
        'expires_at' => now()->addMinutes(15),
    ]);

    $this->get(route('tasks.show', $task))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Task/DeviceTask')
            ->where('task.expires_at', $task->expires_at->toIso8601String())
            ->where('task.can_extend', true)
        );
});

test('extend updates custom provision workflow deadline and redispatches running steps', function () {
    Queue::fake();

    Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00', 'UTC'));

    $device = Device::factory()->for($this->deployment)->create();
    $workflow = ProvisioningWorkflow::query()->create([
        'deployment_id' => $this->deployment->id,
        'user_id' => $this->user->id,
        'status' => 'running',
        'job_queue' => 'q0',
        'deployment_time' => 20,
        'wait_time' => 1,
        'steps' => [ProvisioningStep::AssociateSite->value],
    ]);

    $workflowDevice = ProvisioningWorkflowDevice::query()->create([
        'provisioning_workflow_id' => $workflow->id,
        'device_id' => $device->id,
        'overall_status' => 'in_progress',
        'current_step_key' => ProvisioningStep::AssociateSite->value,
    ]);

    $workflowDevice->steps()->create([
        'step_key' => ProvisioningStep::AssociateSite->value,
        'step_order' => 1,
        'status' => 'in_progress',
    ]);

    $task = app(ProvisioningWorkflowTaskSync::class)->createForWorkflow(
        $workflow->fresh(['workflowDevices']),
        $this->deployment,
    );

    $task->update([
        'expires_at' => now()->addMinutes(20),
        'status' => 'IN_PROGRESS',
    ]);

    $this->post(route('tasks.extend', $task), [
        'hours' => 0,
        'minutes' => 15,
    ])->assertRedirect()
        ->assertSessionHas('success');

    $workflow->refresh();
    $task->refresh();

    expect($workflow->deployment_time)->toBe(35)
        ->and($task->deployment_time)->toBe(35)
        ->and($task->expiresAt()?->equalTo(now()->addMinutes(35)))->toBeTrue();

    Queue::assertPushed(RunProvisioningWorkflowStepJob::class, function (RunProvisioningWorkflowStepJob $job) use ($workflowDevice) {
        return $job->workflowDeviceId === $workflowDevice->id
            && $job->stepKey === ProvisioningStep::AssociateSite->value;
    });

    Carbon::setTestNow();
});

test('fail wait for online timeout job no-ops when linked task deadline is still in the future', function () {
    $device = Device::factory()->for($this->deployment)->create();
    $workflow = ProvisioningWorkflow::query()->create([
        'deployment_id' => $this->deployment->id,
        'user_id' => $this->user->id,
        'status' => 'running',
        'job_queue' => 'q0',
        'deployment_time' => 10,
        'wait_time' => 1,
        'steps' => [ProvisioningStep::WaitForOnline->value],
    ]);

    $workflowDevice = ProvisioningWorkflowDevice::query()->create([
        'provisioning_workflow_id' => $workflow->id,
        'device_id' => $device->id,
        'overall_status' => 'in_progress',
        'current_step_key' => ProvisioningStep::WaitForOnline->value,
    ]);

    $workflowDevice->steps()->create([
        'step_key' => ProvisioningStep::WaitForOnline->value,
        'step_order' => 1,
        'status' => 'in_progress',
    ]);

    $task = app(ProvisioningWorkflowTaskSync::class)->createForWorkflow(
        $workflow->fresh(['workflowDevices']),
        $this->deployment,
    );

    $task->update([
        'expires_at' => now()->addHour(),
        'status' => 'IN_PROGRESS',
    ]);

    app(FailWaitForOnlineOnTimeoutJob::class, ['workflowDeviceId' => $workflowDevice->id])
        ->handle(app(\App\Services\Provisioning\ProvisioningWorkflowOrchestrator::class));

    $workflowDevice->refresh();

    expect($workflowDevice->overall_status)->toBe('in_progress')
        ->and($workflowDevice->steps()->where('step_key', ProvisioningStep::WaitForOnline->value)->value('status'))
        ->toBe('in_progress');
});
