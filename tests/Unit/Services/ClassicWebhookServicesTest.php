<?php

use App\Services\Central\ClassicWebhookAlertParser;
use App\Services\Central\ClassicWebhookHmacVerifier;
use Illuminate\Http\Request;

it('verifies a valid classic central hmac signature', function () {
    $secret = 'webhook-secret-token';
    $body = '{"alert_type":"New AP detected","details":{"serial":"DZ1"}}';
    $service = 'Alerts';
    $deliveryId = '72d3162e-cc78-11e3-81ab-4c9367dc0958';
    $timestamp = '2016-07-12T13:14:19-07:00';
    $signature = base64_encode(hash_hmac('sha256', $body.$service.$deliveryId.$timestamp, $secret, true));

    $request = Request::create('/webhooks/central/1', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_CENTRAL_SERVICE' => $service,
        'HTTP_X_CENTRAL_DELIVERY_ID' => $deliveryId,
        'HTTP_X_CENTRAL_DELIVERY_TIMESTAMP' => $timestamp,
        'HTTP_X_CENTRAL_SIGNATURE' => $signature,
    ], $body);

    expect((new ClassicWebhookHmacVerifier)->verify($request, $secret))->toBeTrue();
});

it('rejects an invalid classic central hmac signature', function () {
    $request = Request::create('/webhooks/central/1', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_CENTRAL_SERVICE' => 'Alerts',
        'HTTP_X_CENTRAL_DELIVERY_ID' => 'id',
        'HTTP_X_CENTRAL_DELIVERY_TIMESTAMP' => 'ts',
        'HTTP_X_CENTRAL_SIGNATURE' => 'bad',
    ], '{"ok":true}');

    expect((new ClassicWebhookHmacVerifier)->verify($request, 'secret'))->toBeFalse();
});

it('accepts new ap detected and new switch connected alerts', function () {
    $parser = new ClassicWebhookAlertParser;

    expect($parser->isOnlineWakeAlert(['alert_type' => 'New AP detected']))->toBeTrue()
        ->and($parser->isOnlineWakeAlert(['alert_type' => 'New Switch Connected']))->toBeTrue()
        ->and($parser->isOnlineWakeAlert(['alert_type' => 'AP Disconnected']))->toBeFalse()
        ->and($parser->isOnlineWakeAlert(['alert_type' => 'Switch Disconnected']))->toBeFalse();
});

it('extracts serial from details then device_id', function () {
    $parser = new ClassicWebhookAlertParser;

    expect($parser->extractSerial([
        'device_id' => 'FALLBACK',
        'details' => ['serial' => 'DZ0001581'],
    ]))->toBe('DZ0001581')
        ->and($parser->extractSerial([
            'device_id' => 'CNXXYYZZAA',
            'details' => [],
        ]))->toBe('CNXXYYZZAA')
        ->and($parser->extractSerial(['details' => []]))->toBeNull();
});
