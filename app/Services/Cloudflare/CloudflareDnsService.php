<?php

namespace App\Services\Cloudflare;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CloudflareDnsService
{
    /**
     * Create or update a proxied CNAME pointing hostname at {tunnelUuid}.cfargotunnel.com.
     * Picks the longest matching zone suffix so nested zone mistakes are avoided.
     *
     * @return array{ok: bool, message: string, record_name?: string, zone?: string}
     */
    public function upsertTunnelCname(string $hostname, string $tunnelUuid): array
    {
        $token = (string) config('services.cloudflare.api_token', env('CLOUDFLARE_API_TOKEN', ''));
        if ($token === '') {
            return ['ok' => false, 'message' => 'CLOUDFLARE_API_TOKEN is not configured.'];
        }

        $hostname = strtolower(trim($hostname));
        $target = $tunnelUuid.'.cfargotunnel.com';

        $zonesResponse = Http::withToken($token)
            ->acceptJson()
            ->get('https://api.cloudflare.com/client/v4/zones', [
                'per_page' => 50,
                'status' => 'active',
            ]);

        if (! $zonesResponse->successful() || ! ($zonesResponse->json('success') ?? false)) {
            return [
                'ok' => false,
                'message' => 'Failed to list Cloudflare zones: '.($zonesResponse->json('errors.0.message') ?? $zonesResponse->body()),
            ];
        }

        $zones = collect($zonesResponse->json('result') ?? [])
            ->map(fn (array $zone) => [
                'id' => (string) ($zone['id'] ?? ''),
                'name' => strtolower((string) ($zone['name'] ?? '')),
            ])
            ->filter(fn (array $zone) => $zone['id'] !== '' && $zone['name'] !== '')
            ->sortByDesc(fn (array $zone) => strlen($zone['name']))
            ->values();

        $matched = $zones->first(function (array $zone) use ($hostname) {
            return $hostname === $zone['name'] || Str::endsWith($hostname, '.'.$zone['name']);
        });

        if ($matched === null) {
            return [
                'ok' => false,
                'message' => "No Cloudflare zone matches hostname {$hostname}.",
            ];
        }

        $zoneId = $matched['id'];
        $zoneName = $matched['name'];
        $recordName = $hostname === $zoneName ? '@' : Str::beforeLast($hostname, '.'.$zoneName);

        $list = Http::withToken($token)
            ->acceptJson()
            ->get("https://api.cloudflare.com/client/v4/zones/{$zoneId}/dns_records", [
                'type' => 'CNAME',
                'name' => $hostname,
            ]);

        if (! $list->successful() || ! ($list->json('success') ?? false)) {
            return [
                'ok' => false,
                'message' => 'Failed to list DNS records: '.($list->json('errors.0.message') ?? $list->body()),
            ];
        }

        $existing = collect($list->json('result') ?? [])->first();
        $payload = [
            'type' => 'CNAME',
            'name' => $recordName,
            'content' => $target,
            'proxied' => true,
            'ttl' => 1,
        ];

        if (is_array($existing) && isset($existing['id'])) {
            $response = Http::withToken($token)
                ->acceptJson()
                ->put("https://api.cloudflare.com/client/v4/zones/{$zoneId}/dns_records/{$existing['id']}", $payload);
        } else {
            $response = Http::withToken($token)
                ->acceptJson()
                ->post("https://api.cloudflare.com/client/v4/zones/{$zoneId}/dns_records", $payload);
        }

        if (! $response->successful() || ! ($response->json('success') ?? false)) {
            return [
                'ok' => false,
                'message' => 'Failed to upsert DNS CNAME: '.($response->json('errors.0.message') ?? $response->body()),
            ];
        }

        return [
            'ok' => true,
            'message' => "CNAME {$hostname} → {$target} created in zone {$zoneName}.",
            'record_name' => $hostname,
            'zone' => $zoneName,
        ];
    }
}
