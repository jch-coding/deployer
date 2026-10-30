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

    private const SEVERITIES = ['positive', 'negative', 'neutral'];

    private const EXPORT_MAX_EVENTS = 10000;

    public function index(
        Request $request,
        CentralScopeCacheService $centralScopeCacheService,
    ) {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            session()->flash('error', 'Please set current client to view events');

            return to_route('clients.index');
        }

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

        return Inertia::render('Events/Index', [
            'events' => [],
            'filters' => [
                'context_type' => '',
                'context_identifier' => '',
                'site_id' => '',
                'start_at' => '',
                'end_at' => '',
                'filter_categories' => [],
                'filter_source_types' => [],
                'filter_event_ids' => [],
            ],
            'site_options' => $siteOptions,
            'device_options' => $deviceOptionsPayload['devices'],
            'context_type_options' => self::CONTEXT_TYPES,
            'category_options' => EventsODataFilterBuilder::CATEGORIES,
            'source_type_options' => EventsODataFilterBuilder::SOURCE_TYPES,
            'event_id_options' => EventsODataFilterBuilder::EVENT_IDS,
            'central_error' => $centralError,
            'max_events' => self::EXPORT_MAX_EVENTS,
            'default_start_at' => Carbon::now('UTC')->subDay()->format('Y-m-d\TH:i:s.000\Z'),
            'default_end_at' => Carbon::now('UTC')->format('Y-m-d\TH:i:s.000\Z'),
            ...$centralScopeCacheService->getCacheMetadata($currentClient),
        ]);
    }

    public function page(Request $request, EventsODataFilterBuilder $filterBuilder): JsonResponse
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            return response()->json(['message' => 'No current client selected.'], 403);
        }

        $validated = $request->validate([
            'context_type' => ['required', 'string', Rule::in(self::CONTEXT_TYPES)],
            'context_identifier' => ['required', 'string', 'max:128'],
            'site_id' => ['required', 'string', 'max:128'],
            'start_at' => ['required', 'string', 'max:30'],
            'end_at' => ['required', 'string', 'max:30'],
            'next' => ['nullable', 'string', 'max:128'],
            'filter_categories' => ['nullable', 'array'],
            'filter_categories.*' => ['string', 'max:128', Rule::in(EventsODataFilterBuilder::CATEGORIES)],
            'filter_source_types' => ['nullable', 'array'],
            'filter_source_types.*' => ['string', 'max:128', Rule::in(EventsODataFilterBuilder::SOURCE_TYPES)],
            'filter_event_ids' => ['nullable', 'array'],
            'filter_event_ids.*' => ['string', 'max:128'],
        ]);

        $filterCategories = $this->normalizeStringList($validated['filter_categories'] ?? []);
        $filterSourceTypes = $this->normalizeStringList($validated['filter_source_types'] ?? []);
        $filterEventIds = $this->normalizeStringList($validated['filter_event_ids'] ?? []);

        $odata = $filterBuilder->build([
            'eventId' => $filterEventIds,
            'category' => $filterCategories,
            'sourceType' => $filterSourceTypes,
        ]);

        if ($odata['error'] !== null) {
            return response()->json([
                'events' => [],
                'next' => null,
                'total' => null,
                'count' => 0,
                'error' => $odata['error'],
            ], 422);
        }

        $query = [
            'context-type' => trim((string) $validated['context_type']),
            'context-identifier' => trim((string) $validated['context_identifier']),
            'site-id' => trim((string) $validated['site_id']),
            'start-at' => trim((string) $validated['start_at']),
            'end-at' => trim((string) $validated['end_at']),
            'limit' => 1000,
        ];

        if ($odata['filter'] !== null) {
            $query['filter'] = $odata['filter'];
        }

        $next = trim((string) ($validated['next'] ?? ''));
        if ($next !== '') {
            $query['next'] = $next;
        }

        $helper = new CentralAPIHelper($currentClient);
        $response = $helper->get_events($query);

        if (is_array($response)) {
            return response()->json([
                'events' => [],
                'next' => null,
                'total' => null,
                'count' => 0,
                'error' => (string) ($response['error'] ?? 'Failed to fetch events from Central.'),
            ], 502);
        }

        $rawEvents = $response->json('events', []);
        if (! is_array($rawEvents)) {
            $rawEvents = [];
        }

        $events = array_values(array_map(
            fn (mixed $item): array => $this->mapEvent(is_array($item) ? $item : []),
            $rawEvents,
        ));

        $nextValue = $response->json('next');
        $total = $response->json('total');
        $count = $response->json('count');

        return response()->json([
            'events' => $events,
            'next' => is_string($nextValue) && $nextValue !== '' ? $nextValue : null,
            'total' => is_numeric($total) ? (int) $total : null,
            'count' => is_numeric($count) ? (int) $count : count($events),
            'error' => null,
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

    public function export(Request $request, EventsODataFilterBuilder $filterBuilder): JsonResponse
    {
        $currentClient = $request->user()->currentClient();

        if (! $currentClient) {
            return response()->json(['message' => 'No current client selected.'], 403);
        }

        $validated = $request->validate([
            'context_type' => ['required', 'string', Rule::in(self::CONTEXT_TYPES)],
            'context_identifier' => ['required', 'string', 'max:128'],
            'site_id' => ['required', 'string', 'max:128'],
            'start_at' => ['required', 'string', 'max:30'],
            'end_at' => ['required', 'string', 'max:30'],
            'limit' => ['nullable', 'integer', Rule::in(self::LIMITS)],
            'severity' => ['nullable', 'string', Rule::in(self::SEVERITIES)],
            'filter_categories' => ['nullable', 'array'],
            'filter_categories.*' => ['string', 'max:128', Rule::in(EventsODataFilterBuilder::CATEGORIES)],
            'filter_source_types' => ['nullable', 'array'],
            'filter_source_types.*' => ['string', 'max:128', Rule::in(EventsODataFilterBuilder::SOURCE_TYPES)],
            'filter_event_ids' => ['nullable', 'array'],
            'filter_event_ids.*' => ['string', 'max:128'],
        ]);

        $severity = strtolower(trim((string) ($validated['severity'] ?? '')));
        $filterCategories = $this->normalizeStringList($validated['filter_categories'] ?? []);
        $filterSourceTypes = $this->normalizeStringList($validated['filter_source_types'] ?? []);
        $filterEventIds = $this->normalizeStringList($validated['filter_event_ids'] ?? []);

        $odata = $filterBuilder->build([
            'eventId' => $filterEventIds,
            'category' => $filterCategories,
            'sourceType' => $filterSourceTypes,
        ]);

        if ($odata['error'] !== null) {
            return response()->json([
                'events' => [],
                'truncated' => false,
                'fetched_count' => 0,
                'error' => $odata['error'],
            ], 422);
        }

        $query = [
            'context-type' => trim((string) $validated['context_type']),
            'context-identifier' => trim((string) $validated['context_identifier']),
            'site-id' => trim((string) $validated['site_id']),
            'start-at' => trim((string) $validated['start_at']),
            'end-at' => trim((string) $validated['end_at']),
            'limit' => (int) ($validated['limit'] ?? 1000),
        ];

        if ($odata['filter'] !== null) {
            $query['filter'] = $odata['filter'];
        }

        $helper = new CentralAPIHelper($currentClient);
        $payload = $helper->get_all_events($query, self::EXPORT_MAX_EVENTS);

        if ($payload['error'] !== null) {
            return response()->json([
                'events' => [],
                'truncated' => false,
                'fetched_count' => 0,
                'error' => $payload['error'],
            ], 502);
        }

        $events = array_values(array_map(
            fn (mixed $item): array => $this->mapEvent(is_array($item) ? $item : []),
            $payload['events'],
        ));

        $fetchedCount = count($events);

        if ($severity !== '') {
            $events = array_values(array_filter(
                $events,
                fn (array $event): bool => strtolower(trim($event['severity'])) === $severity,
            ));
        }

        return response()->json([
            'events' => $events,
            'truncated' => (bool) $payload['truncated'],
            'fetched_count' => $fetchedCount,
            'error' => null,
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
