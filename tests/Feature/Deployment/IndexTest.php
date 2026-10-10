<?php

use App\Enums\ProvisioningStep;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\ProvisioningWorkflow;
use App\Models\Site;
use App\Models\Task;
use App\Models\User;
use App\Services\Provisioning\ProvisioningWorkflowTaskSync;
use Inertia\Testing\AssertableInertia as Assert;

test('index page returns a list of deployments for an authenticated user', function () {
    $user = User::factory()->has(Client::factory())->create();
    $client = $user->clients()->first();
    $client->update(['current' => true]);
    seedCentralScopeCache($client);
    $this->actingAs($user);
    $deployments = Deployment::factory(2)->for($client)->create();
    $this->get(route('deployments.index'))
        ->assertOk()
        ->assertSeeHtml($deployments->first()->name)
        ->assertSeeHtml($deployments->last()->name);
});

test('index page requires authentication', function () {
    $this->get(route('deployments.index'))->assertRedirect(route('login'));
});

test('a deployment is created with the current client by default', function () {
    $user = User::factory()
        ->has(Client::factory())
        ->create();
    $user->refresh()->clients()->first()->update(['current' => true]);
    $this->actingAs($user);
    $this->post(route('deployments.store'), ['name' => 'New Deployment'])
        ->assertRedirect(route('deployments.index'));
    $this->assertDatabaseHas('deployments', [
        'name' => 'New Deployment',
        'client_id' => $user->clients()->first()->id,
    ]);
});

test('a user can click on a deployment to view it', function () {
    $user = User::factory()->has(Client::factory())->create();
    $client = $user->clients()->first();
    $client->update(['current' => true]);
    seedCentralScopeCache($client);
    $deployment = Deployment::factory()->for($client)->create();
    $this->actingAs($user);
    $this->get(route('deployments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Deployment/Index')
            ->where('deployments.0.id', $deployment->id)
            ->where('deployments.0.name', $deployment->name)
            ->has('central_sites_cache.refreshed_at')
            ->has('central_groups_cache.refreshed_at')
        );
});

test('index page includes slim device fields for search', function () {
    $user = User::factory()->has(Client::factory())->create();
    $client = $user->clients()->first();
    $client->update(['current' => true]);
    seedCentralScopeCache($client);
    $deployment = Deployment::factory()->for($client)->create();
    $site = Site::factory()->for($client)->create(['name' => 'Warehouse']);
    Device::factory()->for($client)->for($deployment)->create([
        'name' => 'AP-Lobby',
        'serial' => 'CN12345678',
        'mac_address' => 'aa:bb:cc:dd:ee:ff',
        'group' => 'Floor-1',
        'controller_joined_ip' => '10.44.30.27',
        'site_id' => $site->id,
    ]);
    $this->actingAs($user);
    $this->get(route('deployments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Deployment/Index')
            ->where('deployments.0.id', $deployment->id)
            ->where('deployments.0.devices_count', 1)
            ->where('deployments.0.devices.0.name', 'AP-Lobby')
            ->where('deployments.0.devices.0.serial', 'CN12345678')
            ->where('deployments.0.devices.0.mac_address', 'aa:bb:cc:dd:ee:ff')
            ->where('deployments.0.devices.0.site', 'Warehouse')
            ->where('deployments.0.devices.0.group', 'Floor-1')
            ->where('deployments.0.devices.0.controller_joined_ip', '10.44.30.27')
        );
});

test('a user can delete a deployment on the index page', function () {
    $user = User::factory()->has(Client::factory())->create();
    $client = $user->clients()->first();
    $client->update(['current' => true]);
    $deployment = Deployment::factory()->for($client)->create();
    $this->actingAs($user);
    $this->delete(route('deployments.destroy', $deployment))
        ->assertRedirect(route('deployments.index'));
    $this->assertDatabaseMissing('deployments', ['id' => $deployment->id]);
});

it('has a button to add a new deployment', function () {
    $user = User::factory()->has(Client::factory())->create();
    $client = $user->clients()->first();
    $client->update(['current' => true]);
    seedCentralScopeCache($client);
    $this->actingAs($user);
    $this->get(route('deployments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Deployment/Index')
            ->has('deployments')
        );
});

test('index page includes in-progress tasks with progress and omits completed tasks', function () {
    $user = User::factory()->has(Client::factory())->create();
    $client = $user->clients()->first();
    $client->update(['current' => true]);
    seedCentralScopeCache($client);
    $deployment = Deployment::factory()->for($client)->create();

    $runningDevice = Device::factory()->for($client)->for($deployment)->create([
        'device_function' => 'CAMPUS_AP',
    ]);
    $completedDevice = Device::factory()->for($client)->for($deployment)->create([
        'device_function' => 'CAMPUS_AP',
    ]);

    $inProgressTask = Task::factory()->for($deployment)->create([
        'task_type' => 'ASSOCIATE_DEVICE_TO_SITE',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 10,
        'wait_time' => 1,
        'job_queue' => 'q0',
    ]);
    $inProgressTask->devices()->attach($runningDevice->id, ['status' => 'PENDING']);
    $inProgressTask->devices()->attach($completedDevice->id, ['status' => 'COMPLETED']);

    Task::factory()->for($deployment)->create([
        'task_type' => 'ASSOCIATE_DEVICE_TO_SITE',
        'status' => 'COMPLETED',
        'deployment_time' => 10,
        'wait_time' => 1,
        'job_queue' => 'q0',
    ]);

    $this->actingAs($user);
    $this->get(route('deployments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Deployment/Index')
            ->has('in_progress_tasks', 1)
            ->where('in_progress_tasks.0.id', $inProgressTask->id)
            ->where('in_progress_tasks.0.deployment_id', $deployment->id)
            ->where('in_progress_tasks.0.progress.completed', 1)
            ->where('in_progress_tasks.0.progress.failed', 0)
            ->where('in_progress_tasks.0.progress.in_progress', 1)
            ->where('in_progress_tasks.0.progress.total', 2)
            ->where('in_progress_tasks.0.workflow', null)
            ->where('in_progress_tasks.0.can_extend', true)
        );
});

test('index page includes custom in-progress tasks with append-step workflow fields', function () {
    $user = User::factory()->has(Client::factory())->create();
    $client = $user->clients()->first();
    $client->update(['current' => true]);
    seedCentralScopeCache($client);
    $deployment = Deployment::factory()->for($client)->create();
    $device = Device::factory()->for($client)->for($deployment)->create([
        'device_function' => 'ACCESS_SWITCH',
    ]);

    $workflow = ProvisioningWorkflow::query()->create([
        'deployment_id' => $deployment->id,
        'user_id' => $user->id,
        'status' => 'running',
        'job_queue' => 'q0',
        'deployment_time' => 10,
        'wait_time' => 1,
        'name' => 'Lobby rollout',
        'licensing_config' => ['mode' => 'skipped'],
        'steps' => [ProvisioningStep::AssociateSite->value],
        'started_at' => now(),
    ]);
    $workflow->workflowDevices()->create([
        'device_id' => $device->id,
        'overall_status' => 'in_progress',
        'current_step_key' => ProvisioningStep::AssociateSite->value,
        'status_message' => null,
    ]);

    app(ProvisioningWorkflowTaskSync::class)->createForWorkflow(
        $workflow->fresh(['workflowDevices']),
        $deployment,
    );

    $task = Task::query()->where('task_type', 'CUSTOM_PROVISION')->latest('id')->firstOrFail();

    $this->actingAs($user);
    $this->get(route('deployments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Deployment/Index')
            ->has('in_progress_tasks', 1)
            ->where('in_progress_tasks.0.id', $task->id)
            ->where('in_progress_tasks.0.task_type', 'CUSTOM_PROVISION')
            ->where('in_progress_tasks.0.progress.in_progress', 1)
            ->where('in_progress_tasks.0.progress.total', 1)
            ->where('in_progress_tasks.0.workflow.id', $workflow->id)
            ->where('in_progress_tasks.0.workflow.can_append_steps', true)
            ->has('in_progress_tasks.0.workflow.appendable_steps')
            ->where('in_progress_tasks.0.workflow.needs_licensing_for_append', true)
        );
});
