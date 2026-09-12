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
use App\Models\Task;
use App\Models\User;
use App\Services\Provisioning\ProvisioningStepResult;
use App\Services\Provisioning\ProvisioningWorkflowOrchestrator;
use App\Services\Provisioning\ProvisioningWorkflowService;
use App\Services\Provisioning\ProvisioningWorkflowTaskSync;
use Illuminate\Support\Facades\Http;
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

it('skips scope and hostname preflight when the device is not in New Central', function () {
    $device = provisionLicensedDevice($this->deployment, $this->client, [
        'device_function' => 'CAMPUS_AP',
        'scope_id' => null,
        'name' => 'ap-a',
        'serial' => 'APNOTINCNTRL',
    ]);

    $this->client->update([
        'expires_at' => now()->addHour(),
        'bearer_token' => 'test-bearer-token',
        'base_url' => \App\BaseURL::US1->value,
    ]);

    // APs skip the switches lookup and hit hierarchy directly — empty items used to throw.
    Http::fake([
        '*network-config/v1/hierarchy*' => Http::response(['items' => []], 200),
    ]);

    $this->actingAs($this->user);

    $response = $this->postJson(route('deployments.provision.preflight', $this->deployment), [
        'device_ids' => [$device->id],
        'steps' => [
            ProvisioningStep::ResolveScopeId->value,
            ProvisioningStep::NameDevice->value,
        ],
    ]);

    $response->assertOk();

    $steps = collect($response->json('devices.0.steps'));
    expect($steps->firstWhere('step_key', ProvisioningStep::ResolveScopeId->value)['status'])->toBe('unchecked')
        ->and($steps->firstWhere('step_key', ProvisioningStep::NameDevice->value)['status'])->toBe('unchecked');
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

    $response = $this->post(route('deployments.provision.store', $this->deployment), [
        'device_ids' => [$device->id],
        'deployment_time' => 10,
        'wait_time' => 1,
        'steps' => $steps,
        'name' => 'Site then interfaces',
        'save_as_template' => true,
        'template_name' => 'Site then interfaces',
    ]);

    $task = Task::query()->where('task_type', 'CUSTOM_PROVISION')->latest('id')->first();
    $response->assertRedirect(route('tasks.show', $task));

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

    $response = $this->post(route('deployments.provision.store', $this->deployment), [
        'device_ids' => [$device->id],
        'deployment_time' => 10,
        'wait_time' => 1,
        'steps' => [
            ProvisioningStep::AssignDeviceFunction->value,
            ProvisioningStep::WaitForOnline->value,
            ProvisioningStep::NameDevice->value,
        ],
        'name' => 'Free reorder',
    ]);

    $task = Task::query()->where('task_type', 'CUSTOM_PROVISION')->latest('id')->first();
    $response->assertRedirect(route('tasks.show', $task));

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

    $response = $this->post(route('deployments.provision.store', $this->deployment), [
        'device_ids' => [$device->id],
        'deployment_time' => 10,
        'wait_time' => 1,
        'template_id' => $template->id,
        'name' => 'From template',
    ]);

    $task = Task::query()->where('task_type', 'CUSTOM_PROVISION')->latest('id')->first();
    $response->assertRedirect(route('tasks.show', $task));

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

it('skips wait_for_online when an accepted webhook exists for the device', function () {
    Queue::fake();

    $device = provisionLicensedDevice($this->deployment, $this->client, [
        'serial' => 'SNWEBHOOK1',
        'device_function' => \App\DeviceFunction::CAMPUS_AP->name,
    ]);

    \App\Models\CentralWebhookEvent::query()->create([
        'client_id' => $this->client->id,
        'payload' => [
            'alert_type' => 'New AP detected',
            'details' => ['serial' => 'SNWEBHOOK1'],
        ],
        'alert_type' => 'New AP detected',
        'serial' => 'SNWEBHOOK1',
        'disposition' => 'accepted',
        'created_at' => now(),
    ]);

    $this->actingAs($this->user);

    $this->post(route('deployments.provision.store', $this->deployment), [
        'device_ids' => [$device->id],
        'deployment_time' => 10,
        'wait_time' => 1,
        'start_step' => ProvisioningStep::WaitForOnline->value,
    ])->assertRedirect(route('deployments.provision', $this->deployment));

    $workflowDevice = ProvisioningWorkflowDevice::query()->first();
    $waitStep = $workflowDevice->steps()->where('step_key', ProvisioningStep::WaitForOnline->value)->first();

    expect($waitStep->status)->toBe('skipped')
        ->and($waitStep->message)->toBe('Already online (webhook).')
        ->and($workflowDevice->current_step_key)->toBe(ProvisioningStep::AssociateSite->value);

    Queue::assertPushed(RunProvisioningWorkflowStepJob::class, function (RunProvisioningWorkflowStepJob $job) {
        return $job->stepKey === ProvisioningStep::AssociateSite->value;
    });
});

it('does not skip wait_for_online for ignored webhooks or other serials', function () {
    Queue::fake();

    $device = provisionLicensedDevice($this->deployment, $this->client, [
        'serial' => 'SNDEVICE1',
        'device_function' => \App\DeviceFunction::CAMPUS_AP->name,
    ]);

    \App\Models\CentralWebhookEvent::query()->create([
        'client_id' => $this->client->id,
        'payload' => ['alert_type' => 'AP Disconnected'],
        'alert_type' => 'AP Disconnected',
        'serial' => 'SNDEVICE1',
        'disposition' => 'ignored',
        'created_at' => now(),
    ]);

    \App\Models\CentralWebhookEvent::query()->create([
        'client_id' => $this->client->id,
        'payload' => ['alert_type' => 'New AP detected'],
        'alert_type' => 'New AP detected',
        'serial' => 'OTHERSERIAL',
        'disposition' => 'accepted',
        'created_at' => now(),
    ]);

    $this->actingAs($this->user);

    $this->post(route('deployments.provision.store', $this->deployment), [
        'device_ids' => [$device->id],
        'deployment_time' => 10,
        'wait_time' => 1,
        'start_step' => ProvisioningStep::WaitForOnline->value,
    ])->assertRedirect(route('deployments.provision', $this->deployment));

    $workflowDevice = ProvisioningWorkflowDevice::query()->first();
    $waitStep = $workflowDevice->steps()->where('step_key', ProvisioningStep::WaitForOnline->value)->first();

    expect($waitStep->status)->toBe('in_progress')
        ->and($workflowDevice->current_step_key)->toBe(ProvisioningStep::WaitForOnline->value);

    Queue::assertPushed(RunProvisioningWorkflowStepJob::class, function (RunProvisioningWorkflowStepJob $job) {
        return $job->stepKey === ProvisioningStep::WaitForOnline->value;
    });
});

it('does not look up webhooks when a custom workflow omits wait_for_online', function () {
    Queue::fake();

    $device = provisionLicensedDevice($this->deployment, $this->client, [
        'serial' => 'SNNOWAIT1',
        'device_function' => 'ACCESS_SWITCH',
    ]);

    \App\Models\CentralWebhookEvent::query()->create([
        'client_id' => $this->client->id,
        'payload' => ['alert_type' => 'New Switch Connected'],
        'alert_type' => 'New Switch Connected',
        'serial' => 'SNNOWAIT1',
        'disposition' => 'accepted',
        'created_at' => now(),
    ]);

    $this->actingAs($this->user);

    $response = $this->post(route('deployments.provision.store', $this->deployment), [
        'device_ids' => [$device->id],
        'deployment_time' => 10,
        'wait_time' => 1,
        'steps' => [
            ProvisioningStep::AssociateSite->value,
            ProvisioningStep::ConfigureEthernetInterfaces->value,
        ],
        'name' => 'No wait online',
    ]);

    $task = Task::query()->where('task_type', 'CUSTOM_PROVISION')->latest('id')->first();
    $response->assertRedirect(route('tasks.show', $task));

    $workflowDevice = ProvisioningWorkflowDevice::query()->first();
    expect($workflowDevice->steps()->where('step_key', ProvisioningStep::WaitForOnline->value)->exists())->toBeFalse()
        ->and($workflowDevice->current_step_key)->toBe(ProvisioningStep::AssociateSite->value);
});

it('skips wait_for_online when query_central_for_online finds the device up', function () {
    Queue::fake();

    $this->client->update([
        'classic_base_url' => \App\ClassicBaseUrl::US_WEST4,
        'classic_client_id' => 'classic-client-id',
        'classic_client_secret' => 'classic-client-secret',
        'classic_username' => 'classic-user',
        'classic_password' => 'classic-password',
        'classic_refresh_token' => 'refresh-token',
        'classic_access_token' => 'access-token',
        'classic_expires_in' => now()->addHour(),
    ]);

    $device = provisionLicensedDevice($this->deployment, $this->client, [
        'serial' => 'SNAPUP1',
        'device_function' => \App\DeviceFunction::CAMPUS_AP->name,
    ]);

    Http::fake([
        '*monitoring/v2/aps*' => Http::response([
            'aps' => [
                ['serial' => 'SNAPUP1', 'status' => 'Up'],
            ],
        ], 200),
        '*monitoring/v1/switches*' => Http::response(['switches' => []], 200),
    ]);

    $this->actingAs($this->user);

    $this->post(route('deployments.provision.store', $this->deployment), [
        'device_ids' => [$device->id],
        'deployment_time' => 10,
        'wait_time' => 1,
        'start_step' => ProvisioningStep::WaitForOnline->value,
        'query_central_for_online' => true,
    ])->assertRedirect(route('deployments.provision', $this->deployment));

    $workflowDevice = ProvisioningWorkflowDevice::query()->first();
    $waitStep = $workflowDevice->steps()->where('step_key', ProvisioningStep::WaitForOnline->value)->first();

    expect($waitStep->status)->toBe('skipped')
        ->and($waitStep->message)->toBe('Already online in Central (Up).')
        ->and($workflowDevice->current_step_key)->toBe(ProvisioningStep::AssociateSite->value);
});

it('does not skip wait_for_online from central when query_central_for_online is off', function () {
    Queue::fake();

    $this->client->update([
        'classic_base_url' => \App\ClassicBaseUrl::US_WEST4,
        'classic_client_id' => 'classic-client-id',
        'classic_client_secret' => 'classic-client-secret',
        'classic_username' => 'classic-user',
        'classic_password' => 'classic-password',
        'classic_refresh_token' => 'refresh-token',
        'classic_access_token' => 'access-token',
        'classic_expires_in' => now()->addHour(),
    ]);

    $device = provisionLicensedDevice($this->deployment, $this->client, [
        'serial' => 'SNAPUP2',
        'device_function' => \App\DeviceFunction::CAMPUS_AP->name,
    ]);

    Http::fake([
        '*monitoring/v2/aps*' => Http::response([
            'aps' => [
                ['serial' => 'SNAPUP2', 'status' => 'Up'],
            ],
        ], 200),
        '*monitoring/v1/switches*' => Http::response(['switches' => []], 200),
    ]);

    $this->actingAs($this->user);

    $this->post(route('deployments.provision.store', $this->deployment), [
        'device_ids' => [$device->id],
        'deployment_time' => 10,
        'wait_time' => 1,
        'start_step' => ProvisioningStep::WaitForOnline->value,
        'query_central_for_online' => false,
    ])->assertRedirect(route('deployments.provision', $this->deployment));

    $workflowDevice = ProvisioningWorkflowDevice::query()->first();
    $waitStep = $workflowDevice->steps()->where('step_key', ProvisioningStep::WaitForOnline->value)->first();

    expect($waitStep->status)->toBe('in_progress')
        ->and($workflowDevice->current_step_key)->toBe(ProvisioningStep::WaitForOnline->value);

    Http::assertNothingSent();
});

it('still starts when query_central_for_online is on but central errors', function () {
    Queue::fake();

    $this->client->update([
        'classic_base_url' => \App\ClassicBaseUrl::US_WEST4,
        'classic_client_id' => 'classic-client-id',
        'classic_client_secret' => 'classic-client-secret',
        'classic_username' => 'classic-user',
        'classic_password' => 'classic-password',
        'classic_refresh_token' => 'refresh-token',
        'classic_access_token' => 'access-token',
        'classic_expires_in' => now()->addHour(),
    ]);

    $device = provisionLicensedDevice($this->deployment, $this->client, [
        'serial' => 'SNERR1',
        'device_function' => \App\DeviceFunction::CAMPUS_AP->name,
    ]);

    Http::fake([
        '*monitoring/v2/aps*' => Http::response(['error' => 'boom'], 500),
        '*monitoring/v1/switches*' => Http::response(['error' => 'boom'], 500),
    ]);

    $this->actingAs($this->user);

    $this->post(route('deployments.provision.store', $this->deployment), [
        'device_ids' => [$device->id],
        'deployment_time' => 10,
        'wait_time' => 1,
        'start_step' => ProvisioningStep::WaitForOnline->value,
        'query_central_for_online' => true,
    ])->assertRedirect(route('deployments.provision', $this->deployment));

    $workflowDevice = ProvisioningWorkflowDevice::query()->first();
    $waitStep = $workflowDevice->steps()->where('step_key', ProvisioningStep::WaitForOnline->value)->first();

    expect($waitStep->status)->toBe('in_progress')
        ->and($workflowDevice->current_step_key)->toBe(ProvisioningStep::WaitForOnline->value)
        ->and(ProvisioningWorkflow::query()->count())->toBe(1);
});

it('pauses a running workflow and halts further step dispatch', function () {
    Queue::fake();

    $device = Device::factory()->for($this->deployment)->create();
    $workflow = ProvisioningWorkflow::query()->create([
        'deployment_id' => $this->deployment->id,
        'user_id' => $this->user->id,
        'status' => 'running',
        'job_queue' => 'q0',
        'deployment_time' => 10,
        'wait_time' => 1,
        'steps' => [ProvisioningStep::AssociateSite->value],
        'classic_poller_active' => true,
    ]);

    $workflowDevice = ProvisioningWorkflowDevice::query()->create([
        'provisioning_workflow_id' => $workflow->id,
        'device_id' => $device->id,
        'overall_status' => 'in_progress',
        'current_step_key' => ProvisioningStep::AssociateSite->value,
        'status_message' => 'Associate to site...',
    ]);

    $workflowDevice->steps()->create([
        'step_key' => ProvisioningStep::AssociateSite->value,
        'step_order' => 1,
        'status' => 'in_progress',
    ]);

    $this->actingAs($this->user);

    $this->post(route('provisioning_workflows.pause', $workflow))
        ->assertRedirect()
        ->assertSessionHas('success');

    $workflow->refresh();
    expect($workflow->status)->toBe('paused')
        ->and($workflow->classic_poller_active)->toBeFalse();

    app(ProvisioningWorkflowOrchestrator::class)->dispatchStep(
        $workflowDevice->fresh(['workflow']),
        ProvisioningStep::AssociateSite,
    );

    Queue::assertNothingPushed();
});

it('resumes a cancelled custom workflow and skips completed steps', function () {
    Queue::fake();

    $device = Device::factory()->for($this->deployment)->create();
    $workflow = ProvisioningWorkflow::query()->create([
        'deployment_id' => $this->deployment->id,
        'user_id' => $this->user->id,
        'status' => 'cancelled',
        'job_queue' => 'q0',
        'deployment_time' => 10,
        'wait_time' => 1,
        'steps' => [
            ProvisioningStep::AssociateSite->value,
            ProvisioningStep::ConfigureVlanInterfaces->value,
        ],
        'completed_at' => now(),
    ]);

    $workflowDevice = ProvisioningWorkflowDevice::query()->create([
        'provisioning_workflow_id' => $workflow->id,
        'device_id' => $device->id,
        'overall_status' => 'in_progress',
        'current_step_key' => ProvisioningStep::ConfigureVlanInterfaces->value,
        'status_message' => 'Cancelled mid-run.',
    ]);

    $workflowDevice->steps()->create([
        'step_key' => ProvisioningStep::AssociateSite->value,
        'step_order' => 1,
        'status' => 'completed',
        'completed_at' => now(),
    ]);
    $workflowDevice->steps()->create([
        'step_key' => ProvisioningStep::ConfigureVlanInterfaces->value,
        'step_order' => 2,
        'status' => 'pending',
    ]);

    $this->actingAs($this->user);

    $this->post(route('provisioning_workflows.resume', $workflow))
        ->assertRedirect()
        ->assertSessionHas('success');

    $workflow->refresh();
    $workflowDevice->refresh();

    expect($workflow->status)->toBe('running')
        ->and($workflow->completed_at)->toBeNull()
        ->and($workflowDevice->overall_status)->toBe('in_progress')
        ->and($workflowDevice->steps()->where('step_key', ProvisioningStep::AssociateSite->value)->value('status'))->toBe('completed')
        ->and($workflowDevice->steps()->where('step_key', ProvisioningStep::ConfigureVlanInterfaces->value)->value('status'))->toBe('in_progress');

    Queue::assertPushed(RunProvisioningWorkflowStepJob::class, function (RunProvisioningWorkflowStepJob $job) use ($workflowDevice) {
        return $job->workflowDeviceId === $workflowDevice->id
            && $job->stepKey === ProvisioningStep::ConfigureVlanInterfaces->value;
    });
});

it('resumes a paused workflow', function () {
    Queue::fake();

    $device = Device::factory()->for($this->deployment)->create();
    $workflow = ProvisioningWorkflow::query()->create([
        'deployment_id' => $this->deployment->id,
        'user_id' => $this->user->id,
        'status' => 'paused',
        'job_queue' => 'q0',
        'deployment_time' => 10,
        'wait_time' => 1,
        'steps' => [ProvisioningStep::AssociateSite->value],
    ]);

    $workflowDevice = ProvisioningWorkflowDevice::query()->create([
        'provisioning_workflow_id' => $workflow->id,
        'device_id' => $device->id,
        'overall_status' => 'in_progress',
        'current_step_key' => ProvisioningStep::AssociateSite->value,
        'status_message' => 'Paused mid-run.',
    ]);

    $workflowDevice->steps()->create([
        'step_key' => ProvisioningStep::AssociateSite->value,
        'step_order' => 1,
        'status' => 'in_progress',
    ]);

    $this->actingAs($this->user);

    $this->post(route('provisioning_workflows.resume', $workflow))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($workflow->fresh()->status)->toBe('running');

    Queue::assertPushed(RunProvisioningWorkflowStepJob::class);
});

it('resumes a failed device workflow by clearing failed state and dispatching the incomplete step', function () {
    Queue::fake();

    $device = Device::factory()->for($this->deployment)->create();
    $workflow = ProvisioningWorkflow::query()->create([
        'deployment_id' => $this->deployment->id,
        'user_id' => $this->user->id,
        'status' => 'cancelled',
        'job_queue' => 'q0',
        'deployment_time' => 10,
        'wait_time' => 1,
        'steps' => [
            ProvisioningStep::AssociateSite->value,
            ProvisioningStep::ConfigureVlanInterfaces->value,
        ],
        'completed_at' => now(),
    ]);

    $workflowDevice = ProvisioningWorkflowDevice::query()->create([
        'provisioning_workflow_id' => $workflow->id,
        'device_id' => $device->id,
        'overall_status' => 'failed',
        'failed_step_key' => ProvisioningStep::ConfigureVlanInterfaces->value,
        'current_step_key' => ProvisioningStep::ConfigureVlanInterfaces->value,
        'status_message' => 'VLAN configuration failed.',
    ]);

    $workflowDevice->steps()->create([
        'step_key' => ProvisioningStep::AssociateSite->value,
        'step_order' => 1,
        'status' => 'completed',
        'completed_at' => now(),
    ]);
    $workflowDevice->steps()->create([
        'step_key' => ProvisioningStep::ConfigureVlanInterfaces->value,
        'step_order' => 2,
        'status' => 'failed',
    ]);

    $this->actingAs($this->user);

    $this->post(route('provisioning_workflows.resume', $workflow))
        ->assertRedirect()
        ->assertSessionHas('success');

    $workflowDevice->refresh();

    expect($workflowDevice->overall_status)->toBe('in_progress')
        ->and($workflowDevice->failed_step_key)->toBeNull();

    Queue::assertPushed(RunProvisioningWorkflowStepJob::class, function (RunProvisioningWorkflowStepJob $job) use ($workflowDevice) {
        return $job->workflowDeviceId === $workflowDevice->id
            && $job->stepKey === ProvisioningStep::ConfigureVlanInterfaces->value;
    });
});

it('does not resume a running workflow', function () {
    Queue::fake();

    $workflow = ProvisioningWorkflow::query()->create([
        'deployment_id' => $this->deployment->id,
        'user_id' => $this->user->id,
        'status' => 'running',
        'job_queue' => 'q0',
        'deployment_time' => 10,
        'wait_time' => 1,
    ]);

    $this->actingAs($this->user);

    $this->post(route('provisioning_workflows.resume', $workflow))
        ->assertRedirect()
        ->assertSessionHasErrors('workflow');

    Queue::assertNothingPushed();
});

it('serializes pause and resume flags for the UI', function () {
    $device = Device::factory()->for($this->deployment)->create();
    $workflow = ProvisioningWorkflow::query()->create([
        'deployment_id' => $this->deployment->id,
        'user_id' => $this->user->id,
        'status' => 'paused',
        'job_queue' => 'q0',
        'deployment_time' => 10,
        'wait_time' => 1,
        'steps' => [ProvisioningStep::AssociateSite->value],
    ]);

    $workflow->workflowDevices()->create([
        'device_id' => $device->id,
        'overall_status' => 'in_progress',
        'current_step_key' => ProvisioningStep::AssociateSite->value,
    ]);

    $payload = app(ProvisioningWorkflowService::class)->serializeForUi($workflow);

    expect($payload['can_pause'])->toBeFalse()
        ->and($payload['can_resume'])->toBeTrue()
        ->and($payload['status'])->toBe('paused');
});

it('creates a linked custom provision task when starting a custom workflow', function () {
    Queue::fake();
    $device = provisionLicensedDevice($this->deployment, $this->client);
    $this->actingAs($this->user);

    $this->post(route('deployments.provision.store', $this->deployment), [
        'device_ids' => [$device->id],
        'deployment_time' => 10,
        'wait_time' => 1,
        'steps' => [ProvisioningStep::AssociateSite->value],
        'name' => 'Run alpha',
    ])->assertRedirect();

    $workflow = ProvisioningWorkflow::query()->first();
    $task = Task::query()->where('provisioning_workflow_id', $workflow->id)->first();

    expect($task)->not->toBeNull()
        ->and($task->task_type)->toBe('CUSTOM_PROVISION')
        ->and($task->status)->toBe('IN_PROGRESS')
        ->and($task->deployment_id)->toBe($this->deployment->id)
        ->and($task->devices()->pluck('devices.id')->all())->toBe([$device->id])
        ->and($task->devices()->first()->pivot->status)->toBe('IN_PROGRESS');
});

it('lists custom provision tasks with display names on the tasks index', function () {
    Queue::fake();
    $device = provisionLicensedDevice($this->deployment, $this->client);
    $this->actingAs($this->user);

    $this->post(route('deployments.provision.store', $this->deployment), [
        'device_ids' => [$device->id],
        'deployment_time' => 10,
        'wait_time' => 1,
        'steps' => [ProvisioningStep::AssociateSite->value],
        'name' => 'Named run',
    ]);

    $this->get(route('tasks.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Task/Index')
            ->where('tasks.data.0.task_name', 'Custom Task. — Named run')
        );
});

it('renders the custom provision task detail page with workflow data', function () {
    Queue::fake();
    $site = \App\Models\Site::factory()->for($this->client)->create(['name' => 'HQ Site']);
    $device = provisionLicensedDevice($this->deployment, $this->client, [
        'mac_address' => 'aa:bb:cc:dd:ee:ff',
        'site_id' => $site->id,
        'device_function' => 'CAMPUS_AP',
        'group' => 'Floor-1',
    ]);
    $this->actingAs($this->user);

    $this->post(route('deployments.provision.store', $this->deployment), [
        'device_ids' => [$device->id],
        'deployment_time' => 10,
        'wait_time' => 1,
        'steps' => [ProvisioningStep::AssociateSite->value],
        'name' => 'Detail run',
    ]);

    $task = Task::query()->where('task_type', 'CUSTOM_PROVISION')->latest('id')->first();

    $this->get(route('tasks.show', $task))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Task/CustomProvisionTask')
            ->where('task.id', $task->id)
            ->where('deployment.id', $this->deployment->id)
            ->where('workflow.name', 'Detail run')
            ->has('workflow.devices', 1)
            ->where('workflow.devices.0.device_function', 'CAMPUS_AP')
            ->where('workflow.devices.0.mac_address', 'aa:bb:cc:dd:ee:ff')
            ->where('workflow.devices.0.site_name', 'HQ Site')
            ->where('workflow.devices.0.group', 'Floor-1')
        );
});

it('syncs linked task status when a custom workflow is cancelled', function () {
    Queue::fake();
    $device = Device::factory()->for($this->deployment)->create();
    $workflow = ProvisioningWorkflow::query()->create([
        'deployment_id' => $this->deployment->id,
        'user_id' => $this->user->id,
        'name' => 'Cancel me',
        'status' => 'running',
        'job_queue' => 'q0',
        'deployment_time' => 10,
        'wait_time' => 1,
        'steps' => [ProvisioningStep::AssociateSite->value],
    ]);

    $workflowDevice = $workflow->workflowDevices()->create([
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

    $this->actingAs($this->user);
    $this->post(route('provisioning_workflows.cancel', $workflow))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($task->fresh()->status)->toBe('CANCELLED');
});

it('syncs linked task status when a custom workflow is paused', function () {
    Queue::fake();
    $device = Device::factory()->for($this->deployment)->create();
    $workflow = ProvisioningWorkflow::query()->create([
        'deployment_id' => $this->deployment->id,
        'user_id' => $this->user->id,
        'status' => 'running',
        'job_queue' => 'q0',
        'deployment_time' => 10,
        'wait_time' => 1,
        'steps' => [ProvisioningStep::AssociateSite->value],
        'classic_poller_active' => true,
    ]);

    $workflowDevice = $workflow->workflowDevices()->create([
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

    $this->actingAs($this->user);
    $this->post(route('provisioning_workflows.pause', $workflow))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($task->fresh()->status)->toBe('IN_PROGRESS');
});

it('syncs device pivot status from workflow device progress', function () {
    $device = Device::factory()->for($this->deployment)->create();
    $workflow = ProvisioningWorkflow::query()->create([
        'deployment_id' => $this->deployment->id,
        'user_id' => $this->user->id,
        'status' => 'running',
        'job_queue' => 'q0',
        'deployment_time' => 10,
        'wait_time' => 1,
        'steps' => [ProvisioningStep::AssociateSite->value],
    ]);

    $workflowDevice = $workflow->workflowDevices()->create([
        'device_id' => $device->id,
        'overall_status' => 'completed',
        'current_step_key' => null,
        'status_message' => 'Done',
    ]);

    $workflowDevice->steps()->create([
        'step_key' => ProvisioningStep::AssociateSite->value,
        'step_order' => 1,
        'status' => 'completed',
    ]);

    $task = app(ProvisioningWorkflowTaskSync::class)->createForWorkflow(
        $workflow->fresh(['workflowDevices']),
        $this->deployment,
    );

    expect($task->devices()->first()->pivot->status)->toBe('COMPLETED');
});

it('overrides an in-progress step as completed and advances to the next step', function () {
    Queue::fake();

    $device = Device::factory()->for($this->deployment)->create([
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
            ProvisioningStep::NameDevice->value,
        ],
    ]);

    $workflowDevice = ProvisioningWorkflowDevice::query()->create([
        'provisioning_workflow_id' => $workflow->id,
        'device_id' => $device->id,
        'overall_status' => 'in_progress',
        'current_step_key' => ProvisioningStep::AssociateSite->value,
        'status_message' => 'Failed to associate device to site. Retrying...',
    ]);

    $workflowDevice->steps()->create([
        'step_key' => ProvisioningStep::AssociateSite->value,
        'step_order' => 1,
        'status' => 'in_progress',
        'message' => 'Failed to associate device to site. Retrying...',
        'attempts' => 3,
    ]);
    $workflowDevice->steps()->create([
        'step_key' => ProvisioningStep::NameDevice->value,
        'step_order' => 2,
        'status' => 'pending',
    ]);

    $this->actingAs($this->user);

    $this->post(route('provisioning_workflow_devices.override', $workflowDevice), [
        'step_key' => ProvisioningStep::AssociateSite->value,
    ])->assertRedirect();

    $workflowDevice->refresh();
    $associateStep = $workflowDevice->steps()->where('step_key', ProvisioningStep::AssociateSite->value)->first();
    $nameStep = $workflowDevice->steps()->where('step_key', ProvisioningStep::NameDevice->value)->first();

    expect($associateStep->status)->toBe('completed')
        ->and($associateStep->user_overridden)->toBeTrue()
        ->and($associateStep->message)->toBe('Marked complete by user.')
        ->and($workflowDevice->overall_status)->toBe('in_progress')
        ->and($workflowDevice->current_step_key)->toBe(ProvisioningStep::NameDevice->value)
        ->and($nameStep->status)->toBe('in_progress');

    Queue::assertPushed(RunProvisioningWorkflowStepJob::class, function (RunProvisioningWorkflowStepJob $job) use ($workflowDevice) {
        return $job->workflowDeviceId === $workflowDevice->id
            && $job->stepKey === ProvisioningStep::NameDevice->value;
    });
});

it('overrides a failed step and resumes the device workflow', function () {
    Queue::fake();

    $device = Device::factory()->for($this->deployment)->create([
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
            ProvisioningStep::NameDevice->value,
        ],
    ]);

    $workflowDevice = ProvisioningWorkflowDevice::query()->create([
        'provisioning_workflow_id' => $workflow->id,
        'device_id' => $device->id,
        'overall_status' => 'failed',
        'failed_step_key' => ProvisioningStep::AssociateSite->value,
        'current_step_key' => ProvisioningStep::AssociateSite->value,
        'status_message' => 'Site not found',
    ]);

    $workflowDevice->steps()->create([
        'step_key' => ProvisioningStep::AssociateSite->value,
        'step_order' => 1,
        'status' => 'failed',
        'message' => 'Site not found',
    ]);
    $workflowDevice->steps()->create([
        'step_key' => ProvisioningStep::NameDevice->value,
        'step_order' => 2,
        'status' => 'pending',
    ]);

    $this->actingAs($this->user);

    $this->post(route('provisioning_workflow_devices.override', $workflowDevice), [
        'step_key' => ProvisioningStep::AssociateSite->value,
    ])->assertRedirect();

    $workflowDevice->refresh();

    expect($workflowDevice->overall_status)->toBe('in_progress')
        ->and($workflowDevice->failed_step_key)->toBeNull()
        ->and($workflowDevice->current_step_key)->toBe(ProvisioningStep::NameDevice->value)
        ->and($workflowDevice->steps()->where('step_key', ProvisioningStep::AssociateSite->value)->value('status'))->toBe('completed')
        ->and((bool) $workflowDevice->steps()->where('step_key', ProvisioningStep::AssociateSite->value)->value('user_overridden'))->toBeTrue();

    Queue::assertPushed(RunProvisioningWorkflowStepJob::class, function (RunProvisioningWorkflowStepJob $job) use ($workflowDevice) {
        return $job->workflowDeviceId === $workflowDevice->id
            && $job->stepKey === ProvisioningStep::NameDevice->value;
    });
});

it('completes the device when the overridden step is the last step', function () {
    Queue::fake();

    $device = Device::factory()->for($this->deployment)->create([
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

    $this->actingAs($this->user);

    $this->post(route('provisioning_workflow_devices.override', $workflowDevice), [
        'step_key' => ProvisioningStep::AssociateSite->value,
    ])->assertRedirect();

    $workflowDevice->refresh();

    expect($workflowDevice->overall_status)->toBe('completed')
        ->and($workflowDevice->current_step_key)->toBeNull()
        ->and($workflowDevice->status_message)->toBe('Workflow completed successfully.')
        ->and((bool) $workflowDevice->steps()->where('step_key', ProvisioningStep::AssociateSite->value)->value('user_overridden'))->toBeTrue();

    Queue::assertNotPushed(RunProvisioningWorkflowStepJob::class);
});

it('does not re-advance when a released step job runs after a user override', function () {
    Queue::fake();

    $device = Device::factory()->for($this->deployment)->create([
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
            ProvisioningStep::NameDevice->value,
        ],
    ]);

    $workflowDevice = ProvisioningWorkflowDevice::query()->create([
        'provisioning_workflow_id' => $workflow->id,
        'device_id' => $device->id,
        'overall_status' => 'in_progress',
        'current_step_key' => ProvisioningStep::NameDevice->value,
        'status_message' => 'Name device...',
    ]);

    $workflowDevice->steps()->create([
        'step_key' => ProvisioningStep::AssociateSite->value,
        'step_order' => 1,
        'status' => 'completed',
        'user_overridden' => true,
        'message' => 'Marked complete by user.',
        'completed_at' => now(),
    ]);
    $workflowDevice->steps()->create([
        'step_key' => ProvisioningStep::NameDevice->value,
        'step_order' => 2,
        'status' => 'in_progress',
        'message' => 'Name device...',
    ]);

    $job = new RunProvisioningWorkflowStepJob($workflowDevice->id, ProvisioningStep::AssociateSite->value);
    $job->handle(
        app(\App\Services\Provisioning\ProvisioningStepRunner::class),
        app(ProvisioningWorkflowOrchestrator::class),
    );

    $workflowDevice->refresh();
    $nameStep = $workflowDevice->steps()->where('step_key', ProvisioningStep::NameDevice->value)->first();

    expect($workflowDevice->current_step_key)->toBe(ProvisioningStep::NameDevice->value)
        ->and($nameStep->status)->toBe('in_progress')
        ->and((bool) $workflowDevice->steps()->where('step_key', ProvisioningStep::AssociateSite->value)->value('user_overridden'))->toBeTrue();

    Queue::assertNotPushed(RunProvisioningWorkflowStepJob::class);
});

it('ignores in-flight step results after a user override', function () {
    $device = Device::factory()->for($this->deployment)->create([
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
            ProvisioningStep::NameDevice->value,
        ],
    ]);

    $workflowDevice = ProvisioningWorkflowDevice::query()->create([
        'provisioning_workflow_id' => $workflow->id,
        'device_id' => $device->id,
        'overall_status' => 'in_progress',
        'current_step_key' => ProvisioningStep::NameDevice->value,
    ]);

    $workflowDevice->steps()->create([
        'step_key' => ProvisioningStep::AssociateSite->value,
        'step_order' => 1,
        'status' => 'completed',
        'user_overridden' => true,
        'message' => 'Marked complete by user.',
        'completed_at' => now(),
    ]);
    $workflowDevice->steps()->create([
        'step_key' => ProvisioningStep::NameDevice->value,
        'step_order' => 2,
        'status' => 'in_progress',
    ]);

    app(ProvisioningWorkflowOrchestrator::class)->processStepResult(
        $workflowDevice->fresh(['steps', 'workflow', 'device']),
        ProvisioningStep::AssociateSite,
        ProvisioningStepResult::failed('Late failure from in-flight API call'),
    );

    $associateStep = $workflowDevice->steps()->where('step_key', ProvisioningStep::AssociateSite->value)->first();

    expect($associateStep->status)->toBe('completed')
        ->and($associateStep->user_overridden)->toBeTrue()
        ->and($workflowDevice->fresh()->overall_status)->toBe('in_progress');
});

it('clears user_overridden when restarting from an overridden step', function () {
    Queue::fake();

    $device = Device::factory()->for($this->deployment)->create([
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
            ProvisioningStep::NameDevice->value,
        ],
    ]);

    $workflowDevice = ProvisioningWorkflowDevice::query()->create([
        'provisioning_workflow_id' => $workflow->id,
        'device_id' => $device->id,
        'overall_status' => 'failed',
        'failed_step_key' => ProvisioningStep::NameDevice->value,
        'current_step_key' => ProvisioningStep::NameDevice->value,
    ]);

    $workflowDevice->steps()->create([
        'step_key' => ProvisioningStep::AssociateSite->value,
        'step_order' => 1,
        'status' => 'completed',
        'user_overridden' => true,
        'message' => 'Marked complete by user.',
        'completed_at' => now(),
    ]);
    $workflowDevice->steps()->create([
        'step_key' => ProvisioningStep::NameDevice->value,
        'step_order' => 2,
        'status' => 'failed',
        'message' => 'Naming failed',
    ]);

    $this->actingAs($this->user);

    $this->post(route('provisioning_workflow_devices.restart', $workflowDevice), [
        'from_step' => ProvisioningStep::AssociateSite->value,
    ])->assertRedirect();

    $associateStep = $workflowDevice->steps()->where('step_key', ProvisioningStep::AssociateSite->value)->first();

    expect($associateStep->status)->toBe('in_progress')
        ->and($associateStep->user_overridden)->toBeFalse()
        ->and($associateStep->message)->toBe('Restarting...');
});

it('rejects overriding a non-current step', function () {
    $device = Device::factory()->for($this->deployment)->create([
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
            ProvisioningStep::NameDevice->value,
        ],
    ]);

    $workflowDevice = ProvisioningWorkflowDevice::query()->create([
        'provisioning_workflow_id' => $workflow->id,
        'device_id' => $device->id,
        'overall_status' => 'in_progress',
        'current_step_key' => ProvisioningStep::NameDevice->value,
    ]);

    $workflowDevice->steps()->create([
        'step_key' => ProvisioningStep::AssociateSite->value,
        'step_order' => 1,
        'status' => 'completed',
    ]);
    $workflowDevice->steps()->create([
        'step_key' => ProvisioningStep::NameDevice->value,
        'step_order' => 2,
        'status' => 'in_progress',
    ]);

    $this->actingAs($this->user);

    $this->from(route('deployments.provision', $this->deployment))
        ->post(route('provisioning_workflow_devices.override', $workflowDevice), [
            'step_key' => ProvisioningStep::AssociateSite->value,
        ])
        ->assertRedirect(route('deployments.provision', $this->deployment))
        ->assertSessionHasErrors('step_key');
});

it('rejects overriding a step on a cancelled workflow', function () {
    $device = Device::factory()->for($this->deployment)->create([
        'device_function' => 'ACCESS_SWITCH',
    ]);
    $workflow = ProvisioningWorkflow::query()->create([
        'deployment_id' => $this->deployment->id,
        'user_id' => $this->user->id,
        'status' => 'cancelled',
        'job_queue' => 'q0',
        'deployment_time' => 10,
        'wait_time' => 1,
        'steps' => [ProvisioningStep::AssociateSite->value],
        'completed_at' => now(),
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

    $this->actingAs($this->user);

    $this->from(route('deployments.provision', $this->deployment))
        ->post(route('provisioning_workflow_devices.override', $workflowDevice), [
            'step_key' => ProvisioningStep::AssociateSite->value,
        ])
        ->assertRedirect(route('deployments.provision', $this->deployment))
        ->assertSessionHasErrors('step_key');
});

it('serializes user_overridden and can_override flags for the UI', function () {
    $device = Device::factory()->for($this->deployment)->create([
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
            ProvisioningStep::NameDevice->value,
        ],
    ]);

    $workflowDevice = ProvisioningWorkflowDevice::query()->create([
        'provisioning_workflow_id' => $workflow->id,
        'device_id' => $device->id,
        'overall_status' => 'in_progress',
        'current_step_key' => ProvisioningStep::NameDevice->value,
    ]);

    $workflowDevice->steps()->create([
        'step_key' => ProvisioningStep::AssociateSite->value,
        'step_order' => 1,
        'status' => 'completed',
        'user_overridden' => true,
        'message' => 'Marked complete by user.',
    ]);
    $workflowDevice->steps()->create([
        'step_key' => ProvisioningStep::NameDevice->value,
        'step_order' => 2,
        'status' => 'in_progress',
    ]);

    $payload = app(ProvisioningWorkflowService::class)->serializeForUi($workflow->fresh());
    $steps = collect($payload['devices'][0]['steps']);

    expect($steps->firstWhere('step_key', ProvisioningStep::AssociateSite->value)['user_overridden'])->toBeTrue()
        ->and($steps->firstWhere('step_key', ProvisioningStep::AssociateSite->value)['can_override'])->toBeFalse()
        ->and($steps->firstWhere('step_key', ProvisioningStep::NameDevice->value)['user_overridden'])->toBeFalse()
        ->and($steps->firstWhere('step_key', ProvisioningStep::NameDevice->value)['can_override'])->toBeTrue();
});
