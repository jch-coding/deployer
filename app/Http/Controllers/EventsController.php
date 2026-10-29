<?php

namespace App\Http\Controllers;

use App\Helper\CentralAPIHelper;
use App\Services\CentralScopeCacheService;
use App\Services\EventsODataFilterBuilder;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class EventsController extends Controller
{
    private const CONTEXT_TYPES = [
        'SITE',
        'ACCESS_POINT',
        'SWITCH',
        'GATEWAY',
        'WIRELESS_CLIENT',
        'WIRED_CLIENT',
        'BRIDGE',
    ];

    private const LIMITS = [25, 50, 100, 250, 500, 1000];

    private const CLIENT_CONNECTION_TYPES = [
        'WIRELESS_CLIENT' => 'Wireless',
        'WIRED_CLIENT' => 'Wired',
    ];

    public function index(
        Request $request,
        CentralScopeCacheService $centralScopeCacheService,
        EventsODataFilterBuilder $filterBuilder,
    ) {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            session()->flash('error', 'Please set current client to view events');

            return to_route('clients.index');
        }

        $validated = $request->validate([
            'context_type' => ['nullable', 'string', Rule::in(self::CONTEXT_TYPES)],
            'context_identifier' => ['nullable', 'string', 'max:128'],
            'site_id' => ['nullable', 'string', 'max:128'],
            'start_at' => ['nullable', 'string', 'max:30'],
            'end_at' => ['nullable', 'string', 'max:30'],
            'limit' => ['nullable', 'integer', Rule::in(self::LIMITS)],
            'next' => ['nullable', 'string', 'max:128'],
            'submitted' => ['nullable', 'boolean'],
            'filter_categories' => ['nullable', 'array'],
            'filter_categories.*' => ['string', 'max:128', Rule::in(EventsODataFilterBuilder::CATEGORIES)],
            'filter_source_types' => ['nullable', 'array'],
            'filter_source_types.*' => ['string', 'max:128', Rule::in(EventsODataFilterBuilder::SOURCE_TYPES)],
            'filter_event_ids' => ['nullable', 'array'],
            'filter_event_ids.*' => ['string', 'max:128'],
        ]);

        $filters = [
            'context_type' => trim((string) ($validated['context_type'] ?? '')),
            'context_identifier' => trim((string) ($validated['context_identifier'] ?? '')),
            'site_id' => trim((string) ($validated['site_id'] ?? '')),
            'start_at' => trim((string) ($validated['start_at'] ?? '')),
            'end_at' => trim((string) ($validated['end_at'] ?? '')),
            'limit' => (int) ($validated['limit'] ?? 100),
            'next' => trim((string) ($validated['next'] ?? '')),
            'filter_categories' => $this->normalizeStringList($validated['filter_categories'] ?? []),
            'filter_source_types' => $this->normalizeStringList($validated['filter_source_types'] ?? []),
            'filter_event_ids' => $this->normalizeStringList($validated['filter_event_ids'] ?? []),
        ];

        $submitted = (bool) ($validated['submitted'] ?? false);

        $helper = new CentralAPIHelper($currentClient);
        $sitesPayload = $centralScopeCacheService->getSites($currentClient);
        $deviceOptionsPayload = $helper->collectCentralEventContextOptions();

        $siteOptions = array_map(
            fn (array $site): array => [
                'siteId' => $site['scopeId'],
                'siteName' => $site['scopeName'],
            ],
            $sitesPayload['sites'],
        );

        $centralError = $sitesPayload['error'] ?? $deviceOptionsPayload['error'];
        $events = [];
        $next = null;
        $total = null;
        $count = null;

        if ($submitted) {
            $requiredMissing = $filters['context_type'] === ''
                || $filters['context_identifier'] === ''
                || $filters['site_id'] === ''
                || $filters['start_at'] === ''
                || $filters['end_at'] === '';

            if ($requiredMissing) {
                $centralError = 'All required fields must be filled before searching events.';
            } elseif ($centralError === null) {
                $odata = $filterBuilder->build([
                    'eventId' => $filters['filter_event_ids'],
                    'category' => $filters['filter_categories'],
                    'sourceType' => $filters['filter_source_types'],
                ]);

                if ($odata['error'] !== null) {
                    $centralError = $odata['error'];
                } else {
                    $query = [
                        'context-type' => $filters['context_type'],
                        'context-identifier' => $filters['context_identifier'],
                        'site-id' => $filters['site_id'],
                        'start-at' => $filters['start_at'],
                        'end-at' => $filters['end_at'],
                        'limit' => $filters['limit'],
                    ];

                    if ($odata['filter'] !== null) {
                        $query['filter'] = $odata['filter'];
                    }

                    if ($filters['next'] !== '') {
                        $query['next'] = $filters['next'];
                    }

                    $response = $helper->get_events($query);

                    if (is_array($response)) {
                        $centralError = (string) ($response['error'] ?? 'Failed to fetch events from Central.');
                    } else {
                        $rawEvents = $response->json('events', []);
                        if (! is_array($rawEvents)) {
                            $rawEvents = [];
                        }

                        $events = array_values(array_map(
                            fn (mixed $item): array => $this->mapEvent(is_array($item) ? $item : []),
                            $rawEvents,
                        ));

                        $nextValue = $response->json('next');
                        $next = is_string($nextValue) && $nextValue !== '' ? $nextValue : null;
                        $total = $response->json('total');
                        $count = $response->json('count');
                    }
                }
            }
        }

        return Inertia::render('Events/Index', [
            'events' => $events,
            'filters' => $filters,
            'site_options' => $siteOptions,
            'device_options' => $deviceOptionsPayload['devices'],
            'context_type_options' => self::CONTEXT_TYPES,
            'limit_options' => self::LIMITS,
            'category_options' => EventsODataFilterBuilder::CATEGORIES,
            'source_type_options' => EventsODataFilterBuilder::SOURCE_TYPES,
            'central_error' => $centralError,
            'next_cursor' => $next,
            'total' => is_numeric($total) ? (int) $total : null,
            'count' => is_numeric($count) ? (int) $count : null,
            'submitted' => $submitted,
            'default_start_at' => Carbon::now('UTC')->subDay()->format('Y-m-d\TH:i:s.000\Z'),
            'default_end_at' => Carbon::now('UTC')->format('Y-m-d\TH:i:s.000\Z'),
            ...$centralScopeCacheService->getCacheMetadata($currentClient),
        ]);
    }

    public function clients(Request $request): JsonResponse
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            return response()->json(['message' => 'No current client selected.'], 403);
        }

        $validated = $request->validate([
            'context_type' => ['required', 'string', Rule::in(array_keys(self::CLIENT_CONNECTION_TYPES))],
            'site_id' => ['nullable', 'string', 'max:128'],
        ]);

        $connectionType = self::CLIENT_CONNECTION_TYPES[$validated['context_type']];
        $helper = new CentralAPIHelper($currentClient);

        $payload = $helper->collectCentralClientOptions([
            'connection_type' => $connectionType,
            'site_id' => trim((string) ($validated['site_id'] ?? '')),
            'limit' => 1000,
        ]);

        return response()->json([
            'clients' => $payload['clients'],
            'error' => $payload['error'],
        ]);
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    private function normalizeStringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = [];

        foreach ($values as $value) {
            $trimmed = trim((string) $value);
            if ($trimmed === '' || in_array($trimmed, $normalized, true)) {
                continue;
            }

            $normalized[] = $trimmed;
        }

        return array_values($normalized);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{
     *     eventId: string,
     *     eventIdentifier: string,
     *     timeAt: string,
     *     eventName: string,
     *     category: string,
     *     severity: string,
     *     sourceType: string,
     *     sourceName: string,
     *     serialNumber: string,
     *     description: string,
     *     reason: string,
     *     clientMacAddress: string,
     *     deviceMacAddress: string
     * }
     */
    private function mapEvent(array $item): array
    {
        return [
            'eventId' => (string) ($item['eventId'] ?? ''),
            'eventIdentifier' => (string) ($item['eventIdentifier'] ?? ''),
            'timeAt' => (string) ($item['timeAt'] ?? ''),
            'eventName' => (string) ($item['eventName'] ?? ''),
            'category' => (string) ($item['category'] ?? ''),
            'severity' => (string) ($item['severity'] ?? ''),
            'sourceType' => (string) ($item['sourceType'] ?? ''),
            'sourceName' => (string) ($item['sourceName'] ?? ''),
            'serialNumber' => (string) ($item['serialNumber'] ?? ''),
            'description' => (string) ($item['description'] ?? ''),
            'reason' => (string) ($item['reason'] ?? ''),
            'clientMacAddress' => (string) ($item['clientMacAddress'] ?? ''),
            'deviceMacAddress' => (string) ($item['deviceMacAddress'] ?? ''),
        ];
    }
}
