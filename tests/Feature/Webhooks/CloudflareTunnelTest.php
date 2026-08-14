<?php

use App\Models\Client;
use App\Models\User;
use App\Services\Cloudflare\CloudflaredTunnelService;
use Illuminate\Support\Facades\Http;

function webhookUserWithCurrentClient(): array
{
    $user = User::factory()->has(Client::factory()->state(['current' => true]))->create();
    $client = $user->clients()->first();

    return compact('user', 'client');
}

function fakeTunnelStatus(array $overrides = []): array
{
    return array_merge([
        'binary' => true,
        'binary_path' => '/usr/local/bin/cloudflared',
        'logged_in' => true,
        'name' => 'deployer',
        'hostname' => 'deployment-cnx.example.com',
        'running' => false,
        'pid' => null,
        'message' => null,
        'available' => true,
    ], $overrides);
}

it('redirects guests away from cloudflare tunnel start', function () {
    $this->postJson(route('webhooks.cloudflare_tunnel.start'), ['name' => 'deployer'])
        ->assertUnauthorized();
});

it('includes cloudflare_tunnel on the webhook index page', function () {
    $this->withoutVite();
    ['user' => $user] = webhookUserWithCurrentClient();

    $this->mock(CloudflaredTunnelService::class, function ($mock) {
        $mock->shouldReceive('status')->once()->andReturn(fakeTunnelStatus([
            'running' => true,
            'pid' => 4242,
        ]));
    });

    $this->actingAs($user)
        ->get(route('webhooks.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Webhook/Index')
            ->where('cloudflare_tunnel.running', true)
            ->where('cloudflare_tunnel.name', 'deployer')
            ->where('cloudflare_tunnel.pid', 4242));
});

it('validates tunnel name on start', function () {
    ['user' => $user] = webhookUserWithCurrentClient();

    $this->mock(CloudflaredTunnelService::class, function ($mock) {
        $mock->shouldReceive('status')->never();
        $mock->shouldReceive('start')->never();
    });

    $this->actingAs($user)
        ->postJson(route('webhooks.cloudflare_tunnel.start'), ['name' => 'bad name!'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('starts and stops a named tunnel', function () {
    ['user' => $user] = webhookUserWithCurrentClient();

    $this->mock(CloudflaredTunnelService::class, function ($mock) {
        $mock->shouldReceive('start')
            ->once()
            ->with('deployer')
            ->andReturn([
                'ok' => true,
                'message' => 'Tunnel deployer started (pid 99).',
                'status' => fakeTunnelStatus(['running' => true, 'pid' => 99]),
            ]);
        $mock->shouldReceive('stop')
            ->once()
            ->andReturn([
                'ok' => true,
                'message' => 'Stopped tunnel process 99.',
                'status' => fakeTunnelStatus(['running' => false]),
            ]);
    });

    $this->actingAs($user)
        ->postJson(route('webhooks.cloudflare_tunnel.start'), ['name' => 'deployer'])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('status.running', true);

    $this->actingAs($user)
        ->postJson(route('webhooks.cloudflare_tunnel.stop'))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('status.running', false);
});

it('reports nested-zone dns failure from the dns step', function () {
    ['user' => $user] = webhookUserWithCurrentClient();

    $this->mock(CloudflaredTunnelService::class, function ($mock) {
        $mock->shouldReceive('assertValidHostname')->once()->with('app.domain2.com');
        $mock->shouldReceive('routeDns')
            ->once()
            ->with('deployer', 'app.domain2.com')
            ->andReturn([
                'ok' => false,
                'manual' => true,
                'message' => 'cloudflared created the CNAME in the wrong zone.',
                'expected_cname' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee.cfargotunnel.com',
                'record_name' => 'app.domain2.com.domain1.com',
            ]);
    });

    $this->actingAs($user)
        ->postJson(route('webhooks.cloudflare_tunnel.dns'), [
            'name' => 'deployer',
            'hostname' => 'app.domain2.com',
        ])
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('manual', true)
        ->assertJsonPath('record_name', 'app.domain2.com.domain1.com');
});

it('reports dns success', function () {
    ['user' => $user] = webhookUserWithCurrentClient();

    $this->mock(CloudflaredTunnelService::class, function ($mock) {
        $mock->shouldReceive('assertValidHostname')->once();
        $mock->shouldReceive('routeDns')
            ->once()
            ->andReturn([
                'ok' => true,
                'message' => 'DNS CNAME for app.example.com routed to tunnel deployer.',
                'record_name' => 'app.example.com',
            ]);
    });

    $this->actingAs($user)
        ->postJson(route('webhooks.cloudflare_tunnel.dns'), [
            'name' => 'deployer',
            'hostname' => 'app.example.com',
        ])
        ->assertOk()
        ->assertJsonPath('ok', true);
});

it('runs the tunnel and updates APP_URL when requested', function () {
    ['user' => $user] = webhookUserWithCurrentClient();

    $this->mock(CloudflaredTunnelService::class, function ($mock) {
        $mock->shouldReceive('assertValidHostname')->once();
        $mock->shouldReceive('runTunnel')
            ->once()
            ->with('deployer', 'app.example.com', true)
            ->andReturn([
                'ok' => true,
                'message' => 'Tunnel deployer started and APP_URL set to https://app.example.com.',
                'status' => fakeTunnelStatus([
                    'running' => true,
                    'hostname' => 'app.example.com',
                ]),
            ]);
    });

    $this->actingAs($user)
        ->postJson(route('webhooks.cloudflare_tunnel.run'), [
            'name' => 'deployer',
            'hostname' => 'app.example.com',
            'update_app_url' => true,
        ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('status.hostname', 'app.example.com');
});

it('runs the tunnel without updating APP_URL when disabled', function () {
    ['user' => $user] = webhookUserWithCurrentClient();

    $this->mock(CloudflaredTunnelService::class, function ($mock) {
        $mock->shouldReceive('assertValidHostname')->once();
        $mock->shouldReceive('runTunnel')
            ->once()
            ->with('deployer', 'app.example.com', false)
            ->andReturn([
                'ok' => true,
                'message' => 'Tunnel deployer started.',
                'status' => fakeTunnelStatus(['running' => true]),
            ]);
    });

    $this->actingAs($user)
        ->postJson(route('webhooks.cloudflare_tunnel.run'), [
            'name' => 'deployer',
            'hostname' => 'app.example.com',
            'update_app_url' => false,
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Tunnel deployer started.');
});

it('creates a named tunnel via the wizard create endpoint', function () {
    ['user' => $user] = webhookUserWithCurrentClient();

    $this->mock(CloudflaredTunnelService::class, function ($mock) {
        $mock->shouldReceive('createTunnel')
            ->once()
            ->with('deployer')
            ->andReturn([
                'ok' => true,
                'message' => 'Tunnel deployer created.',
                'tunnel_id' => '11111111-2222-3333-4444-555555555555',
                'name' => 'deployer',
            ]);
    });

    $this->actingAs($user)
        ->postJson(route('webhooks.cloudflare_tunnel.create'), ['name' => 'deployer'])
        ->assertOk()
        ->assertJsonPath('tunnel_id', '11111111-2222-3333-4444-555555555555');
});

it('upserts a tunnel cname via the cloudflare dns api helper', function () {
    // More-specific dns_records URL must be registered before zones* (which would also match it).
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-child/dns_records*' => Http::sequence()
            ->push(['success' => true, 'result' => []])
            ->push(['success' => true, 'result' => ['id' => 'rec-1']]),
        'https://api.cloudflare.com/client/v4/zones*' => Http::response([
            'success' => true,
            'result' => [
                ['id' => 'zone-parent', 'name' => 'domain1.com'],
                ['id' => 'zone-child', 'name' => 'domain2.com'],
            ],
        ]),
    ]);

    config(['services.cloudflare.api_token' => 'test-token']);

    $service = new \App\Services\Cloudflare\CloudflareDnsService;
    $result = $service->upsertTunnelCname(
        'app.domain2.com',
        'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['zone'])->toBe('domain2.com');

    Http::assertSent(function ($request) {
        return str_starts_with($request->url(), 'https://api.cloudflare.com/client/v4/zones/zone-child/dns_records')
            && $request->method() === 'POST'
            && $request['name'] === 'app'
            && $request['content'] === 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee.cfargotunnel.com';
    });
});
