<?php

namespace App\Services\Central;

use App\Enums\OnlineDetectionMode;
use App\Events\CentralStreamMessageReceived;
use App\JobQueueShard;
use App\Jobs\HandleCentralDeviceOnlineWakeJob;
use App\Models\CentralStreamEvent;
use App\Models\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use function Ratchet\Client\connect;

class ClassicMonitoringStreamManager
{
    public function __construct(
        private readonly ClassicMonitoringStreamDecoder $decoder,
    ) {}

    public function run(?LoopInterface $loop = null): void
    {
        $loop ??= Loop::get();
        /** @var array<int, true> $tracked */
        $tracked = [];

        $syncClients = function () use ($loop, &$tracked): void {
            $clients = Client::query()
                ->whereNotNull('classic_streaming_hostname')
                ->whereNotNull('classic_streaming_key')
                ->whereNotNull('classic_streaming_username')
                ->get();

            $activeIds = [];

            foreach ($clients as $client) {
                if (! $client->hasClassicStreamingCredentials()) {
                    continue;
                }

                $activeIds[$client->id] = true;
                if (isset($tracked[$client->id])) {
                    continue;
                }

                $tracked[$client->id] = true;
                $this->maintainConnection($loop, $client->id, $tracked);
            }

            foreach (array_keys($tracked) as $clientId) {
                if (! isset($activeIds[$clientId])) {
                    unset($tracked[$clientId]);
                }
            }
        };

        $syncClients();
        $loop->addPeriodicTimer(30, $syncClients);
        $loop->run();
    }

    /**
     * @param  array<int, true>  $tracked
     */
    private function maintainConnection(LoopInterface $loop, int $clientId, array &$tracked): void
    {
        $attempt = 0;

        $connect = function () use ($loop, $clientId, &$tracked, &$attempt, &$connect): void {
            $client = Client::query()->find($clientId);
            if ($client === null || ! $client->hasClassicStreamingCredentials()) {
                unset($tracked[$clientId]);

                return;
            }

            $hostname = trim((string) $client->classic_streaming_hostname);
            $url = 'wss://'.rtrim($hostname, '/').'/streaming/api';
            $headers = [
                'Authorization' => (string) $client->classic_streaming_key,
                'UserName' => (string) $client->classic_streaming_username,
                'Topic' => 'monitoring',
            ];

            connect($url, [], $headers, $loop)->then(
                function ($conn) use ($loop, $clientId, &$tracked, &$attempt, &$connect): void {
                    $attempt = 0;
                    Log::info('Classic streaming connected', ['client_id' => $clientId]);

                    $conn->on('message', function ($message) use ($clientId): void {
                        $payload = is_string($message) ? $message : (string) $message;
                        $this->handleFrame($clientId, $payload);
                    });

                    $conn->on('close', function () use ($loop, $clientId, &$tracked, &$attempt, &$connect): void {
                        Log::warning('Classic streaming disconnected', ['client_id' => $clientId]);
                        $this->scheduleReconnect($loop, $clientId, $tracked, $attempt, $connect);
                        $attempt++;
                    });
                },
                function ($error) use ($loop, $clientId, &$tracked, &$attempt, &$connect): void {
                    Log::error('Classic streaming connect failed', [
                        'client_id' => $clientId,
                        'error' => (string) $error,
                    ]);
                    $this->scheduleReconnect($loop, $clientId, $tracked, $attempt, $connect);
                    $attempt++;
                },
            );
        };

        $connect();
    }

    /**
     * @param  array<int, true>  $tracked
     * @param  callable(): void  $connect
     */
    private function scheduleReconnect(
        LoopInterface $loop,
        int $clientId,
        array &$tracked,
        int $attempt,
        callable $connect,
    ): void {
        $delay = min(60, 2 ** min($attempt, 5));
        $loop->addTimer($delay, function () use ($clientId, &$tracked, $connect): void {
            $client = Client::query()->find($clientId);
            if ($client === null || ! $client->hasClassicStreamingCredentials()) {
                unset($tracked[$clientId]);

                return;
            }

            $tracked[$clientId] = true;
            $connect();
        });
    }

    public function handleFrame(int $clientId, string $frame): void
    {
        $decoded = $this->decoder->decodeMonitoringFrame($frame);
        if ($decoded === null) {
            return;
        }

        $event = CentralStreamEvent::query()->create([
            'client_id' => $clientId,
            'subject' => $decoded['subject'],
            'customer_id' => $decoded['customer_id'],
            'timestamp' => $decoded['timestamp'],
            'decoded' => [
                'aps' => $decoded['aps'],
                'switches' => $decoded['switches'],
                'data_elements' => $decoded['data_elements'],
            ],
            'created_at' => now(),
        ]);
        CentralStreamEvent::pruneForClient($clientId);
        CentralStreamMessageReceived::dispatch($event);

        $serials = [];
        foreach (array_merge($decoded['aps'], $decoded['switches']) as $device) {
            if (($device['status'] ?? null) === ClassicMonitoringStreamDecoder::STATUS_UP
                && ($device['serial'] ?? '') !== '') {
                $serials[] = $device['serial'];
            }
        }

        foreach (array_values(array_unique($serials)) as $serial) {
            $cacheKey = "stream-up:{$clientId}:{$serial}";
            if (! Cache::add($cacheKey, true, 60)) {
                continue;
            }

            HandleCentralDeviceOnlineWakeJob::dispatch($clientId, $serial, OnlineDetectionMode::Stream->value)
                ->onQueue(JobQueueShard::resolve(null));
        }
    }
}
