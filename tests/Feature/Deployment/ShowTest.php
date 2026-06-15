<?php

use App\DeviceFunction;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\Site;
use App\Models\Task;
use App\Models\User;
use App\TaskType;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()
        ->has(Client::factory())
        ->create();
    $this->client = $this->user->clients()->first();
    $this->client->update([
        'current' => true,
        'bearer_token' => 'test-bearer-token',
        'expires_at' => now()->addHour(),
    ]);
});

function fakeCentralScopeManagementApis(): void
{
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'network-config/v1/sites')) {
            return Http::response([
                'items' => [
                    ['scopeName' => 'Central Site', 'scopeId' => 'scope-site'],
                ],
            ], 200);
        }

        if (str_contains($request->url(), 'device-groups')) {
            return Http::response([
                'items' => [
                    ['scopeName' => 'Central Group', 'scopeId' => 'scope-group'],
                ],
            ], 200);
        }

        return Http::response([], 404);
    });
}

it('shows a list of devices associated with the deployment', function () {
    fakeCentralScopeManagementApis();

    $deployment = Deployment::factory()->for($this->client)->create();
    $devices = Device::factory(2)->for($deployment)->create();
    $this->actingAs($this->user);
    $this->get(route('deployments.show', $deployment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Deployment/Show')
            ->where('devices.data.0.name', $devices->first()->name)
            ->where('devices.data.1.name', $devices->last()->name)
        );
});

it('includes site and group on paginated devices and central scope options', function () {
    fakeCentralScopeManagementApis();

    $deployment = Deployment::factory()->for($this->client)->create();
    $site = Site::factory()->for($this->client)->create(['name' => 'Warehouse']);
    $device = Device::factory()->for($deployment)->create([
        'client_id' => $this->client->id,
        'user_id' => $this->user->id,
        'site_id' => $site->id,
        'group' => 'Edge Switches',
    ]);

    $this->actingAs($this->user)
        ->get(route('deployments.show', $deployment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Deployment/Show')
            ->where('devices.data.0.id', $device->id)
            ->where('devices.data.0.site', 'Warehouse')
            ->where('devices.data.0.group', 'Edge Switches')
            ->where('central_sites_error', null)
            ->where('central_device_groups_error', null)
            ->has('central_sites', 1)
            ->has('central_device_groups', 1)
            ->where('central_sites.0.scopeName', 'Central Site')
            ->where('central_device_groups.0.scopeName', 'Central Group'));
});

it('filters devices by name, serial, or device function search', function () {
    $deployment = Deployment::factory()->for($this->client)->create();
    Device::factory()->for($deployment)->create([
        'name' => 'Alpha Switch',
        'serial' => 'SERIAL-ALPHA-100',
        'device_function' => DeviceFunction::CAMPUS_AP->name,
    ]);
    Device::factory()->for($deployment)->create([
        'name' => 'Beta Switch',
        'serial' => 'SERIAL-BETA-200',
        'device_function' => DeviceFunction::ACCESS_SWITCH->name,
    ]);
    $this->actingAs($this->user);

    $this->get(route('deployments.show', $deployment).'?search='.urlencode('Alpha'))
        ->assertOk()
        ->assertSeeHtml('Alpha Switch');

    $this->get(route('deployments.show', $deployment).'?search='.urlencode('SERIAL-BETA'))
        ->assertOk()
        ->assertSeeHtml('Beta Switch');

    $this->get(route('deployments.show', $deployment).'?search='.urlencode('access_switch'))
        ->assertOk()
        ->assertSeeHtml('Beta Switch');
});

it('finalizes expired in-progress tasks when viewing the deployment', function () {
    $deployment = Deployment::factory()->for($this->client)->create();
    $task = Task::factory()->for($deployment)->create([
        'task_type' => 'UPDATE_SYSTEM_INFO',
        'status' => 'IN_PROGRESS',
        'deployment_time' => 1,
    ]);
    $task->timestamps = false;
    $task->update(['created_at' => now()->subMinutes(10)]);

    $device = Device::factory()->for($deployment)->create();
    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    $this->actingAs($this->user);
    $this->get(route('deployments.show', $deployment))
        ->assertOk();

    expect($task->fresh()->status)->toBe('TIMED_OUT');
});

it('has an upload devices button', function () {
    $this->actingAs($this->user);
    $deployment = Deployment::factory()->for($this->client)->create();
    $this->get(route('deployments.show', $deployment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Deployment/Show')
            ->has('devices.data', 0)
        );
});

it('has a set of task types that are available for deployment', function ($task_name) {
    $deployment = Deployment::factory()->for($this->client)->create();
    $this->actingAs($this->user);

    $this->get(route('deployments.show', $deployment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Deployment/Show')
            ->where('tasks', fn ($tasks) => collect($tasks)->pluck('task_type')->contains($task_name))
        );
})->with(array_map(fn ($t) => $t->name, TaskType::cases()));

it('can add devices to a deployment', function () {
    $deployment = Deployment::factory()->for($this->client)->create();
    $this->actingAs($this->user);
    $uploadedFile = UploadedFile::fake()
        ->createWithContent('devices.csv',
            'name,serial,device_function,site,description'.PHP_EOL.'Test Device 1,SN0000000001,CAMPUS_AP,CO Warehouse,First Test Device'.PHP_EOL.'Test Device 2,SN0000000002,CAMPUS_AP,CO Warehouse,Test Device 2'.PHP_EOL
        );
    $this->post(route('devices.store-many', $deployment), ['devices' => $uploadedFile])
        ->assertRedirect(route('deployments.show', $deployment));

    $this->assertDatabaseHas('devices', ['name' => 'Test Device 1', 'serial' => 'SN0000000001']);
    $this->assertDatabaseHas('devices', ['name' => 'Test Device 2', 'serial' => 'SN0000000002']);
});
