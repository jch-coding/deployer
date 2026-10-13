<?php

use App\Enums\ProvisioningStep;
use App\InterfaceKind;
use App\Jobs\RunProvisioningWorkflowStepJob;
use App\Models\Client;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\ProvisioningWorkflow;
use App\Models\ProvisioningWorkflowDevice;
use App\Models\Task;
use App\Models\User;
use App\Services\Provisioning\ProvisioningWorkflowTaskSync;
use App\Services\RelaunchFailedCriticalConfigService;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()
        ->has(Client::factory())
        ->create();
    $this->client = $this->user->clients()->first();
    $this->client->update(['current' => true]);
    withClassicCentralCredentials($this->client);
    $this->deployment = $this->client->deployments()->create(['name' => 'Test Deployment']);
    $this->actingAs($this->user);
});

test('relaunching a failed task sets status in progress, updates batch id, and dispatches only non-completed pivots', function () {
    Bus::fake();

    $devices = Device::factory(2)->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'device_function' => 'ACCESS_SWITCH',
    ]);
    fakeClassicMonitoringOnline($devices);

    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'FAILED',
        'batch_id' => 'old-batch-id',
    ]);

    $task->devices()->attach($devices[0]->id, ['status' => 'COMPLETED']);
    $task->devices()->attach($devices[1]->id, ['status' => 'FAILED']);

    $this->post(route('tasks.relaunch', $task))
        ->assertRedirect(route('tasks.show', $task));

    $task->refresh();
    expect($task->status)->toBe('IN_PROGRESS');
    expect($task->batch_id)->not->toBeNull();
    expect($task->batch_id)->not->toBe('old-batch-id');

    Bus::assertBatched(fn (PendingBatch $batch) => count($batch->jobs) === 1);

    $task->load('devices');
    expect($task->devices->firstWhere('id', $devices[0]->id)->pivot->status)->toBe('COMPLETED');
    expect($task->devices->firstWhere('id', $devices[1]->id)->pivot->status)->toBe('PENDING');
});

test('relaunching a cancelled task sets status in progress and redirects to show', function () {
    Bus::fake();

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'device_function' => 'ACCESS_SWITCH',
    ]);
    fakeClassicMonitoringOnline([$device]);

    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'CANCELLED',
        'batch_id' => 'old-batch-id',
    ]);

    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    $this->post(route('tasks.relaunch', $task))
        ->assertRedirect(route('tasks.show', $task));

    $task->refresh();
    expect($task->status)->toBe('IN_PROGRESS');
    expect($task->batch_id)->not->toBeNull();
    expect($task->batch_id)->not->toBe('old-batch-id');
});

test('relaunching a timed out task sets status in progress and redirects to show', function () {
    Bus::fake();

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'device_function' => 'ACCESS_SWITCH',
    ]);
    fakeClassicMonitoringOnline([$device]);

    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'TIMED_OUT',
        'batch_id' => 'old-batch-id',
    ]);

    $task->devices()->attach($device->id, ['status' => 'TIMED_OUT']);

    $this->post(route('tasks.relaunch', $task))
        ->assertRedirect(route('tasks.show', $task));

    $task->refresh();
    expect($task->status)->toBe('IN_PROGRESS');
    expect($task->batch_id)->not->toBeNull();
    expect($task->batch_id)->not->toBe('old-batch-id');

    $task->load('devices');
    expect($task->devices->first()->pivot->status)->toBe('PENDING');
});

test('relaunch with custom timers persists deployment_time and wait_time', function () {
    Bus::fake();

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'device_function' => 'ACCESS_SWITCH',
    ]);
    fakeClassicMonitoringOnline([$device]);

    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'FAILED',
        'deployment_time' => 1,
        'wait_time' => 1,
    ]);

    $task->devices()->attach($device->id, ['status' => 'FAILED']);

    $this->post(route('tasks.relaunch', $task), [
        'deployment_time' => 90,
        'wait_time' => 5,
    ])->assertRedirect(route('tasks.show', $task));

    $task->refresh();
    expect($task->status)->toBe('IN_PROGRESS')
        ->and($task->deployment_time)->toBe(90)
        ->and($task->wait_time)->toBe(5);
});

test('relaunch rejects non-failed-and-non-cancelled task statuses', function () {
    Bus::fake();

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
    ]);

    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'IN_PROGRESS',
    ]);

    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    $this->from(route('tasks.index'))
        ->post(route('tasks.relaunch', $task))
        ->assertRedirect(route('tasks.index'))
        ->assertSessionHas('error', 'Only failed, timed out, or cancelled tasks can be relaunched.');

    $task->refresh();
    expect($task->status)->toBe('IN_PROGRESS');
    Bus::assertNothingBatched();
});

test('relaunching a failed composite remediation group relaunches all siblings', function () {
    Bus::fake();

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
    ]);
    $lagInterface = DeviceInterface::factory()->create([
        'device_id' => $device->id,
        'interface_kind' => InterfaceKind::LAG,
    ]);
    $vlanInterface = DeviceInterface::factory()->create([
        'device_id' => $device->id,
        'interface_kind' => InterfaceKind::VLAN,
    ]);

    $group = (string) Str::uuid();
    $jobQueue = 'q0';
    $first = Task::factory()->for($this->deployment)->create([
        'task_type' => 'CONFIGURE_LAG_INTERFACE',
        'status' => 'FAILED',
        'job_queue' => $jobQueue,
        'composite_group_id' => $group,
        'composite_kind' => RelaunchFailedCriticalConfigService::COMPOSITE_KIND,
        'composite_order' => 1,
    ]);
    $second = Task::factory()->for($this->deployment)->create([
        'task_type' => 'CONFIGURE_VLAN_INTERFACE',
        'status' => 'FAILED',
        'job_queue' => $jobQueue,
        'composite_group_id' => $group,
        'composite_kind' => RelaunchFailedCriticalConfigService::COMPOSITE_KIND,
        'composite_order' => 2,
    ]);

    $first->devices()->attach($device->id, ['status' => 'FAILED']);
    $first->deviceInterfaces()->attach([$lagInterface->id => ['status' => 'FAILED']]);
    $second->devices()->attach($device->id, ['status' => 'FAILED']);
    $second->deviceInterfaces()->attach([$vlanInterface->id => ['status' => 'FAILED']]);

    $this->post(route('tasks.relaunch', $first))
        ->assertRedirect(route('tasks.show', $first));

    expect($first->refresh()->status)->toBe('IN_PROGRESS');
    expect($second->refresh()->status)->toBe('IN_PROGRESS');

    $batches = Bus::batched(fn (PendingBatch $batch) => count($batch->jobs) === 1);
    expect($batches)->toHaveCount(2);
});

test('relaunching a failed custom provision task resets failed devices to in progress and redispatches', function () {
    Queue::fake();

    $failedDevice = Device::factory()->for($this->deployment)->create([
        'client_id' => $this->client->id,
        'device_function' => 'ACCESS_SWITCH',
    ]);
    $completedDevice = Device::factory()->for($this->deployment)->create([
        'client_id' => $this->client->id,
        'device_function' => 'ACCESS_SWITCH',
    ]);

    $workflow = ProvisioningWorkflow::query()->create([
        'deployment_id' => $this->deployment->id,
        'user_id' => $this->user->id,
        'status' => 'running',
        'job_queue' => 'q0',
        'deployment_time' => 10,
        'wait_time' => 1,
        'steps' => [
            ProvisioningStep::AssociateSite->value,
            ProvisioningStep::ConfigureVlanInterfaces->value,
        ],
        'started_at' => now(),
    ]);

    $failedWorkflowDevice = ProvisioningWorkflowDevice::query()->create([
        'provisioning_workflow_id' => $workflow->id,
        'device_id' => $failedDevice->id,
        'overall_status' => 'failed',
        'failed_step_key' => ProvisioningStep::ConfigureVlanInterfaces->value,
        'current_step_key' => ProvisioningStep::ConfigureVlanInterfaces->value,
        'status_message' => 'VLAN configuration failed.',
    ]);
    $failedWorkflowDevice->steps()->create([
        'step_key' => ProvisioningStep::AssociateSite->value,
        'step_order' => 1,
        'status' => 'completed',
        'completed_at' => now(),
    ]);
    $failedWorkflowDevice->steps()->create([
        'step_key' => ProvisioningStep::ConfigureVlanInterfaces->value,
        'step_order' => 2,
        'status' => 'failed',
    ]);

    $completedWorkflowDevice = ProvisioningWorkflowDevice::query()->create([
        'provisioning_workflow_id' => $workflow->id,
        'device_id' => $completedDevice->id,
        'overall_status' => 'completed',
        'current_step_key' => null,
        'failed_step_key' => null,
        'status_message' => 'Workflow completed successfully.',
    ]);
    $completedWorkflowDevice->steps()->create([
        'step_key' => ProvisioningStep::AssociateSite->value,
        'step_order' => 1,
        'status' => 'completed',
        'completed_at' => now(),
    ]);
    $completedWorkflowDevice->steps()->create([
        'step_key' => ProvisioningStep::ConfigureVlanInterfaces->value,
        'step_order' => 2,
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    $task = app(ProvisioningWorkflowTaskSync::class)->createForWorkflow(
        $workflow->fresh(['workflowDevices']),
        $this->deployment,
    );
    $task->update(['status' => 'FAILED']);
    $task->devices()->updateExistingPivot($failedDevice->id, ['status' => 'FAILED']);

    $this->post(route('tasks.relaunch', $task))
        ->assertRedirect(route('tasks.show', $task));

    $task->refresh()->load('devices');
    $failedWorkflowDevice->refresh();
    $completedWorkflowDevice->refresh();

    expect($task->status)->toBe('IN_PROGRESS')
        ->and($failedWorkflowDevice->overall_status)->toBe('in_progress')
        ->and($failedWorkflowDevice->failed_step_key)->toBeNull()
        ->and($completedWorkflowDevice->overall_status)->toBe('completed')
        ->and($task->devices->firstWhere('id', $failedDevice->id)->pivot->status)->toBe('IN_PROGRESS')
        ->and($task->devices->firstWhere('id', $completedDevice->id)->pivot->status)->toBe('COMPLETED');

    Queue::assertPushed(RunProvisioningWorkflowStepJob::class, function (RunProvisioningWorkflowStepJob $job) use ($failedWorkflowDevice) {
        return $job->workflowDeviceId === $failedWorkflowDevice->id
            && $job->stepKey === ProvisioningStep::ConfigureVlanInterfaces->value;
    });

    Queue::assertNotPushed(RunProvisioningWorkflowStepJob::class, function (RunProvisioningWorkflowStepJob $job) use ($completedWorkflowDevice) {
        return $job->workflowDeviceId === $completedWorkflowDevice->id;
    });
});

test('relaunching a timed out custom provision task resets incomplete devices to in progress and redispatches', function () {
    Queue::fake();

    $device = Device::factory()->for($this->deployment)->create([
        'client_id' => $this->client->id,
        'device_function' => 'ACCESS_SWITCH',
    ]);

    $workflow = ProvisioningWorkflow::query()->create([
        'deployment_id' => $this->deployment->id,
        'user_id' => $this->user->id,
        'status' => 'running',
        'job_queue' => 'q0',
        'deployment_time' => 10,
        'wait_time' => 1,
        'steps' => [ProvisioningStep::AssociateSite->value],
        'started_at' => now(),
    ]);

    $workflowDevice = ProvisioningWorkflowDevice::query()->create([
        'provisioning_workflow_id' => $workflow->id,
        'device_id' => $device->id,
        'overall_status' => 'in_progress',
        'current_step_key' => ProvisioningStep::AssociateSite->value,
        'failed_step_key' => null,
        'status_message' => 'Associating site...',
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
    $task->update(['status' => 'TIMED_OUT']);
    $task->devices()->updateExistingPivot($device->id, ['status' => 'TIMED_OUT']);

    $this->post(route('tasks.relaunch', $task))
        ->assertRedirect(route('tasks.show', $task));

    $task->refresh()->load('devices');
    $workflowDevice->refresh();

    expect($task->status)->toBe('IN_PROGRESS')
        ->and($workflowDevice->overall_status)->toBe('in_progress')
        ->and($workflowDevice->failed_step_key)->toBeNull()
        ->and($task->devices->first()->pivot->status)->toBe('IN_PROGRESS');

    Queue::assertPushed(RunProvisioningWorkflowStepJob::class, function (RunProvisioningWorkflowStepJob $job) use ($workflowDevice) {
        return $job->workflowDeviceId === $workflowDevice->id
            && $job->stepKey === ProvisioningStep::AssociateSite->value;
    });
});
