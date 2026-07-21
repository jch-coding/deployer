<?php

use App\ClassicBaseUrl;
use App\DeviceFunction;
use App\Enums\OnlineDetectionMode;
use App\Enums\ProvisioningStep;
use App\Jobs\FailWaitForOnlineOnTimeoutJob;
use App\Jobs\HandleCentralDeviceOnlineWakeJob;
use App\Jobs\PollClassicDeviceOnlineJob;
use App\Jobs\RunProvisioningWorkflowStepJob;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\ProvisioningWorkflow;
use App\Models\ProvisioningWorkflowDevice;
use App\Models\User;
use App\Services\Central\ClassicMonitoringStreamManager;
use App\Services\Provisioning\MarkDeviceOnlineIfWaiting;
use App\Services\Provisioning\ProvisioningStepResult;
use App\Services\Provisioning\ProvisioningWorkflowOrchestrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function createStreamWaitingWorkflow(array $deviceAttrs = [], array $workflowAttrs = []): array
{
    $user = User::factory()->has(Client::factory())->create();
    $client = $user->clients()->first();
    $client->update([
        'classic_base_url' => ClassicBaseUrl::US_WEST4,
        'classic_client_id' => 'classic-client-id',
        'classic_client_secret' => 'classic-client-secret',
        'classic_username' => 'classic-user',
        'classic_password' => 'classic-password',
        'classic_refresh_token' => 'refresh-token',
        'classic_access_token' => 'access-token',
        'classic_expires_in' => now()->addHour(),
        'classic_streaming_hostname' => 'internal-ui.central.arubanetworks.com',
        'classic_streaming_username' => 'ops@example.com',
        'classic_streaming_key' => 'streaming-key',
    ]);

    $deployment = Deployment::factory()->for($client)->create();
    $device = Device::factory()->for($deployment)->create(array_merge([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'serial' => 'SNSTREAM1',
        'device_function' => DeviceFunction::CAMPUS_AP->name,
    ], $deviceAttrs));

    $workflow = ProvisioningWorkflow::query()->create(array_merge([
        'deployment_id' => $deployment->id,
        'user_id' => $user->id,
        'status' => 'running',
        'job_queue' => 'q0',
        'deployment_time' => 10,
        'wait_time' => 1,
        'online_detection_mode' => OnlineDetectionMode::Stream,
        'classic_poller_active' => false,
    ], $workflowAttrs));

    $workflowDevice = ProvisioningWorkflowDevice::query()->create([
        'provisioning_workflow_id' => $workflow->id,
        'device_id' => $device->id,
        'overall_status' => 'in_progress',
        'current_step_key' => ProvisioningStep::WaitForOnline->value,
        'status_message' => 'Waiting via streaming',
    ]);

    foreach (ProvisioningStep::ordered() as $step) {
        $status = 'pending';
        if ($step->order() < ProvisioningStep::WaitForOnline->order()) {
            $status = 'completed';
        } elseif ($step === ProvisioningStep::WaitForOnline) {
            $status = 'in_progress';
        } elseif ($step->shouldSkipForDevice($device)) {
            $status = 'skipped';
        }

        $workflowDevice->steps()->create([
            'step_key' => $step->value,
            'step_order' => $step->order(),
            'status' => $status,
        ]);
    }

    return compact('user', 'client', 'deployment', 'device', 'workflow', 'workflowDevice');
}

function encodeStreamVarint(int $value): string
{
    $bytes = '';
    while ($value > 0x7F) {
        $bytes .= chr(($value & 0x7F) | 0x80);
        $value >>= 7;
    }

    return $bytes.chr($value & 0x7F);
}

function encodeStreamTag(int $fieldNumber, int $wireType): string
{
    return encodeStreamVarint(($fieldNumber << 3) | $wireType);
}

function encodeStreamString(int $fieldNumber, string $value): string
{
    return encodeStreamTag($fieldNumber, 2).encodeStreamVarint(strlen($value)).$value;
}

function encodeStreamVarintField(int $fieldNumber, int $value): string
{
    return encodeStreamTag($fieldNumber, 0).encodeStreamVarint($value);
}

function encodeStreamMessage(int $fieldNumber, string $message): string
{
    return encodeStreamTag($fieldNumber, 2).encodeStreamVarint(strlen($message)).$message;
}

function buildStreamUpFrame(string $serial): string
{
    $device = encodeStreamString(2, $serial).encodeStreamVarintField(6, 1);
    $monitoring = encodeStreamMessage(4, $device);

    return encodeStreamMessage(3, $monitoring);
}

it('persists stream online detection mode when streaming credentials exist', function () {
    Queue::fake();
    $user = User::factory()->has(Client::factory()->state(['current' => true]))->create();
    $client = $user->clients()->first();
    $client->update([
        'classic_streaming_hostname' => 'internal-ui.central.arubanetworks.com',
        'classic_streaming_username' => 'ops@example.com',
        'classic_streaming_key' => 'streaming-key',
        'licensing_synced_at' => now(),
        'licensing_enabled_services' => ['ADVANCED_AP'],
    ]);
    $deployment = Deployment::factory()->for($client)->create();
    $device = Device::factory()->for($deployment)->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'license_tag' => 'pool-a',
        'license_type' => 'Advanced AP',
        'group' => 'TestGroup',
    ]);

    \App\Models\ClientSubscription::factory()->for($client)->create([
        'subscription_key' => 'sub-key',
        'greenlake_subscription_id' => 'gl-sub-1',
        'tags' => ['pool-a'],
        'license_type' => 'Advanced AP',
        'available' => 5,
    ]);

    \App\Models\LicensingInventoryDevice::factory()->for($client)->create([
        'serial' => $device->serial,
        'greenlake_device_id' => 'gl-dev-1',
        'licensed' => true,
        'subscription_key' => 'sub-key',
    ]);

    $this->actingAs($user);
    $this->post(route('deployments.provision.store', $deployment), [
        'device_ids' => [$device->id],
        'deployment_time' => 10,
        'wait_time' => 1,
        'online_detection_mode' => 'stream',
    ])->assertRedirect(route('deployments.provision', $deployment));

    expect(ProvisioningWorkflow::query()->first()->online_detection_mode)->toBe(OnlineDetectionMode::Stream);
});

it('rejects stream online detection mode without streaming credentials', function () {
    $user = User::factory()->has(Client::factory()->state(['current' => true]))->create();
    $client = $user->clients()->first();
    $deployment = Deployment::factory()->for($client)->create();
    $device = Device::factory()->for($deployment)->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
    ]);

    $this->actingAs($user);
    $this->post(route('deployments.provision.store', $deployment), [
        'device_ids' => [$device->id],
        'deployment_time' => 10,
        'wait_time' => 1,
        'online_detection_mode' => 'stream',
    ])->assertSessionHasErrors('online_detection_mode');

    expect(ProvisioningWorkflow::query()->count())->toBe(0);
});

it('does not start the classic poller for stream-mode wait_for_online', function () {
    Queue::fake();
    $ctx = createStreamWaitingWorkflow();

    app(ProvisioningWorkflowOrchestrator::class)->processStepResult(
        $ctx['workflowDevice']->fresh(['steps', 'workflow', 'device']),
        ProvisioningStep::WaitForOnline,
        ProvisioningStepResult::waitingPeer('Waiting for device to come online via streaming (status: Down).'),
    );

    expect($ctx['workflow']->fresh()->classic_poller_active)->toBeFalse();
    Queue::assertNotPushed(PollClassicDeviceOnlineJob::class);
    Queue::assertPushed(FailWaitForOnlineOnTimeoutJob::class, function (FailWaitForOnlineOnTimeoutJob $job) use ($ctx) {
        return $job->workflowDeviceId === $ctx['workflowDevice']->id;
    });
});

it('advances stream-mode wait_for_online from a stream wake', function () {
    Queue::fake([RunProvisioningWorkflowStepJob::class]);
    $ctx = createStreamWaitingWorkflow();

    Http::fake([
        '*monitoring/v2/aps*' => Http::response([
            'aps' => [
                ['serial' => $ctx['device']->serial, 'status' => 'Up'],
            ],
        ], 200),
        '*monitoring/v1/switches*' => Http::response(['switches' => []], 200),
    ]);

    (new HandleCentralDeviceOnlineWakeJob($ctx['client']->id, $ctx['device']->serial, 'stream'))->handle(
        app(MarkDeviceOnlineIfWaiting::class),
    );

    $step = $ctx['workflowDevice']->steps()->where('step_key', ProvisioningStep::WaitForOnline->value)->first();

    expect($step->fresh()->status)->toBe('completed');
});

it('does not advance webhook-mode wait_for_online from a stream wake', function () {
    Queue::fake([RunProvisioningWorkflowStepJob::class]);
    $ctx = createStreamWaitingWorkflow([], [
        'online_detection_mode' => OnlineDetectionMode::Webhook,
    ]);

    Http::fake([
        '*monitoring/v2/aps*' => Http::response([
            'aps' => [
                ['serial' => $ctx['device']->serial, 'status' => 'Up'],
            ],
        ], 200),
    ]);

    (new HandleCentralDeviceOnlineWakeJob($ctx['client']->id, $ctx['device']->serial, 'stream'))->handle(
        app(MarkDeviceOnlineIfWaiting::class),
    );

    $step = $ctx['workflowDevice']->steps()->where('step_key', ProvisioningStep::WaitForOnline->value)->first();

    expect($step->fresh()->status)->toBe('in_progress');
});

it('dispatches a stream wake job from a monitoring up frame', function () {
    Queue::fake();
    Cache::flush();
    $ctx = createStreamWaitingWorkflow();

    app(ClassicMonitoringStreamManager::class)->handleFrame(
        $ctx['client']->id,
        buildStreamUpFrame($ctx['device']->serial),
    );

    Queue::assertPushed(HandleCentralDeviceOnlineWakeJob::class, function (HandleCentralDeviceOnlineWakeJob $job) use ($ctx) {
        return $job->clientId === $ctx['client']->id
            && $job->serial === $ctx['device']->serial
            && $job->mode === 'stream';
    });

    $event = \App\Models\CentralStreamEvent::query()->first();
    expect($event)->not->toBeNull()
        ->and($event->client_id)->toBe($ctx['client']->id)
        ->and($event->decoded['aps'][0]['serial'])->toBe($ctx['device']->serial)
        ->and($event->decoded['aps'][0]['status'])->toBe(1);
});

it('shows stream events for the current client on the websocket index page', function () {
    $this->withoutVite();
    $ctx = createStreamWaitingWorkflow();
    $ctx['client']->update(['current' => true]);
    \App\Models\CentralStreamEvent::query()->create([
        'client_id' => $ctx['client']->id,
        'subject' => 'monitoring',
        'customer_id' => 'cust-1',
        'timestamp' => 123,
        'decoded' => [
            'aps' => [['serial' => $ctx['device']->serial, 'status' => 1]],
            'switches' => [],
            'data_elements' => [],
        ],
        'created_at' => now(),
    ]);

    $otherClient = Client::factory()->for($ctx['user'])->create();
    \App\Models\CentralStreamEvent::query()->create([
        'client_id' => $otherClient->id,
        'subject' => 'other',
        'customer_id' => 'cust-2',
        'timestamp' => 456,
        'decoded' => ['aps' => [], 'switches' => [], 'data_elements' => []],
        'created_at' => now(),
    ]);

    $this->actingAs($ctx['user'])
        ->get(route('streaming.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('WebSocket/Index')
            ->has('events.data', 1)
            ->where('events.data.0.customer_id', 'cust-1'));
});

it('redirects streaming index when no current client is set', function () {
    $user = User::factory()->has(Client::factory()->state(['current' => false]))->create();

    $this->actingAs($user)
        ->get(route('streaming.index'))
        ->assertRedirect(route('clients.index'));
});

it('dedupes stream up frames for the same serial within ttl', function () {
    Queue::fake();
    Cache::flush();
    $ctx = createStreamWaitingWorkflow();
    $manager = app(ClassicMonitoringStreamManager::class);
    $frame = buildStreamUpFrame($ctx['device']->serial);

    $manager->handleFrame($ctx['client']->id, $frame);
    $manager->handleFrame($ctx['client']->id, $frame);

    Queue::assertPushed(HandleCentralDeviceOnlineWakeJob::class, 1);
});

it('saves classic streaming credentials on the client', function () {
    $user = User::factory()->has(Client::factory()->state(['current' => true]))->create();
    $client = $user->clients()->first();
    $this->actingAs($user);

    $this->put(route('clients.edit', $client), [
        'classic_streaming_hostname' => 'internal-ui.central.arubanetworks.com',
        'classic_streaming_username' => 'ops@example.com',
        'classic_streaming_key' => 'stream-secret',
    ])->assertRedirect(route('clients.index'));

    $client->refresh();
    expect($client->classic_streaming_hostname)->toBe('internal-ui.central.arubanetworks.com')
        ->and($client->classic_streaming_username)->toBe('ops@example.com')
        ->and($client->classic_streaming_key)->toBe('stream-secret')
        ->and($client->hasClassicStreamingCredentials())->toBeTrue();
});
