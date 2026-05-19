<?php

use App\DeviceFunction;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = Client::factory()->for($this->user)->create([
        'expires_at' => now()->addHour(),
        'current' => true,
    ]);
    $this->deployment = Deployment::factory()->for($this->client)->create();
    $this->actingAs($this->user);
});

function hierarchyScopeResponse(string $scopeId): array
{
    return [
        'items' => [
            [
                'hierarchy' => [
                    [
                        'childCount' => null,
                        'scopeType' => 'device',
                        'scopeId' => $scopeId,
                    ],
                ],
            ],
        ],
    ];
}

test('bulk refresh scope ids updates selected devices', function () {
    Http::fake([
        '*network-config/v1/hierarchy*' => Http::response(hierarchyScopeResponse('scope-new-a'), 200),
    ]);

    $deviceA = Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'name' => 'Device A',
        'serial' => 'SERIAL-A-00000001',
        'device_function' => DeviceFunction::CAMPUS_AP->name,
        'scope_id' => 'old-scope',
    ]);
    $deviceB = Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'name' => 'Device B',
        'serial' => 'SERIAL-B-00000002',
        'device_function' => DeviceFunction::CAMPUS_AP->name,
        'scope_id' => 'old-scope-b',
    ]);

    $this->from(route('deployments.show', $this->deployment))
        ->post(route('deployments.refresh-scope-ids', $this->deployment), [
            'device_ids' => [$deviceA->id],
        ])
        ->assertRedirect(route('deployments.show', $this->deployment))
        ->assertSessionHas('success', 'Updated scope ID for Device A: scope-new-a.');

    expect($deviceA->fresh()->scope_id)->toBe('scope-new-a')
        ->and($deviceB->fresh()->scope_id)->toBe('old-scope-b');
});

test('bulk refresh scope ids sync all respects search filter', function () {
    Http::fake([
        '*network-config/v1/hierarchy*' => Http::response(hierarchyScopeResponse('scope-alpha'), 200),
    ]);

    $alpha = Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'name' => 'Alpha Device',
        'serial' => 'SERIAL-ALPHA-100',
        'device_function' => DeviceFunction::CAMPUS_AP->name,
        'scope_id' => null,
    ]);
    $beta = Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'name' => 'Beta Device',
        'serial' => 'SERIAL-BETA-200',
        'device_function' => DeviceFunction::CAMPUS_AP->name,
        'scope_id' => null,
    ]);

    $this->post(route('deployments.refresh-scope-ids', $this->deployment), [
        'sync_all' => true,
        'search' => 'Alpha',
    ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Updated scope ID for Alpha Device: scope-alpha.');

    expect($alpha->fresh()->scope_id)->toBe('scope-alpha')
        ->and($beta->fresh()->scope_id)->toBeNull();
});

test('bulk refresh scope ids rejects devices outside deployment', function () {
    Http::fake();

    $otherDeployment = Deployment::factory()->for($this->client)->create();
    $foreignDevice = Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $otherDeployment->id,
    ]);

    $this->post(route('deployments.refresh-scope-ids', $this->deployment), [
        'device_ids' => [$foreignDevice->id],
    ])
        ->assertRedirect()
        ->assertSessionHas('error', 'One or more selected devices do not belong to this deployment.');

    Http::assertNothingSent();
});

test('bulk refresh scope ids rejects when current client does not match deployment', function () {
    Http::fake();

    $otherClient = Client::factory()->for($this->user)->create(['current' => false]);
    $otherDeployment = Deployment::factory()->for($otherClient)->create();
    $device = Device::factory()->create([
        'client_id' => $otherClient->id,
        'user_id' => $this->user->id,
        'deployment_id' => $otherDeployment->id,
    ]);

    $this->post(route('deployments.refresh-scope-ids', $otherDeployment), [
        'device_ids' => [$device->id],
    ])
        ->assertRedirect()
        ->assertSessionHas('error', 'Please set current client to match this deployment before refreshing device scope IDs.');

    Http::assertNothingSent();
});

test('bulk refresh scope ids reports partial failures', function () {
    Http::fake([
        '*network-config/v1/hierarchy*' => Http::sequence()
            ->push(hierarchyScopeResponse('scope-ok'), 200)
            ->push(Http::response([], 500), 500),
    ]);

    $deviceOk = Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'name' => 'Device OK',
        'serial' => 'SERIAL-OK-00000001',
        'device_function' => DeviceFunction::CAMPUS_AP->name,
        'scope_id' => null,
    ]);
    $deviceFail = Device::factory()->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'deployment_id' => $this->deployment->id,
        'name' => 'Device Fail',
        'serial' => 'SERIAL-FAIL-00000002',
        'device_function' => DeviceFunction::CAMPUS_AP->name,
        'scope_id' => null,
    ]);

    $this->post(route('deployments.refresh-scope-ids', $this->deployment), [
        'device_ids' => [$deviceOk->id, $deviceFail->id],
    ])
        ->assertRedirect()
        ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Device Fail')
            && str_contains($message, 'Device OK: scope-ok'));

    expect($deviceOk->fresh()->scope_id)->toBe('scope-ok')
        ->and($deviceFail->fresh()->scope_id)->toBeNull();
});
