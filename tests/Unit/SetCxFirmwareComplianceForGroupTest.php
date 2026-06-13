<?php

use App\Helper\CentralAPIHelper;
use App\Jobs\SetCxFirmwareComplianceForGroup;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Task;
use App\Models\User;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;

function firmwareComplianceHttpResponse(array $json, int $status = 200): Response
{
    return new Response(new Psr7Response($status, ['Content-Type' => 'application/json'], json_encode($json)));
}

it('posts firmware compliance and logs success without failing the vlan task', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($client)->create();

    $task = Task::factory()->create([
        'deployment_id' => $deployment->id,
        'task_type' => 'ADD_VLANS_FOR_DEVICE_GROUP',
        'status' => 'IN_PROGRESS',
        'vlan_target_device_group' => 'WHSE-SAC-CORE',
        'firmware_compliance_version' => 'FL.10.15.1010',
    ]);

    $centralClient = mock(Client::class)->makePartial();
    $centralClient->shouldReceive('handleClassicBearerToken')->andReturn(true);

    $helper = mock(CentralAPIHelper::class, [$centralClient])->makePartial();
    $helper->shouldReceive('classic_post_firmware_compliance')
        ->once()
        ->with([
            'device_type' => 'CX',
            'group' => 'WHSE-SAC-CORE',
            'firmware_compliance_version' => 'FL.10.15.1010',
        ])
        ->andReturn(firmwareComplianceHttpResponse(['status' => 'success'], 200));

    $job = new SetCxFirmwareComplianceForGroup('WHSE-SAC-CORE', 'FL.10.15.1010', $task->fresh(), $helper);
    $job->handle();

    expect($task->fresh()->status)->toBe('IN_PROGRESS')
        ->and($task->fresh()->status_log)->toContain('Set firmware compliance for WHSE-SAC-CORE to FL.10.15.1010');
});

it('marks the vlan task failed when firmware compliance request fails', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($client)->create();

    $task = Task::factory()->create([
        'deployment_id' => $deployment->id,
        'task_type' => 'ADD_VLANS_FOR_DEVICE_GROUP',
        'status' => 'IN_PROGRESS',
        'vlan_target_device_group' => 'WHSE-SAC-CORE',
        'firmware_compliance_version' => 'FL.10.15.1010',
    ]);

    $centralClient = mock(Client::class)->makePartial();
    $centralClient->shouldReceive('handleClassicBearerToken')->andReturn(true);

    $helper = mock(CentralAPIHelper::class, [$centralClient])->makePartial();
    $helper->shouldReceive('classic_post_firmware_compliance')
        ->once()
        ->andReturn(firmwareComplianceHttpResponse(['description' => 'invalid version'], 400));

    $job = new SetCxFirmwareComplianceForGroup('WHSE-SAC-CORE', 'FL.10.15.1010', $task->fresh(), $helper);
    $job->handle();

    expect($task->fresh()->status)->toBe('FAILED')
        ->and($task->fresh()->status_log)->toContain('Failed to set firmware compliance for WHSE-SAC-CORE');
});

it('marks the vlan task failed when classic_post_firmware_compliance returns token error array', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($client)->create();

    $task = Task::factory()->create([
        'deployment_id' => $deployment->id,
        'task_type' => 'ADD_VLANS_FOR_DEVICE_GROUP',
        'status' => 'IN_PROGRESS',
        'vlan_target_device_group' => 'WHSE-SAC-CORE',
        'firmware_compliance_version' => 'FL.10.15.1010',
    ]);

    $centralClient = mock(Client::class)->makePartial();
    $centralClient->shouldReceive('handleClassicBearerToken')->andReturn(true);

    $helper = mock(CentralAPIHelper::class, [$centralClient])->makePartial();
    $helper->shouldReceive('classic_post_firmware_compliance')
        ->once()
        ->andReturn(['error' => 'failed to get access token from central.']);

    $job = new SetCxFirmwareComplianceForGroup('WHSE-SAC-CORE', 'FL.10.15.1010', $task->fresh(), $helper);
    $job->handle();

    expect($task->fresh()->status)->toBe('FAILED');
});
