<?php

use App\BaseURL;
use App\Helper\CentralAPIHelper;
use App\InterfaceKind;
use App\Jobs\ConfigureLagInterfaceJob;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\LacpProfile;
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
    $this->lacpProfile = LacpProfile::factory()->create([
        'mode' => 'ACTIVE',
        'rate' => 'SLOW',
        'port_list' => '1/1/1-1/1/2',
        'trunk_type' => 'LACP',
    ]);
    $this->deviceInterface = DeviceInterface::factory()->for($this->device)->create([
        'interface' => '11',
        'lacp_profile_id' => $this->lacpProfile->id,
        'interface_kind' => InterfaceKind::LAG,
        'description' => 'Routed LAG',
        'ip_address' => '10.255.0.1/30',
        'vrf_forwarding' => 'my-vrf',
        'routing' => true,
    ]);
    $this->task = Task::factory()->for($this->deployment)->create([
        'task_type' => 'CONFIGURE_LAG_INTERFACE',
        'status' => 'IN_PROGRESS',
    ]);
    $this->task->deviceInterfaces()->attach($this->deviceInterface->id, ['status' => 'PENDING']);
    $this->helper = new CentralAPIHelper($this->client);
});

test('ConfigureLagInterfaceJob creates vrf then posts and patches routed LAG', function () {
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

        if ($request->method() === 'POST' && str_contains($request->url(), 'portchannels/11')) {
            return Http::response(['name' => '11'], 200);
        }

        if ($request->method() === 'PATCH' && str_contains($request->url(), 'portchannels/11')) {
            return Http::response(['name' => '11'], 200);
        }

        return Http::response([], 404);
    });

    $job = new ConfigureLagInterfaceJob($this->deviceInterface, $this->task, $this->helper);
    $job->handle();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), '/vrfs/my-vrf'));
    Http::assertSent(function (Request $request) {
        if ($request->method() !== 'POST' || ! str_contains($request->url(), 'portchannels/11')) {
            return false;
        }
        $body = json_decode($request->body(), true);

        return ($body['ipv4']['address'] ?? null) === '10.255.0.1/30'
            && ($body['vrf-forwarding'] ?? null) === 'my-vrf'
            && ! array_key_exists('routing', $body)
            && ! array_key_exists('switchport', $body);
    });
    Http::assertSent(function (Request $request) {
        if ($request->method() !== 'PATCH' || ! str_contains($request->url(), 'portchannels/11')) {
            return false;
        }
        $body = json_decode($request->body(), true);

        return ($body['routing'] ?? null) === true
            && ($body['ipv4']['address'] ?? null) === '10.255.0.1/30';
    });
    expect($this->task->deviceInterfaces()->find($this->deviceInterface->id)->pivot->status)->toBe('COMPLETED');
});

test('ConfigureLagInterfaceJob patches LAG when post fails because it is already configured', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'device-groups')) {
            return Http::response(['items' => [['scopeName' => 'MyGroup', 'scopeId' => 'group-scope-1']]], 200);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/vrfs')) {
            return Http::response(['vrf' => [['name' => 'my-vrf']]], 200);
        }

        if ($request->method() === 'POST' && str_contains($request->url(), 'portchannels/11')) {
            return Http::response(['message' => 'Cannot create duplicate config'], 400);
        }

        if ($request->method() === 'PATCH' && str_contains($request->url(), 'portchannels/11')) {
            return Http::response(['name' => '11'], 200);
        }

        return Http::response([], 404);
    });

    $job = new ConfigureLagInterfaceJob($this->deviceInterface, $this->task, $this->helper);
    $job->handle();

    Http::assertSent(function (Request $request) {
        if ($request->method() !== 'PATCH' || ! str_contains($request->url(), 'portchannels/11')) {
            return false;
        }
        $body = json_decode($request->body(), true);

        return ($body['ipv4']['address'] ?? null) === '10.255.0.1/30'
            && ($body['vrf-forwarding'] ?? null) === 'my-vrf'
            && ! array_key_exists('routing', $body);
    });
    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && str_contains($request->url(), 'portchannels/11'));
    expect($this->task->deviceInterfaces()->find($this->deviceInterface->id)->pivot->status)->toBe('COMPLETED');
});

test('ConfigureLagInterfaceJob skips vrf post when vrf already exists for routed LAG', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'device-groups')) {
            return Http::response(['items' => [['scopeName' => 'MyGroup', 'scopeId' => 'group-scope-1']]], 200);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/vrfs')) {
            return Http::response(['vrf' => [['name' => 'my-vrf']]], 200);
        }

        if ($request->method() === 'POST' && str_contains($request->url(), 'portchannels/11')) {
            return Http::response(['name' => '11'], 200);
        }

        if ($request->method() === 'PATCH' && str_contains($request->url(), 'portchannels/11')) {
            return Http::response(['name' => '11'], 200);
        }

        return Http::response([], 404);
    });

    $job = new ConfigureLagInterfaceJob($this->deviceInterface, $this->task, $this->helper);
    $job->handle();

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), '/vrfs/'));
    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && str_contains($request->url(), 'portchannels/11'));
});
