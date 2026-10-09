<?php

use App\BaseURL;
use App\Jobs\CreateNetworkAliasJob;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Task;
use App\Models\User;
use App\Support\NetworkAliasPayload;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create();
    $this->client = Client::factory()->for($this->user)->create([
        'current' => true,
        'base_url' => BaseURL::US1,
        'bearer_token' => 'test-bearer-token',
        'expires_at' => now()->addHour(),
    ]);
    $this->deployment = Deployment::factory()->for($this->client)->create();
    $this->actingAs($this->user);
});

test('create network alias task stores site details and dispatches job', function () {
    Bus::fake();

    $response = $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'CREATE_NETWORK_ALIAS',
        'deployment_time' => 10,
        'site_name' => '07 - Boulder',
        'site_scope_id' => 'site-scope-7',
    ]);

    $response->assertSessionHasNoErrors();
    $task = $this->deployment->refresh()->tasks()->first();

    expect($task)->not->toBeNull()
        ->and($task->task_type)->toBe('CREATE_NETWORK_ALIAS')
        ->and($task->site_details)->toMatchArray([
            'site_name' => '07 - Boulder',
            'site_scope_id' => 'site-scope-7',
            'network_ipv4_address' => '10.7.9.0/24',
            'alias_name' => NetworkAliasPayload::ALIAS_NAME,
        ]);

    Bus::assertBatched(function ($batch) {
        return count($batch->jobs) === 1
            && $batch->jobs[0] instanceof CreateNetworkAliasJob;
    });
});

test('create network alias task honors network ipv4 override', function () {
    Bus::fake();

    $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'CREATE_NETWORK_ALIAS',
        'deployment_time' => 10,
        'site_name' => 'Warehouse',
        'site_scope_id' => 'site-scope-1',
        'network_ipv4_address' => '10.99.9.0/24',
    ])->assertSessionHasNoErrors();

    $task = $this->deployment->refresh()->tasks()->first();

    expect($task->site_details['network_ipv4_address'])->toBe('10.99.9.0/24');
});

test('create network alias task rejects missing site', function () {
    Bus::fake();

    $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'CREATE_NETWORK_ALIAS',
        'deployment_time' => 10,
    ])->assertSessionHasErrors('site_name');

    expect($this->deployment->refresh()->tasks)->toHaveCount(0);
    Bus::assertNothingBatched();
});

test('create network alias task rejects unparseable site name without override', function () {
    Bus::fake();

    $this->post(route('tasks.store', $this->deployment), [
        'task_type' => 'CREATE_NETWORK_ALIAS',
        'deployment_time' => 10,
        'site_name' => 'Warehouse',
        'site_scope_id' => 'site-scope-1',
    ])->assertSessionHasErrors('site_name');

    expect($this->deployment->refresh()->tasks)->toHaveCount(0);
    Bus::assertNothingBatched();
});

test('create network alias job posts alias body to central', function () {
    Http::fake([
        '*/network-config/v1alpha1/aliases/BVSD-VIVI-SUBNET*' => Http::response(['status' => 'ok'], 200),
    ]);

    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'CREATE_NETWORK_ALIAS',
        'status' => 'IN_PROGRESS',
        'site_details' => [
            'site_name' => '07 - Boulder',
            'site_scope_id' => 'site-scope-7',
            'network_ipv4_address' => '10.7.9.0/24',
            'alias_name' => NetworkAliasPayload::ALIAS_NAME,
        ],
    ]);

    $helper = new App\Helper\CentralAPIHelper($this->client);
    (new CreateNetworkAliasJob($task, $helper))->handle();

    expect($task->fresh()->status)->toBe('COMPLETED');

    Http::assertSent(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
        $body = json_decode($request->body(), true);

        return $request->method() === 'POST'
            && str_contains($request->url(), '/network-config/v1alpha1/aliases/BVSD-VIVI-SUBNET')
            && ($query['object-type'] ?? null) === 'LOCAL'
            && ($query['scope-id'] ?? null) === 'site-scope-7'
            && ($query['device-function'] ?? null) === 'CAMPUS_AP'
            && ($body['name'] ?? null) === 'BVSD-VIVI-SUBNET'
            && ($body['type'] ?? null) === 'ALIAS_NETWORK'
            && data_get($body, 'default-value.network-address-value.network-ipv4-address') === '10.7.9.0/24';
    });
});

test('create network alias job marks task failed on central error', function () {
    Http::fake([
        '*/network-config/v1alpha1/aliases/BVSD-VIVI-SUBNET*' => Http::response(['message' => 'boom'], 400),
    ]);

    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'CREATE_NETWORK_ALIAS',
        'status' => 'IN_PROGRESS',
        'site_details' => [
            'site_name' => '07 - Boulder',
            'site_scope_id' => 'site-scope-7',
            'network_ipv4_address' => '10.7.9.0/24',
            'alias_name' => NetworkAliasPayload::ALIAS_NAME,
        ],
    ]);

    $helper = new App\Helper\CentralAPIHelper($this->client);
    (new CreateNetworkAliasJob($task, $helper))->handle();

    expect($task->fresh()->status)->toBe('FAILED');
});
