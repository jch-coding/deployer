<?php

namespace App\Http\Controllers;

use App\Events\CentralWebhookReceived;
use App\JobQueueShard;
use App\Jobs\HandleCentralDeviceOnlineWakeJob;
use App\Models\CentralWebhookEvent;
use App\Models\Client;
use App\Services\Central\ClassicWebhookAlertParser;
use App\Services\Central\ClassicWebhookHmacVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CentralWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        Client $client,
        ClassicWebhookHmacVerifier $hmacVerifier,
        ClassicWebhookAlertParser $alertParser,
    ): JsonResponse {
        $secret = (string) ($client->classic_webhook_secret ?? '');
        if ($secret === '' || ! $hmacVerifier->verify($request, $secret)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $payload = $request->json()->all();
        if (! is_array($payload)) {
            return response()->json(['message' => 'Accepted'], 200);
        }

        $isWake = $alertParser->isOnlineWakeAlert($payload);
        $serial = $alertParser->extractSerial($payload);
        $disposition = $isWake && $serial !== null ? 'accepted' : 'ignored';

        $event = CentralWebhookEvent::query()->create([
            'client_id' => $client->id,
            'payload' => $payload,
            'alert_type' => isset($payload['alert_type']) ? (string) $payload['alert_type'] : null,
            'serial' => $serial,
            'disposition' => $disposition,
            'created_at' => now(),
        ]);
        CentralWebhookEvent::pruneForClient((int) $client->id);
        CentralWebhookReceived::dispatch($event);

        if ($disposition !== 'accepted') {
            return response()->json(['message' => 'Ignored'], 200);
        }

        HandleCentralDeviceOnlineWakeJob::dispatch($client->id, $serial, 'webhook')
            ->onQueue(JobQueueShard::resolve(null));

        return response()->json(['message' => 'Accepted'], 200);
    }
}
