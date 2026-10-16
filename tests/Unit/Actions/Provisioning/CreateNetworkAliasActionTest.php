<?php

use App\Actions\Provisioning\CreateNetworkAliasAction;
use App\Helper\CentralAPIHelper;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\ProvisioningWorkflow;
use App\Models\User;
use App\Support\NetworkAliasPayload;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

it('creates the alias once and short-circuits subsequent devices', function () {
    Cache::flush();

    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($client)->create();
    $workflow = ProvisioningWorkflow::query()->create([
        'deployment_id' => $deployment->id,
        'user_id' => $user->id,
        'status' => 'running',
        'job_queue' => 'q0',
        'deployment_time' => 10,
        'wait_time' => 1,
        'licensing_config' => [
            'network_alias' => [
                'site_name' => '07 - Boulder',
                'site_scope_id' => 'scope-1',
                'network_ipv4_address' => '10.7.9.0/24',
                'alias_name' => NetworkAliasPayload::ALIAS_NAME,
                'status' => null,
                'message' => null,
            ],
        ],
    ]);

    $helper = mock(CentralAPIHelper::class);
    $helper->shouldReceive('create_alias')
        ->once()
        ->andReturn(new Response(new Psr7Response(200, [], '{"ok":true}')));

    $action = new CreateNetworkAliasAction;
    $first = $action->execute($workflow->fresh(), $helper);
    $second = $action->execute($workflow->fresh(), $helper);

    expect($first->isCompleted())->toBeTrue()
        ->and($first->message)->toContain('Created network alias')
        ->and($second->isCompleted())->toBeTrue()
        ->and($second->message)->toContain('Created network alias')
        ->and($workflow->fresh()->licensing_config['network_alias']['status'])->toBe('completed');
});

it('fails when network alias config is incomplete', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($client)->create();
    $workflow = ProvisioningWorkflow::query()->create([
        'deployment_id' => $deployment->id,
        'user_id' => $user->id,
        'status' => 'running',
        'job_queue' => 'q0',
        'deployment_time' => 10,
        'wait_time' => 1,
        'licensing_config' => [
            'network_alias' => [
                'site_name' => '',
                'site_scope_id' => '',
                'network_ipv4_address' => '',
            ],
        ],
    ]);

    $helper = mock(CentralAPIHelper::class);
    $helper->shouldReceive('create_alias')->never();

    $result = (new CreateNetworkAliasAction)->execute($workflow, $helper);

    expect($result->isFailed())->toBeTrue()
        ->and($result->message)->toContain('Missing site name');
});
