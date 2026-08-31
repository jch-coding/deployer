<?php

use App\Jobs\RebootAccessPointJob;
use App\Models\Client;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

test('reboot access point job calls central reboot endpoint', function () {
    $client = Client::factory()->create([
        'base_url' => \App\BaseURL::US1,
        'bearer_token' => 'test-bearer-token',
        'expires_at' => now()->addHour(),
    ]);

    Http::fake(function (Request $request) {
        expect($request->method())->toBe('POST')
            ->and($request->url())->toContain('network-troubleshooting/v1/aps/AP00000001/reboot');

        return Http::response(['status' => 'INITIATED'], 202);
    });

    (new RebootAccessPointJob($client->id, 'AP00000001'))->handle();

    Http::assertSentCount(1);
});

test('reboot access point job logs failure when central returns error', function () {
    Log::spy();

    $client = Client::factory()->create([
        'base_url' => \App\BaseURL::US1,
        'bearer_token' => 'test-bearer-token',
        'expires_at' => now()->addHour(),
    ]);

    Http::fake([
        '*network-troubleshooting/v1/aps/AP00000001/reboot*' => Http::response([
            'message' => 'Device not found: AP00000001',
        ], 404),
    ]);

    (new RebootAccessPointJob($client->id, 'AP00000001'))->handle();

    Log::shouldHaveReceived('error')
        ->once()
        ->with('RebootAccessPointJob: failed to reboot access point.', \Mockery::subset([
            'client_id' => $client->id,
            'serial' => 'AP00000001',
            'status' => 404,
        ]));
});
