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
            ->has('available_steps')
            ->where('available_steps.0.step_key', ProvisioningStep::VerifyLicensing->value)
        );
});

function provisionLicensedDevice(Deployment $deployment, Client $client, array $overrides = []): Device
{
    $device = Device::factory()->for($deployment)->create(array_merge([
        'license_tag' => 'pool-a',
        'license_type' => 'Advanced AP',
        'group' => 'TestGroup',
    ], $overrides));

    if (! $client->clientSubscriptions()->where('subscription_key', 'sub-key')->exists()) {
        ClientSubscription::factory()->for($client)->create([
            'subscription_key' => 'sub-key',
            'greenlake_subscription_id' => 'gl-sub-1',
            'tags' => ['pool-a'],
            'license_type' => 'Advanced AP',
            'available' => 5,
        ]);
    }

    LicensingInventoryDevice::factory()->for($client)->create([
        'serial' => $device->serial,
        'greenlake_device_id' => 'gl-'.$device->serial,
        'licensed' => true,
        'subscription_key' => 'sub-key',
    ]);

    return $device;
}

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

it('starts a workflow at the selected step and skips earlier steps', function () {
    Queue::fake();

    $device = provisionLicensedDevice($this->deployment, $this->client);

    $this->actingAs($this->user);

    $this->post(route('deployments.provision.store', $this->deployment), [
        'device_ids' => [$device->id],
        'deployment_time' => 10,
        'wait_time' => 1,
        'start_step' => ProvisioningStep::AssociateSite->value,
    ])->assertRedirect(route('deployments.provision', $this->deployment));

    $workflowDevice = ProvisioningWorkflowDevice::query()->first();
    expect($workflowDevice->current_step_key)->toBe(ProvisioningStep::AssociateSite->value)
        ->and($workflowDevice->steps()->where('step_key', ProvisioningStep::VerifyLicensing->value)->value('status'))->toBe('skipped')
        ->and($workflowDevice->steps()->where('step_key', ProvisioningStep::PreprovisionGroup->value)->value('status'))->toBe('skipped')
        ->and($workflowDevice->steps()->where('step_key', ProvisioningStep::AssociateSite->value)->value('status'))->toBe('in_progress');

    Queue::assertPushed(RunProvisioningWorkflowStepJob::class, function (RunProvisioningWorkflowStepJob $job) {
        return $job->stepKey === ProvisioningStep::AssociateSite->value;
    });
});

it('omits selected steps after the start step', function () {
    Queue::fake();

    $device = provisionLicensedDevice($this->deployment, $this->client);

    $this->actingAs($this->user);

    $this->post(route('deployments.provision.store', $this->deployment), [
        'device_ids' => [$device->id],
        'deployment_time' => 10,
        'wait_time' => 1,
        'start_step' => ProvisioningStep::AssociateSite->value,
        'omit_steps' => [
            ProvisioningStep::NameDevice->value,
            ProvisioningStep::ConfigureMirrorSessions->value,
        ],
    ])->assertRedirect(route('deployments.provision', $this->deployment));

    $workflowDevice = ProvisioningWorkflowDevice::query()->first();
    expect($workflowDevice->steps()->where('step_key', ProvisioningStep::NameDevice->value)->value('status'))->toBe('skipped')
        ->and($workflowDevice->steps()->where('step_key', ProvisioningStep::NameDevice->value)->value('message'))->toBe('Omitted by user.')
        ->and($workflowDevice->steps()->where('step_key', ProvisioningStep::ConfigureMirrorSessions->value)->value('status'))->toBe('skipped');
});

it('rejects omitting the start step', function () {
    Queue::fake();

    $device = provisionLicensedDevice($this->deployment, $this->client);

    $this->actingAs($this->user);

    $this->post(route('deployments.provision.store', $this->deployment), [
        'device_ids' => [$device->id],
        'deployment_time' => 10,
        'wait_time' => 1,
        'start_step' => ProvisioningStep::AssociateSite->value,
        'omit_steps' => [ProvisioningStep::AssociateSite->value],
    ])->assertSessionHasErrors('omit_steps');

    expect(ProvisioningWorkflow::query()->count())->toBe(0);
});

it('rejects omit steps that are before the start step', function () {
    Queue::fake();

    $device = provisionLicensedDevice($this->deployment, $this->client);

    $this->actingAs($this->user);

    $this->post(route('deployments.provision.store', $this->deployment), [
        'device_ids' => [$device->id],
        'deployment_time' => 10,
        'wait_time' => 1,
        'start_step' => ProvisioningStep::AssociateSite->value,
        'omit_steps' => [ProvisioningStep::VerifyLicensing->value],
    ])->assertSessionHasErrors('omit_steps');
});

it('returns a licensing preflight warning for unlicensed devices', function () {
    $unlicensed = Device::factory()->for($this->deployment)->create([
        'license_tag' => 'pool-a',
        'license_type' => 'Advanced AP',
        'group' => 'TestGroup',
        'serial' => 'UNLICENSED01',
    ]);
    $licensed = provisionLicensedDevice($this->deployment, $this->client, [
        'serial' => 'LICENSED0001',
    ]);

    LicensingInventoryDevice::factory()->for($this->client)->create([
        'serial' => $unlicensed->serial,
        'greenlake_device_id' => 'gl-unlicensed',
        'licensed' => false,
        'subscription_key' => '',
    ]);

    $this->actingAs($this->user);

    $response = $this->postJson(route('deployments.provision.preflight', $this->deployment), [
        'device_ids' => [$unlicensed->id, $licensed->id],
        'start_step' => ProvisioningStep::PreprovisionGroup->value,
    ]);

    $response->assertOk()
        ->assertJsonPath('has_warnings', true);

    $remediations = $response->json('remediations');
    expect($remediations)->toHaveCount(1)
        ->and($remediations[0]['task_type'])->toBe('ASSIGN_SUBSCRIPTION')
        ->and($remediations[0]['device_ids'])->toBe([$unlicensed->id]);

    $devices = collect($response->json('devices'));
    $unlicensedResult = $devices->firstWhere('device_id', $unlicensed->id);
    $licensedResult = $devices->firstWhere('device_id', $licensed->id);

    expect($unlicensedResult['steps'][0]['status'])->toBe('warn')
        ->and($licensedResult['steps'][0]['status'])->toBe('ok');
});

it('marks interface steps before start as unchecked in preflight', function () {
    $device = provisionLicensedDevice($this->deployment, $this->client, [
        'device_function' => \App\DeviceFunction::ACCESS_SWITCH->name,
    ]);
    \App\Models\DeviceInterface::factory()->for($device)->create([
        'interface_kind' => \App\InterfaceKind::VLAN,
    ]);
    \App\Models\DeviceInterface::factory()->for($device)->create([
        'interface_kind' => \App\InterfaceKind::LAG,
    ]);

    $this->actingAs($this->user);

    $response = $this->postJson(route('deployments.provision.preflight', $this->deployment), [
        'device_ids' => [$device->id],
        'start_step' => ProvisioningStep::ConfigureEthernetInterfaces->value,
    ]);

    $response->assertOk();

    $steps = collect($response->json('devices.0.steps'));
    expect($steps->firstWhere('step_key', ProvisioningStep::ConfigureVlanInterfaces->value)['status'])->toBe('unchecked')
        ->and($steps->firstWhere('step_key', ProvisioningStep::ConfigureLagInterfaces->value)['status'])->toBe('unchecked');
});

it('splits VSF and VSX stack profile remediations by device', function () {
    $vsf = provisionLicensedDevice($this->deployment, $this->client, [
        'serial' => 'VSFDEVICE001',
        'sku' => \App\SwitchSKU::JL724A,
        'stack_id' => null,
        'device_function' => \App\DeviceFunction::ACCESS_SWITCH->name,
        'scope_id' => 'scope-1',
    ]);
    $vsx = provisionLicensedDevice($this->deployment, $this->client, [
        'serial' => 'VSXDEVICE001',
        'sku' => null,
        'vsx_profile' => 'vsx-pair-a',
        'device_function' => \App\DeviceFunction::ACCESS_SWITCH->name,
        'scope_id' => 'scope-2',
    ]);

    $this->actingAs($this->user);

    $response = $this->postJson(route('deployments.provision.preflight', $this->deployment), [
        'device_ids' => [$vsf->id, $vsx->id],
        'start_step' => ProvisioningStep::ConfigureLagInterfaces->value,
    ]);

    $response->assertOk();

    $remediations = collect($response->json('remediations'))
        ->where('step_key', ProvisioningStep::CreateStackProfile->value)
        ->values();

    $vsfRemediation = $remediations->firstWhere('task_type', 'CREATE_VSF_PROFILE');
    $vsxRemediation = $remediations->firstWhere('task_type', 'CREATE_VSX_PROFILE');

    expect($vsfRemediation)->not->toBeNull()
        ->and($vsfRemediation['device_ids'])->toBe([$vsf->id])
        ->and($vsxRemediation)->not->toBeNull()
        ->and($vsxRemediation['device_ids'])->toBe([$vsx->id]);
});

it('advances past omitted steps after a completed step', function () {
    Queue::fake([RunProvisioningWorkflowStepJob::class]);

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
        'overall_status' => 'in_progress',
        'current_step_key' => ProvisioningStep::AssociateSite->value,
    ]);

    foreach (ProvisioningStep::ordered() as $step) {
        $status = 'pending';
        if ($step->order() < ProvisioningStep::AssociateSite->order()) {
            $status = 'skipped';
        } elseif ($step === ProvisioningStep::NameDevice) {
            $status = 'skipped';
        }

        $workflowDevice->steps()->create([
            'step_key' => $step->value,
            'step_order' => $step->order(),
            'status' => $status,
            'message' => $status === 'skipped' && $step === ProvisioningStep::NameDevice ? 'Omitted by user.' : null,
        ]);
    }

    app(ProvisioningWorkflowOrchestrator::class)->processStepResult(
        $workflowDevice->fresh(['steps', 'workflow', 'device']),
        ProvisioningStep::AssociateSite,
        ProvisioningStepResult::completed('Associated to site.'),
    );

    $workflowDevice->refresh();
    expect($workflowDevice->current_step_key)->toBe(ProvisioningStep::ResolveScopeId->value);

    Queue::assertPushed(RunProvisioningWorkflowStepJob::class, function (RunProvisioningWorkflowStepJob $job) {
        return $job->stepKey === ProvisioningStep::ResolveScopeId->value;
    });
});

it('launches a remediation-shaped assign subscription task for a device subset', function () {
    Queue::fake();

    $failed = Device::factory()->for($this->deployment)->create([
        'license_tag' => 'pool-a',
        'license_type' => 'Advanced AP',
    ]);
    $ok = Device::factory()->for($this->deployment)->create([
        'license_tag' => 'pool-a',
        'license_type' => 'Advanced AP',
    ]);

    ClientSubscription::factory()->for($this->client)->create([
        'subscription_key' => 'sub-key',
        'greenlake_subscription_id' => 'gl-sub-1',
        'tags' => ['pool-a'],
        'license_type' => 'Advanced AP',
        'available' => 5,
    ]);

    LicensingInventoryDevice::factory()->for($this->client)->create([
        'serial' => $failed->serial,
        'greenlake_device_id' => 'gl-failed',
        'licensed' => false,
        'subscription_key' => '',
    ]);
    LicensingInventoryDevice::factory()->for($this->client)->create([
        'serial' => $ok->serial,
        'greenlake_device_id' => 'gl-ok',
        'licensed' => true,
        'subscription_key' => 'sub-key',
    ]);

    $this->actingAs($this->user);

    $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'ASSIGN_SUBSCRIPTION',
        'devices' => [['id' => $failed->id]],
        'deployment_time' => 10,
        'wait_time' => 1,
        'licensing_mode' => 'uniform',
        'license_tag' => 'pool-a',
        'license_type' => 'Advanced AP',
    ])->assertRedirect();

    $task = \App\Models\Task::query()->latest('id')->first();
    expect($task)->not->toBeNull()
        ->and($task->task_type)->toBe('ASSIGN_SUBSCRIPTION')
        ->and($task->devices()->pluck('devices.id')->all())->toBe([$failed->id]);
});

it('renders the custom provisioning workflow page with templates', function () {
    $this->actingAs($this->user);

    $this->get(route('deployments.custom_provision', $this->deployment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Deployment/CustomProvision')
            ->where('deployment.id', $this->deployment->id)
            ->has('available_steps')
            ->has('templates')
        );
});

it('rejects an illegal custom step order', function () {
    Queue::fake();
    $device = provisionLicensedDevice($this->deployment, $this->client);
    $this->actingAs($this->user);

    $this->post(route('deployments.provision.store', $this->deployment), [
        'device_ids' => [$device->id],
        'deployment_time' => 10,
        'wait_time' => 1,
        'steps' => [
            ProvisioningStep::AssociateSite->value,
            ProvisioningStep::VerifyLicensing->value,
        ],
        'name' => 'Bad order',
    ])->assertSessionHasErrors('steps');
});

it('starts a custom workflow with only selected steps in custom order', function () {
    Queue::fake();
    $device = provisionLicensedDevice($this->deployment, $this->client, [
        'device_function' => 'ACCESS_SWITCH',
    ]);
    $this->actingAs($this->user);

    $steps = [
        ProvisioningStep::AssociateSite->value,
        ProvisioningStep::ConfigureEthernetInterfaces->value,
        ProvisioningStep::ConfigureVlanInterfaces->value,
    ];

    $this->post(route('deployments.provision.store', $this->deployment), [
        'device_ids' => [$device->id],
        'deployment_time' => 10,
        'wait_time' => 1,
        'steps' => $steps,
        'name' => 'Site then interfaces',
        'save_as_template' => true,
        'template_name' => 'Site then interfaces',
    ])->assertRedirect(route('deployments.custom_provision', $this->deployment));

    $workflow = ProvisioningWorkflow::query()->first();
    expect($workflow)->not->toBeNull()
        ->and($workflow->name)->toBe('Site then interfaces')
        ->and($workflow->steps)->toBe($steps)
        ->and($workflow->provisioning_workflow_template_id)->not->toBeNull();

    $workflowDevice = $workflow->workflowDevices()->first();
    $stepKeys = $workflowDevice->steps()->orderBy('step_order')->pluck('step_key')->all();
    expect($stepKeys)->toBe($steps)
        ->and($workflowDevice->steps)->toHaveCount(3);

    $template = \App\Models\ProvisioningWorkflowTemplate::query()->first();
    expect($template)->not->toBeNull()
        ->and($template->name)->toBe('Site then interfaces')
        ->and($template->steps)->toBe($steps)
        ->and($template->client_id)->toBe($this->client->id);

    Queue::assertPushed(RunProvisioningWorkflowStepJob::class, function (RunProvisioningWorkflowStepJob $job) {
        return $job->stepKey === ProvisioningStep::AssociateSite->value;
    });
});

it('advances a custom workflow in the user-defined step order', function () {
    Queue::fake();
    $device = provisionLicensedDevice($this->deployment, $this->client, [
        'device_function' => 'ACCESS_SWITCH',
    ]);
    $this->actingAs($this->user);

    $this->post(route('deployments.provision.store', $this->deployment), [
        'device_ids' => [$device->id],
        'deployment_time' => 10,
        'wait_time' => 1,
        'steps' => [
            ProvisioningStep::AssignDeviceFunction->value,
            ProvisioningStep::WaitForOnline->value,
            ProvisioningStep::NameDevice->value,
        ],
        'name' => 'Free reorder',
    ])->assertRedirect(route('deployments.custom_provision', $this->deployment));

    $workflowDevice = ProvisioningWorkflowDevice::query()->first();
    $orchestrator = app(ProvisioningWorkflowOrchestrator::class);

    $orchestrator->processStepResult(
        $workflowDevice->fresh(['steps', 'device', 'workflow']),
        ProvisioningStep::AssignDeviceFunction,
        ProvisioningStepResult::completed('Function assigned'),
    );

    $workflowDevice->refresh();
    expect($workflowDevice->current_step_key)->toBe(ProvisioningStep::WaitForOnline->value);

    Queue::assertPushed(RunProvisioningWorkflowStepJob::class, function (RunProvisioningWorkflowStepJob $job) {
        return $job->stepKey === ProvisioningStep::WaitForOnline->value;
    });
});

it('launches a custom workflow from a saved template', function () {
    Queue::fake();
    $device = provisionLicensedDevice($this->deployment, $this->client);
    $this->actingAs($this->user);

    $template = \App\Models\ProvisioningWorkflowTemplate::query()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'name' => 'Preprovision only',
        'steps' => [ProvisioningStep::PreprovisionGroup->value],
    ]);

    $this->post(route('deployments.provision.store', $this->deployment), [
        'device_ids' => [$device->id],
        'deployment_time' => 10,
        'wait_time' => 1,
        'template_id' => $template->id,
        'name' => 'From template',
    ])->assertRedirect(route('deployments.custom_provision', $this->deployment));

    $workflow = ProvisioningWorkflow::query()->first();
    expect($workflow->steps)->toBe([ProvisioningStep::PreprovisionGroup->value])
        ->and($workflow->provisioning_workflow_template_id)->toBe($template->id)
        ->and($workflow->name)->toBe('From template');
});

it('keeps the diva path unchanged when steps are omitted', function () {
    Queue::fake();
    $device = provisionLicensedDevice($this->deployment, $this->client);
    $this->actingAs($this->user);

    $this->post(route('deployments.provision.store', $this->deployment), [
        'device_ids' => [$device->id],
        'deployment_time' => 10,
        'wait_time' => 1,
    ])->assertRedirect(route('deployments.provision', $this->deployment));

    $workflow = ProvisioningWorkflow::query()->first();
    expect($workflow->steps)->toBeNull()
        ->and($workflow->name)->toBeNull()
        ->and($workflow->workflowDevices()->first()->steps)->toHaveCount(14);
});
