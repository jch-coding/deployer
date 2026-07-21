<?php

use App\ClassicBaseUrl;
use App\DeviceFunction;
use App\Enums\OnlineDetectionMode;
use App\Enums\ProvisioningStep;
use App\Jobs\FailWaitForOnlineOnTimeoutJob;
use App\Jobs\HandleCentralDeviceOnlineWakeJob;
use App\Jobs\RunProvisioningWorkflowStepJob;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\ProvisioningWorkflow;
use App\Models\ProvisioningWorkflowDevice;
use App\Models\User;
use App\Services\Provisioning\MarkDeviceOnlineIfWaiting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function createWaitingWorkflowDevice(array $deviceAttrs = [], array $workflowAttrs = []): array
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
        'classic_webhook_secret' => 'webhook-secret-token',
    ]);

    $deployment = Deployment::factory()->for($client)->create();
    $device = Device::factory()->for($deployment)->create(array_merge([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'serial' => 'SNWAITONLINE1',
        'device_function' => DeviceFunction::CAMPUS_AP->name,
    ], $deviceAttrs));

    $workflow = ProvisioningWorkflow::query()->create(array_merge([
        'deployment_id' => $deployment->id,
        'user_id' => $user->id,
        'status' => 'running',
        'job_queue' => 'q0',
        'deployment_time' => 10,
        'wait_time' => 1,
        'online_detection_mode' => OnlineDetectionMode::Webhook,
        'classic_poller_active' => true,
    ], $workflowAttrs));

    $workflowDevice = ProvisioningWorkflowDevice::query()->create([
        'provisioning_workflow_id' => $workflow->id,
        'device_id' => $device->id,
        'overall_status' => 'in_progress',
        'current_step_key' => ProvisioningStep::WaitForOnline->value,
        'status_message' => 'Waiting for device to come online',
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

function signedCentralWebhookHeaders(string $body, string $secret): array
{
    $service = 'Alerts';
    $deliveryId = '72d3162e-cc78-11e3-81ab-4c9367dc0958';
    $timestamp = '2016-07-12T13:14:19-07:00';
    $signature = base64_encode(hash_hmac('sha256', $body.$service.$deliveryId.$timestamp, $secret, true));

    return [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_CENTRAL_SERVICE' => $service,
        'HTTP_X_CENTRAL_DELIVERY_ID' => $deliveryId,
        'HTTP_X_CENTRAL_DELIVERY_TIMESTAMP' => $timestamp,
        'HTTP_X_CENTRAL_SIGNATURE' => $signature,
    ];
}

it('rejects webhook requests with invalid hmac', function () {
    $ctx = createWaitingWorkflowDevice();

    $this->call(
        'POST',
        route('webhooks.central', $ctx['client']),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CENTRAL_SIGNATURE' => 'invalid',
        ],
        '{"alert_type":"New AP detected"}',
    )->assertUnauthorized();
});

it('dispatches a wake job for new ap detected alerts', function () {
    Queue::fake();
    $ctx = createWaitingWorkflowDevice();
    $body = json_encode([
        'alert_type' => 'New AP detected',
        'state' => 'Open',
        'device_id' => $ctx['device']->serial,
        'details' => ['serial' => $ctx['device']->serial],
    ], JSON_THROW_ON_ERROR);

    $this->call(
        'POST',
        route('webhooks.central', $ctx['client']),
        [],
        [],
        [],
        signedCentralWebhookHeaders($body, 'webhook-secret-token'),
        $body,
    )->assertOk();

    Queue::assertPushed(HandleCentralDeviceOnlineWakeJob::class, function (HandleCentralDeviceOnlineWakeJob $job) use ($ctx) {
        return $job->clientId === $ctx['client']->id
            && $job->serial === $ctx['device']->serial;
    });
});

it('ignores disconnect alerts without dispatching a wake job', function () {
    Queue::fake();
    $ctx = createWaitingWorkflowDevice();
    $body = json_encode([
        'alert_type' => 'AP Disconnected',
        'state' => 'Close',
        'details' => ['serial' => $ctx['device']->serial],
    ], JSON_THROW_ON_ERROR);

    $this->call(
        'POST',
        route('webhooks.central', $ctx['client']),
        [],
        [],
        [],
        signedCentralWebhookHeaders($body, 'webhook-secret-token'),
        $body,
    )->assertOk()
        ->assertJson(['message' => 'Ignored']);

    Queue::assertNotPushed(HandleCentralDeviceOnlineWakeJob::class);
});

it('advances wait_for_online when wake job verifies the ap is up', function () {
    Queue::fake([RunProvisioningWorkflowStepJob::class]);
    $ctx = createWaitingWorkflowDevice();

    Http::fake([
        '*monitoring/v2/aps*' => Http::response([
            'aps' => [
                ['serial' => $ctx['device']->serial, 'status' => 'Up'],
            ],
        ], 200),
        '*monitoring/v1/switches*' => Http::response(['switches' => []], 200),
    ]);

    (new HandleCentralDeviceOnlineWakeJob($ctx['client']->id, $ctx['device']->serial))->handle(
        app(MarkDeviceOnlineIfWaiting::class),
    );

    $ctx['workflowDevice']->refresh();
    $step = $ctx['workflowDevice']->steps()->where('step_key', ProvisioningStep::WaitForOnline->value)->first();

    expect($step->status)->toBe('completed')
        ->and($ctx['workflowDevice']->current_step_key)->not->toBe(ProvisioningStep::WaitForOnline->value);
});

it('does not advance when wake job verifies the device is not up', function () {
    $ctx = createWaitingWorkflowDevice();

    Http::fake([
        '*monitoring/v2/aps*' => Http::response([
            'aps' => [
                ['serial' => $ctx['device']->serial, 'status' => 'Down'],
            ],
        ], 200),
    ]);

    (new HandleCentralDeviceOnlineWakeJob($ctx['client']->id, $ctx['device']->serial))->handle(
        app(MarkDeviceOnlineIfWaiting::class),
    );

    $step = $ctx['workflowDevice']->steps()->where('step_key', ProvisioningStep::WaitForOnline->value)->first();

    expect($step->fresh()->status)->toBe('in_progress');
});

it('is idempotent when wait_for_online is already completed', function () {
    Queue::fake();
    $ctx = createWaitingWorkflowDevice();
    $ctx['workflowDevice']->steps()
        ->where('step_key', ProvisioningStep::WaitForOnline->value)
        ->update(['status' => 'completed']);
    $ctx['workflowDevice']->update([
        'current_step_key' => ProvisioningStep::AssociateSite->value,
    ]);

    Http::fake([
        '*monitoring/v2/aps*' => Http::response([
            'aps' => [
                ['serial' => $ctx['device']->serial, 'status' => 'Up'],
            ],
        ], 200),
    ]);

    (new HandleCentralDeviceOnlineWakeJob($ctx['client']->id, $ctx['device']->serial))->handle(
        app(MarkDeviceOnlineIfWaiting::class),
    );

    expect($ctx['workflowDevice']->fresh()->current_step_key)->toBe(ProvisioningStep::AssociateSite->value);
    Queue::assertNotPushed(RunProvisioningWorkflowStepJob::class);
});

it('advances a waiting switch on new switch connected wake', function () {
    Queue::fake([RunProvisioningWorkflowStepJob::class]);
    $ctx = createWaitingWorkflowDevice([
        'serial' => 'CNXXYYZZAA',
        'device_function' => DeviceFunction::ACCESS_SWITCH->name,
    ]);

    Http::fake([
        '*monitoring/v1/switches*' => Http::response([
            'switches' => [
                ['serial' => 'CNXXYYZZAA', 'status' => 'Up'],
            ],
        ], 200),
        '*monitoring/v2/aps*' => Http::response(['aps' => []], 200),
    ]);

    $body = json_encode([
        'alert_type' => 'New Switch Connected',
        'state' => 'Open',
        'device_id' => 'CNXXYYZZAA',
        'details' => ['serial' => 'CNXXYYZZAA'],
    ], JSON_THROW_ON_ERROR);

    $this->call(
        'POST',
        route('webhooks.central', $ctx['client']),
        [],
        [],
        [],
        signedCentralWebhookHeaders($body, 'webhook-secret-token'),
        $body,
    )->assertOk();

    $step = $ctx['workflowDevice']->steps()->where('step_key', ProvisioningStep::WaitForOnline->value)->first();

    expect($step->fresh()->status)->toBe('completed');
});

it('saves and clears classic webhook secret from client edit', function () {
    $user = User::factory()->has(Client::factory()->state(['current' => true]))->create();
    $client = $user->clients()->first();
    $this->actingAs($user);

    $this->put(route('clients.edit', $client), [
        'classic_webhook_secret' => 'new-webhook-secret',
        'classic_webhook_wid' => 'wid-123',
    ])->assertRedirect(route('clients.index'));

    $client->refresh();
    expect($client->classic_webhook_secret)->toBe('new-webhook-secret')
        ->and($client->classic_webhook_wid)->toBe('wid-123');

    $this->put(route('clients.edit', $client), [
        'clear_classic_webhook_secret' => true,
    ])->assertRedirect(route('clients.index'));

    expect($client->fresh()->classic_webhook_secret)->toBeNull();
});

it('does not advance poll-mode wait_for_online from a webhook wake', function () {
    Queue::fake([RunProvisioningWorkflowStepJob::class]);
    $ctx = createWaitingWorkflowDevice([], [
        'online_detection_mode' => OnlineDetectionMode::Poll,
    ]);

    Http::fake([
        '*monitoring/v2/aps*' => Http::response([
            'aps' => [
                ['serial' => $ctx['device']->serial, 'status' => 'Up'],
            ],
        ], 200),
        '*monitoring/v1/switches*' => Http::response(['switches' => []], 200),
    ]);

    (new HandleCentralDeviceOnlineWakeJob($ctx['client']->id, $ctx['device']->serial))->handle(
        app(MarkDeviceOnlineIfWaiting::class),
    );

    $step = $ctx['workflowDevice']->steps()->where('step_key', ProvisioningStep::WaitForOnline->value)->first();

    expect($step->fresh()->status)->toBe('in_progress')
        ->and($ctx['workflowDevice']->fresh()->current_step_key)->toBe(ProvisioningStep::WaitForOnline->value);
});

it('fails wait_for_online when webhook timeout job fires while still waiting', function () {
    $ctx = createWaitingWorkflowDevice([], [
        'online_detection_mode' => OnlineDetectionMode::Webhook,
        'classic_poller_active' => false,
    ]);

    (new FailWaitForOnlineOnTimeoutJob($ctx['workflowDevice']->id))->handle(
        app(\App\Services\Provisioning\ProvisioningWorkflowOrchestrator::class),
    );

    $ctx['workflowDevice']->refresh();
    $step = $ctx['workflowDevice']->steps()->where('step_key', ProvisioningStep::WaitForOnline->value)->first();

    expect($step->status)->toBe('failed')
        ->and($ctx['workflowDevice']->overall_status)->toBe('failed')
        ->and($ctx['workflowDevice']->status_message)->toContain('Timed out');
});

it('no-ops webhook timeout job when wait_for_online already completed', function () {
    Queue::fake();
    $ctx = createWaitingWorkflowDevice([], [
        'online_detection_mode' => OnlineDetectionMode::Webhook,
    ]);
    $ctx['workflowDevice']->steps()
        ->where('step_key', ProvisioningStep::WaitForOnline->value)
        ->update(['status' => 'completed']);
    $ctx['workflowDevice']->update([
        'current_step_key' => ProvisioningStep::AssociateSite->value,
        'overall_status' => 'in_progress',
    ]);

    (new FailWaitForOnlineOnTimeoutJob($ctx['workflowDevice']->id))->handle(
        app(\App\Services\Provisioning\ProvisioningWorkflowOrchestrator::class),
    );

    expect($ctx['workflowDevice']->fresh()->current_step_key)->toBe(ProvisioningStep::AssociateSite->value)
        ->and($ctx['workflowDevice']->fresh()->overall_status)->toBe('in_progress');
});
