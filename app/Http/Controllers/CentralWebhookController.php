<?php

namespace App\Http\Controllers;

use App\JobQueueShard;
use App\Jobs\HandleCentralDeviceOnlineWakeJob;
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

        if (! $alertParser->isOnlineWakeAlert($payload)) {
            return response()->json(['message' => 'Ignored'], 200);
        }

        $serial = $alertParser->extractSerial($payload);
        if ($serial === null) {
            return response()->json(['message' => 'Ignored'], 200);
        }

        HandleCentralDeviceOnlineWakeJob::dispatch($client->id, $serial)
            ->onQueue(JobQueueShard::resolve(null));

        return response()->json(['message' => 'Accepted'], 200);
    }
}
