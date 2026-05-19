<?php

use App\DeviceFunction;
use App\Helper\CentralAPIHelper;
use App\Jobs\CreateVSFProfileJob;
use App\Models\Client;
use App\Models\Device;
use App\Models\Site;
use App\Models\Task;
use App\Models\User;
use App\SwitchSKU;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;

beforeEach(function () {
    $this->user = User::factory()->has(Client::factory())->create();
    $this->client = $this->user->clients()->first();
    $this->client->update(['current' => true]);
    $this->deployment = $this->client->deployments()->create(['name' => 'VSF Task Deployment']);
});

it('refreshes device scope_id after successful vsf profile creation', function () {
    Sleep::fake();

    $site = Site::factory()->for($this->client)->create([
        'scope_id' => 'site-scope-id',
    ]);

    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'CREATE_VSF_PROFILE',
        'status' => 'IN_PROGRESS',
    ]);

    $device = Device::factory()->create([
        'deployment_id' => $this->deployment->id,
        'client_id' => $this->client->id,
        'site_id' => $site->id,
        'scope_id' => 'old-device-scope',
        'sku' => SwitchSKU::JL658A->name,
        'device_function' => DeviceFunction::ACCESS_SWITCH,
    ]);
    $task->devices()->attach($device->id, ['status' => 'PENDING']);

    $successResponse = Mockery::mock(Response::class);
    $successResponse->shouldReceive('ok')->andReturn(true);

    $centralApi = Mockery::mock(CentralAPIHelper::class);
    $centralApi->shouldReceive('post_vsf_profile')->once()->with($device)->andReturn($successResponse);
    $centralApi->shouldReceive('getScopeIdFromCentral')->once()->with($device)->andReturn([['scopeId' => 'new-device-scope']]);

    $job = new CreateVSFProfileJob($device, $task, $centralApi);
    $job->handle();

    expect($device->fresh()->scope_id)->toBe('new-device-scope');
    expect($task->devices()->find($device->id)->pivot->status)->toBe('COMPLETED');
    Sleep::assertSequence([
        Sleep::for(10)->seconds(),
    ]);
});
