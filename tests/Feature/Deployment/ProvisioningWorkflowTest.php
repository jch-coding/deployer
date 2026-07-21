<?php

use App\Enums\OnlineDetectionMode;
use App\Enums\ProvisioningStep;
use App\Jobs\FailWaitForOnlineOnTimeoutJob;
use App\Jobs\PollClassicDeviceOnlineJob;
use App\Jobs\RunProvisioningWorkflowStepJob;
use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\LicensingInventoryDevice;
use App\Models\ProvisioningWorkflow;
use App\Models\ProvisioningWorkflowDevice;
use App\Models\User;
use App\Services\Provisioning\ProvisioningStepResult;
use App\Services\Provisioning\ProvisioningWorkflowOrchestrator;
use App\Services\Provisioning\ProvisioningWorkflowService;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->has(Client::factory())->create();
    $this->client = $this->user->clients()->first();
    $this->client->update([
        'current' => true,
        'licensing_synced_at' => now(),
        'licensing_enabled_services' => ['ADVANCED_AP'],
    ]);
    $this->deployment = Deployment::factory()->for($this->client)->create();
    $this->withoutVite();
});

it('renders the provisioning workflow page', function () {
    $this->actingAs($this->user);

    $this->get(route('deployments.provision', $this->deployment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Deployment/Provision')
            ->where('deployment.id', $this->deployment->id)
            ->where('workflow', null)
            ->where('has_classic_webhook_secret', false)
        );
});

it('exposes has_classic_webhook_secret when the client has a secret', function () {
    $this->client->update(['classic_webhook_secret' => 'secret-token']);
    $this->actingAs($this->user);

    $this->get(route('deployments.provision', $this->deployment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('has_classic_webhook_secret', true)
        );
});

it('starts a workflow and dispatches the first step job for each device', function () {
    Queue::fake();

    $device = Device::factory()->for($this->deployment)->create([
        'license_tag' => 'pool-a',
        'license_type' => 'Advanced AP',
        'group' => 'TestGroup',
    ]);

    ClientSubscription::factory()->for($this->client)->create([
        'subscription_key' => 'sub-key',
        'greenlake_subscription_id' => 'gl-sub-1',
        'tags' => ['pool-a'],
        'license_type' => 'Advanced AP',
        'available' => 5,
    ]);

    LicensingInventoryDevice::factory()->for($this->client)->create([
        'serial' => $device->serial,
        'greenlake_device_id' => 'gl-dev-1',
        'licensed' => true,
        'subscription_key' => 'sub-key',
    ]);

    $this->actingAs($this->user);

    $response = $this->post(route('deployments.provision.store', $this->deployment), [
        'device_ids' => [$device->id],
        'deployment_time' => 10,
        'wait_time' => 1,
    ]);

    $response->assertRedirect(route('deployments.provision', $this->deployment));

    $workflow = ProvisioningWorkflow::query()->first();
    expect($workflow)->not->toBeNull()
        ->and($workflow->workflowDevices)->toHaveCount(1);

    Queue::assertPushed(RunProvisioningWorkflowStepJob::class, function (RunProvisioningWorkflowStepJob $job) {
        return $job->stepKey === ProvisioningStep::VerifyLicensing->value;
    });
});

it('skips name device step for APs without an explicit hostname', function () {
    Queue::fake();

    $device = Device::factory()->for($this->deployment)->create([
        'name' => 'SN0000000001',
        'serial' => 'SN0000000001',
        'device_function' => \App\DeviceFunction::CAMPUS_AP->name,
        'license_tag' => 'pool-a',
        'license_type' => 'Advanced AP',
        'group' => 'TestGroup',
    ]);

    ClientSubscription::factory()->for($this->client)->create([
        'subscription_key' => 'sub-key',
        'greenlake_subscription_id' => 'gl-sub-1',
        'tags' => ['pool-a'],
        'license_type' => 'Advanced AP',
        'available' => 5,
    ]);

    LicensingInventoryDevice::factory()->for($this->client)->create([
        'serial' => $device->serial,
        'greenlake_device_id' => 'gl-dev-1',
        'licensed' => true,
        'subscription_key' => 'sub-key',
    ]);

    $this->actingAs($this->user);

    $this->post(route('deployments.provision.store', $this->deployment), [
        'device_ids' => [$device->id],
        'deployment_time' => 10,
        'wait_time' => 1,
    ])->assertRedirect(route('deployments.provision', $this->deployment));

    $workflowDevice = ProvisioningWorkflowDevice::query()->first();
    $nameStep = $workflowDevice->steps()->where('step_key', ProvisioningStep::NameDevice->value)->first();

    expect($nameStep->status)->toBe('skipped');
});

it('updates AP hostname and runs name device step when provided at workflow start', function () {
    Queue::fake();

    $device = Device::factory()->for($this->deployment)->create([
        'name' => 'SN0000000002',
        'serial' => 'SN0000000002',
        'device_function' => \App\DeviceFunction::CAMPUS_AP->name,
        'license_tag' => 'pool-a',
        'license_type' => 'Advanced AP',
        'group' => 'TestGroup',
    ]);

    ClientSubscription::factory()->for($this->client)->create([
        'subscription_key' => 'sub-key',
        'greenlake_subscription_id' => 'gl-sub-1',
        'tags' => ['pool-a'],
        'license_type' => 'Advanced AP',
        'available' => 5,
    ]);

    LicensingInventoryDevice::factory()->for($this->client)->create([
        'serial' => $device->serial,
        'greenlake_device_id' => 'gl-dev-2',
        'licensed' => true,
        'subscription_key' => 'sub-key',
    ]);

    $this->actingAs($this->user);

    $this->post(route('deployments.provision.store', $this->deployment), [
        'device_ids' => [$device->id],
        'deployment_time' => 10,
        'wait_time' => 1,
        'devices' => [
            ['id' => $device->id, 'name' => 'Campus AP East'],
        ],
    ])->assertRedirect(route('deployments.provision', $this->deployment));

    $device->refresh();
    expect($device->name)->toBe('Campus AP East');

    $workflowDevice = ProvisioningWorkflowDevice::query()->first();
    $nameStep = $workflowDevice->steps()->where('step_key', ProvisioningStep::NameDevice->value)->first();

    expect($nameStep->status)->toBe('pending');
});

it('restarts a failed device workflow from the selected step', function () {
    Queue::fake();

    $device = Device::factory()->for($this->deployment)->create();
    $workflow = ProvisioningWorkflow::query()->create([
        'deployment_id' => $this->deployment->id,
        'user_id' => $this->user->id,
        'status' => 'running',
        'job_queue' => 'q0',
        'deployment_time' => 10,
        'wait_time' => 1,
    ]);

    $workflowDevice = ProvisioningWorkflowDevice::query()->create([
        'provisioning_workflow_id' => $workflow->id,
        'device_id' => $device->id,
        'overall_status' => 'failed',
        'failed_step_key' => ProvisioningStep::ConfigureVlanInterfaces->value,
        'current_step_key' => ProvisioningStep::ConfigureVlanInterfaces->value,
        'status_message' => 'Failed VLAN configuration',
    ]);

    foreach (ProvisioningStep::ordered() as $step) {
        $workflowDevice->steps()->create([
            'step_key' => $step->value,
            'step_order' => $step->order(),
            'status' => $step->order() < ProvisioningStep::ConfigureVlanInterfaces->order() ? 'completed' : 'failed',
        ]);
    }

    $this->actingAs($this->user);

    $this->post(route('provisioning_workflow_devices.restart', $workflowDevice), [
        'from_step' => ProvisioningStep::ConfigureVlanInterfaces->value,
    ])->assertRedirect();

    $workflowDevice->refresh();
    expect($workflowDevice->overall_status)->toBe('in_progress')
        ->and($workflowDevice->failed_step_key)->toBeNull()
        ->and($workflowDevice->steps()->where('step_key', ProvisioningStep::ConfigureVlanInterfaces->value)->value('status'))->toBe('in_progress');

    Queue::assertPushed(RunProvisioningWorkflowStepJob::class, function (RunProvisioningWorkflowStepJob $job) use ($workflowDevice) {
        return $job->workflowDeviceId === $workflowDevice->id
            && $job->stepKey === ProvisioningStep::ConfigureVlanInterfaces->value;
    });
});

it('serializes workflow summary counts for the UI', function () {
    $device = Device::factory()->for($this->deployment)->create();
    $workflow = ProvisioningWorkflow::query()->create([
        'deployment_id' => $this->deployment->id,
        'user_id' => $this->user->id,
        'status' => 'running',
        'job_queue' => 'q0',
        'deployment_time' => 10,
        'wait_time' => 1,
        'online_detection_mode' => OnlineDetectionMode::Poll,
    ]);

    $workflow->workflowDevices()->create([
        'device_id' => $device->id,
        'overall_status' => 'in_progress',
        'current_step_key' => ProvisioningStep::PreprovisionGroup->value,
    ]);

    $payload = app(ProvisioningWorkflowService::class)->serializeForUi($workflow->fresh());

    expect($payload['summary']['in_progress'])->toBe(1)
        ->and($payload['devices'])->toHaveCount(1)
        ->and($payload['devices'][0]['name'])->toBe($device->name)
        ->and($payload['online_detection_mode'])->toBe('poll');
});

it('persists poll online detection mode by default', function () {
    Queue::fake();

    $device = Device::factory()->for($this->deployment)->create([
        'license_tag' => 'pool-a',
        'license_type' => 'Advanced AP',
        'group' => 'TestGroup',
    ]);

    ClientSubscription::factory()->for($this->client)->create([
        'subscription_key' => 'sub-key',
        'greenlake_subscription_id' => 'gl-sub-1',
        'tags' => ['pool-a'],
        'license_type' => 'Advanced AP',
        'available' => 5,
    ]);

    LicensingInventoryDevice::factory()->for($this->client)->create([
        'serial' => $device->serial,
        'greenlake_device_id' => 'gl-dev-1',
        'licensed' => true,
        'subscription_key' => 'sub-key',
    ]);

    $this->actingAs($this->user);

    $this->post(route('deployments.provision.store', $this->deployment), [
        'device_ids' => [$device->id],
        'deployment_time' => 10,
        'wait_time' => 1,
    ])->assertRedirect(route('deployments.provision', $this->deployment));

    $workflow = ProvisioningWorkflow::query()->first();
    expect($workflow->online_detection_mode)->toBe(OnlineDetectionMode::Poll);
});

it('persists webhook online detection mode when the client has a webhook secret', function () {
    Queue::fake();
    $this->client->update(['classic_webhook_secret' => 'secret-token']);

    $device = Device::factory()->for($this->deployment)->create([
        'license_tag' => 'pool-a',
        'license_type' => 'Advanced AP',
        'group' => 'TestGroup',
    ]);

    ClientSubscription::factory()->for($this->client)->create([
        'subscription_key' => 'sub-key',
        'greenlake_subscription_id' => 'gl-sub-1',
        'tags' => ['pool-a'],
        'license_type' => 'Advanced AP',
        'available' => 5,
    ]);

    LicensingInventoryDevice::factory()->for($this->client)->create([
        'serial' => $device->serial,
        'greenlake_device_id' => 'gl-dev-1',
        'licensed' => true,
        'subscription_key' => 'sub-key',
    ]);

    $this->actingAs($this->user);

    $this->post(route('deployments.provision.store', $this->deployment), [
        'device_ids' => [$device->id],
        'deployment_time' => 10,
        'wait_time' => 1,
        'online_detection_mode' => 'webhook',
    ])->assertRedirect(route('deployments.provision', $this->deployment));

    $workflow = ProvisioningWorkflow::query()->first();
    expect($workflow->online_detection_mode)->toBe(OnlineDetectionMode::Webhook);
});

it('rejects webhook online detection mode without a client webhook secret', function () {
    Queue::fake();

    $device = Device::factory()->for($this->deployment)->create([
        'license_tag' => 'pool-a',
        'license_type' => 'Advanced AP',
        'group' => 'TestGroup',
    ]);

    $this->actingAs($this->user);

    $this->post(route('deployments.provision.store', $this->deployment), [
        'device_ids' => [$device->id],
        'deployment_time' => 10,
        'wait_time' => 1,
        'online_detection_mode' => 'webhook',
    ])->assertSessionHasErrors('online_detection_mode');

    expect(ProvisioningWorkflow::query()->count())->toBe(0);
});

it('starts the classic poller for poll-mode wait_for_online retries', function () {
    Queue::fake();

    $device = Device::factory()->for($this->deployment)->create();
    $workflow = ProvisioningWorkflow::query()->create([
        'deployment_id' => $this->deployment->id,
        'user_id' => $this->user->id,
        'status' => 'running',
        'job_queue' => 'q0',
        'deployment_time' => 10,
        'wait_time' => 1,
        'online_detection_mode' => OnlineDetectionMode::Poll,
        'classic_poller_active' => false,
    ]);

    $workflowDevice = ProvisioningWorkflowDevice::query()->create([
        'provisioning_workflow_id' => $workflow->id,
        'device_id' => $device->id,
        'overall_status' => 'in_progress',
        'current_step_key' => ProvisioningStep::WaitForOnline->value,
    ]);

    $workflowDevice->steps()->create([
        'step_key' => ProvisioningStep::WaitForOnline->value,
        'step_order' => ProvisioningStep::WaitForOnline->order(),
        'status' => 'in_progress',
    ]);

    app(ProvisioningWorkflowOrchestrator::class)->processStepResult(
        $workflowDevice,
        ProvisioningStep::WaitForOnline,
        ProvisioningStepResult::retry('Waiting for device to come online (status: Down).'),
    );

    expect($workflow->fresh()->classic_poller_active)->toBeTrue();
    Queue::assertPushed(PollClassicDeviceOnlineJob::class, fn (PollClassicDeviceOnlineJob $job) => $job->workflowId === $workflow->id);
});

it('does not start the classic poller for webhook-mode wait_for_online', function () {
    Queue::fake();

    $device = Device::factory()->for($this->deployment)->create();
    $workflow = ProvisioningWorkflow::query()->create([
        'deployment_id' => $this->deployment->id,
        'user_id' => $this->user->id,
        'status' => 'running',
        'job_queue' => 'q0',
        'deployment_time' => 10,
        'wait_time' => 1,
        'online_detection_mode' => OnlineDetectionMode::Webhook,
        'classic_poller_active' => false,
    ]);

    $workflowDevice = ProvisioningWorkflowDevice::query()->create([
        'provisioning_workflow_id' => $workflow->id,
        'device_id' => $device->id,
        'overall_status' => 'in_progress',
        'current_step_key' => ProvisioningStep::WaitForOnline->value,
    ]);

    $workflowDevice->steps()->create([
        'step_key' => ProvisioningStep::WaitForOnline->value,
        'step_order' => ProvisioningStep::WaitForOnline->order(),
        'status' => 'pending',
    ]);

    app(ProvisioningWorkflowOrchestrator::class)->processStepResult(
        $workflowDevice->fresh(['steps', 'workflow', 'device']),
        ProvisioningStep::WaitForOnline,
        ProvisioningStepResult::waitingPeer('Waiting for device to come online via webhook (status: Down).'),
    );

    expect($workflow->fresh()->classic_poller_active)->toBeFalse();
    Queue::assertNotPushed(PollClassicDeviceOnlineJob::class);
    Queue::assertPushed(FailWaitForOnlineOnTimeoutJob::class, function (FailWaitForOnlineOnTimeoutJob $job) use ($workflowDevice) {
        return $job->workflowDeviceId === $workflowDevice->id;
    });
});
