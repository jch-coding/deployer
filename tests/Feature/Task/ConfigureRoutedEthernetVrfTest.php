<?php

use App\BaseURL;
use App\Helper\CentralAPIHelper;
use App\InterfaceKind;
use App\Jobs\ConfigureEthernetInterface;
use App\Jobs\UpdateEthernetInterface;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\Site;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = Client::factory()->for($this->user)->create([
        'current' => true,
        'base_url' => BaseURL::US1,
        'bearer_token' => 'test-bearer-token',
        'expires_at' => now()->addHour(),
    ]);
    $this->deployment = Deployment::factory()->for($this->client)->create();
    $this->site = Site::factory()->for($this->client)->create(['scope_id' => 'site-scope-1']);
    $this->device = Device::factory()->for($this->deployment)->for($this->client)->for($this->site)->create([
        'scope_id' => 'device-scope-1',
        'device_function' => 'ACCESS_SWITCH',
        'group' => 'MyGroup',
    ]);
    $this->deviceInterface = DeviceInterface::factory()->for($this->device)->create([
        'interface' => '1/1/53',
        'ip_address' => '10.255.0.1/30',
        'vrf_forwarding' => 'my-vrf',
        'routing' => true,
        'interface_kind' => InterfaceKind::ETHERNET,
    ]);
    $this->task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'CONFIGURE_ETHERNET_INTERFACE',
        'status' => 'IN_PROGRESS',
    ]);
    $this->task->deviceInterfaces()->attach($this->deviceInterface->id, ['status' => 'PENDING']);
    $this->helper = new CentralAPIHelper($this->client);
});

test('ConfigureEthernetInterface creates vrf then patches routed ethernet interface', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'device-groups')) {
            return Http::response(['items' => [['scopeName' => 'MyGroup', 'scopeId' => 'group-scope-1']]], 200);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/vrfs')) {
            return Http::response(['vrf' => []], 200);
        }

        if ($request->method() === 'POST' && str_contains($request->url(), '/vrfs/my-vrf')) {
            return Http::response(['name' => 'my-vrf'], 200);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), 'ethernet-interfaces/1/1/53')) {
            return Http::response([], 200);
        }

        if ($request->method() === 'PATCH' && str_contains($request->url(), 'ethernet-interfaces/1/1/53')) {
            return Http::response(['name' => '1/1/53'], 200);
        }

        return Http::response([], 404);
    });

    $job = new ConfigureEthernetInterface($this->deviceInterface, $this->task, $this->helper);
    $job->handle();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), '/vrfs/my-vrf'));
    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && str_contains($request->url(), 'ethernet-interfaces/1/1/53'));
    expect($this->task->deviceInterfaces()->find($this->deviceInterface->id)->pivot->status)->toBe('COMPLETED');
});

test('ConfigureEthernetInterface skips vrf post when vrf already exists', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'device-groups')) {
            return Http::response(['items' => [['scopeName' => 'MyGroup', 'scopeId' => 'group-scope-1']]], 200);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/vrfs')) {
            return Http::response(['vrf' => [['name' => 'my-vrf']]], 200);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), 'ethernet-interfaces/1/1/53')) {
            return Http::response([], 200);
        }

        if ($request->method() === 'PATCH' && str_contains($request->url(), 'ethernet-interfaces/1/1/53')) {
            return Http::response(['name' => '1/1/53'], 200);
        }

        return Http::response([], 404);
    });

    $job = new ConfigureEthernetInterface($this->deviceInterface, $this->task, $this->helper);
    $job->handle();

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), '/vrfs/'));
    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && str_contains($request->url(), 'ethernet-interfaces/1/1/53'));
});

test('UpdateEthernetInterface creates vrf then patches routed ethernet interface', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'device-groups')) {
            return Http::response(['items' => [['scopeName' => 'MyGroup', 'scopeId' => 'group-scope-1']]], 200);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/vrfs')) {
            return Http::response(['vrf' => []], 200);
        }

        if ($request->method() === 'POST' && str_contains($request->url(), '/vrfs/my-vrf')) {
            return Http::response(['name' => 'my-vrf'], 200);
        }

        if ($request->method() === 'PATCH' && str_contains($request->url(), 'ethernet-interfaces/1/1/53')) {
            return Http::response(['name' => '1/1/53'], 200);
        }

        return Http::response([], 404);
    });

    $task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'UPDATE_ETHERNET_INTERFACE',
        'status' => 'IN_PROGRESS',
    ]);
    $task->deviceInterfaces()->attach($this->deviceInterface->id, ['status' => 'PENDING']);

    $job = new UpdateEthernetInterface($this->deviceInterface, $task, $this->helper);
    $job->handle();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), '/vrfs/my-vrf'));
    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && str_contains($request->url(), 'ethernet-interfaces/1/1/53'));
    expect($task->deviceInterfaces()->find($this->deviceInterface->id)->pivot->status)->toBe('COMPLETED');
});
