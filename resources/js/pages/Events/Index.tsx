import { router, usePage } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { Loader2, Search } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import CentralScopeRefreshButtons, {
    type CentralScopeCacheMeta,
    type CentralScopeGroupsCacheMeta,
} from '@/components/central/CentralScopeRefreshButtons';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import { Input } from '@/components/ui/input';
import { SearchableSelect } from '@/components/ui/searchable-select';
import AppLayout from '@/layouts/app-layout';
import { csrfHeaders } from '@/lib/csrf';
import { clients as eventsClients, index as eventsIndex } from '@/routes/events';
import { index as clientsIndex } from '@/routes/clients';
import type { BreadcrumbItem, SharedData } from '@/types';

type SiteOption = {
    siteId: string;
    siteName: string;
};

type DeviceOption = {
    serial: string;
    name: string;
    device_type: string;
    device_function: string;
    site_id: string;
};

type ClientOption = {
    mac: string;
    name: string;
    connection_type: string;
    site_id: string;
};

type EventRow = {
    eventId: string;
    eventIdentifier: string;
    timeAt: string;
    eventName: string;
    category: string;
    severity: string;
    sourceType: string;
    sourceName: string;
    serialNumber: string;
    description: string;
    reason: string;
    clientMacAddress: string;
    deviceMacAddress: string;
};

type EventsFilters = {
    context_type: string;
    context_identifier: string;
    site_id: string;
    start_at: string;
    end_at: string;
    limit: number;
    next: string;
};

type EventsIndexProps = {
    events: EventRow[];
    filters: EventsFilters;
    site_options: SiteOption[];
    device_options: DeviceOption[];
    context_type_options: string[];
    limit_options: number[];
    central_error: string | null;
    next_cursor: string | null;
    total: number | null;
    count: number | null;
    submitted: boolean;
    default_start_at: string;
    default_end_at: string;
    central_sites_cache: CentralScopeCacheMeta;
    central_groups_cache: CentralScopeGroupsCacheMeta;
} & SharedData;

const DEVICE_CONTEXT_TYPES = new Set([
    'ACCESS_POINT',
    'SWITCH',
    'GATEWAY',
    'BRIDGE',
]);

const CLIENT_CONTEXT_TYPES = new Set(['WIRELESS_CLIENT', 'WIRED_CLIENT']);

const SEVERITY_OPTIONS = ['positive', 'negative', 'neutral'] as const;

type SeverityFilter = '' | (typeof SEVERITY_OPTIONS)[number];

const selectClassName =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs';

function toDatetimeLocalValue(rfc3339: string): string {
    const date = new Date(rfc3339);
    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const pad = (value: number) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function fromDatetimeLocalValue(localValue: string): string {
    const date = new Date(localValue);
    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return date.toISOString();
}

function matchesDeviceContextType(
    deviceType: string,
    contextType: string,
): boolean {
    const normalized = deviceType.trim().toUpperCase();
    const expected = contextType.trim().toUpperCase();

    if (expected === 'ACCESS_POINT') {
        return (
            normalized === 'ACCESS_POINT' ||
            normalized === 'AP' ||
            normalized === 'IAP'
        );
    }

    return normalized === expected;
}

function severityBadgeClass(severity: string): string {
    switch (severity.trim().toLowerCase()) {
        case 'positive':
            return 'bg-emerald-100 text-emerald-800 border-emerald-200';
        case 'negative':
            return 'bg-red-100 text-red-800 border-red-200';
        case 'neutral':
            return 'bg-slate-100 text-slate-800 border-slate-200';
        default:
            return '';
    }
}

function validateTimeWindow(
    startAt: string,
    endAt: string,
): string | null {
    const start = new Date(startAt);
    const end = new Date(endAt);

    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) {
        return 'Start and end times must be valid dates.';
    }

    if (end <= start) {
        return 'End time must be after start time.';
    }

    const maxSpanMs = 30 * 24 * 60 * 60 * 1000;
    if (end.getTime() - start.getTime() > maxSpanMs) {
        return 'The time window must be 30 days or less.';
    }

    return null;
}

export default function Index() {
    const {
        current_client,
        events,
        filters,
        site_options,
        device_options,
        context_type_options,
        limit_options,
        central_error,
        next_cursor,
        total,
        count,
        submitted,
        default_start_at,
        default_end_at,
        central_sites_cache,
        central_groups_cache,
    } = usePage<EventsIndexProps>().props;

    const [localFilters, setLocalFilters] = useState<EventsFilters>(() => ({
        ...filters,
        start_at: filters.start_at || default_start_at,
        end_at: filters.end_at || default_end_at,
        limit: filters.limit || 100,
    }));
    const [startLocal, setStartLocal] = useState(() =>
        toDatetimeLocalValue(filters.start_at || default_start_at),
    );
    const [endLocal, setEndLocal] = useState(() =>
        toDatetimeLocalValue(filters.end_at || default_end_at),
    );
    const [isSearching, setIsSearching] = useState(false);
    const [clientOptions, setClientOptions] = useState<ClientOption[]>([]);
    const [clientsLoading, setClientsLoading] = useState(false);
    const [clientsError, setClientsError] = useState<string | null>(null);
    const [validationError, setValidationError] = useState<string | null>(null);
    const [pageSize, setPageSize] = useState<10 | 25 | 50 | 100>(25);
    const [pageIndex, setPageIndex] = useState(0);
    const [severityFilter, setSeverityFilter] = useState<SeverityFilter>('');

    useEffect(() => {
        setLocalFilters({
            ...filters,
            start_at: filters.start_at || default_start_at,
            end_at: filters.end_at || default_end_at,
            limit: filters.limit || 100,
        });
        setStartLocal(
            toDatetimeLocalValue(filters.start_at || default_start_at),
        );
        setEndLocal(toDatetimeLocalValue(filters.end_at || default_end_at));
        setIsSearching(false);
    }, [filters, default_start_at, default_end_at]);

    useEffect(() => {
        setPageIndex(0);
    }, [events, pageSize, severityFilter]);

    const updateFilter = useCallback((patch: Partial<EventsFilters>) => {
        setLocalFilters((prev) => ({ ...prev, ...patch }));
    }, []);

    const loadClients = useCallback(
        async (contextType: string, siteId: string) => {
            if (!CLIENT_CONTEXT_TYPES.has(contextType)) {
                setClientOptions([]);
                setClientsError(null);
                return;
            }

            setClientsLoading(true);
            setClientsError(null);

            try {
                const response = await fetch(
                    eventsClients.url({
                        query: {
                            context_type: contextType,
                            ...(siteId.trim() !== ''
                                ? { site_id: siteId.trim() }
                                : {}),
                        },
                    }),
                    {
                        method: 'GET',
                        headers: {
                            Accept: 'application/json',
                            ...csrfHeaders(),
                        },
                        credentials: 'same-origin',
                    },
                );

                if (!response.ok) {
                    setClientOptions([]);
                    setClientsError('Failed to load clients from Central.');
                    return;
                }

                const payload = (await response.json()) as {
                    clients?: ClientOption[];
                    error?: string | null;
                };

                setClientOptions(
                    Array.isArray(payload.clients) ? payload.clients : [],
                );
                setClientsError(payload.error ?? null);
            } catch {
                setClientOptions([]);
                setClientsError('Failed to load clients from Central.');
            } finally {
                setClientsLoading(false);
            }
        },
        [],
    );

    useEffect(() => {
        if (!CLIENT_CONTEXT_TYPES.has(localFilters.context_type)) {
            setClientOptions([]);
            setClientsError(null);
            return;
        }

        void loadClients(localFilters.context_type, localFilters.site_id);
    }, [
        localFilters.context_type,
        localFilters.site_id,
        loadClients,
    ]);

    const handleContextTypeChange = useCallback(
        (contextType: string) => {
            updateFilter({
                context_type: contextType,
                context_identifier: '',
                next: '',
            });
        },
        [updateFilter],
    );

    const handleContextIdentifierChange = useCallback(
        (value: string) => {
            if (localFilters.context_type === 'SITE') {
                updateFilter({
                    context_identifier: value,
                    site_id: value,
                    next: '',
                });
                return;
            }

            if (DEVICE_CONTEXT_TYPES.has(localFilters.context_type)) {
                const device = device_options.find((d) => d.serial === value);
                updateFilter({
                    context_identifier: value,
                    site_id:
                        device?.site_id?.trim() !== ''
                            ? (device?.site_id ?? localFilters.site_id)
                            : localFilters.site_id,
                    next: '',
                });
                return;
            }

            if (CLIENT_CONTEXT_TYPES.has(localFilters.context_type)) {
                const client = clientOptions.find((c) => c.mac === value);
                updateFilter({
                    context_identifier: value,
                    site_id:
                        client?.site_id?.trim() !== ''
                            ? (client?.site_id ?? localFilters.site_id)
                            : localFilters.site_id,
                    next: '',
                });
                return;
            }

            updateFilter({ context_identifier: value, next: '' });
        },
        [
            clientOptions,
            device_options,
            localFilters.context_type,
            localFilters.site_id,
            updateFilter,
        ],
    );

    const handleSiteIdChange = useCallback(
        (siteId: string) => {
            if (localFilters.context_type === 'SITE') {
                updateFilter({
                    site_id: siteId,
                    context_identifier: siteId,
                    next: '',
                });
                return;
            }

            updateFilter({ site_id: siteId, next: '' });
        },
        [localFilters.context_type, updateFilter],
    );

    const siteSelectOptions = useMemo(
        () =>
            site_options.map((site) => ({
                value: site.siteId,
                label: `${site.siteName} (${site.siteId})`,
            })),
        [site_options],
    );

    const contextIdentifierOptions = useMemo(() => {
        if (localFilters.context_type === 'SITE') {
            return siteSelectOptions;
        }

        if (DEVICE_CONTEXT_TYPES.has(localFilters.context_type)) {
            return device_options
                .filter((device) =>
                    matchesDeviceContextType(
                        device.device_type,
                        localFilters.context_type,
                    ),
                )
                .map((device) => ({
                    value: device.serial,
                    label: device.name
                        ? `${device.name} (${device.serial})`
                        : device.serial,
                }));
        }

        if (CLIENT_CONTEXT_TYPES.has(localFilters.context_type)) {
            const options = clientOptions.map((client) => ({
                value: client.mac,
                label: client.name
                    ? `${client.name} (${client.mac})`
                    : client.mac,
            }));

            const current = localFilters.context_identifier.trim();
            if (
                current !== '' &&
                !options.some((option) => option.value === current)
            ) {
                options.unshift({ value: current, label: current });
            }

            return options;
        }

        return [];
    }, [
        clientOptions,
        device_options,
        localFilters.context_identifier,
        localFilters.context_type,
        siteSelectOptions,
    ]);

    const canSearch = useMemo(() => {
        return (
            localFilters.context_type.trim() !== '' &&
            localFilters.context_identifier.trim() !== '' &&
            localFilters.site_id.trim() !== '' &&
            startLocal.trim() !== '' &&
            endLocal.trim() !== ''
        );
    }, [localFilters, startLocal, endLocal]);

    const submitSearch = useCallback(
        (nextCursor: string | null = null) => {
            if (isSearching) {
                return;
            }

            const startAt = fromDatetimeLocalValue(startLocal);
            const endAt = fromDatetimeLocalValue(endLocal);
            const timeError = validateTimeWindow(startAt, endAt);
            if (timeError) {
                setValidationError(timeError);
                return;
            }

            if (
                localFilters.context_type.trim() === '' ||
                localFilters.context_identifier.trim() === '' ||
                localFilters.site_id.trim() === ''
            ) {
                setValidationError(
                    'Context type, context identifier, and site are required.',
                );
                return;
            }

            setValidationError(null);
            setIsSearching(true);

            router.get(
                eventsIndex.url({
                    query: {
                        context_type: localFilters.context_type,
                        context_identifier: localFilters.context_identifier,
                        site_id: localFilters.site_id,
                        start_at: startAt,
                        end_at: endAt,
                        limit: String(localFilters.limit),
                        ...(nextCursor ? { next: nextCursor } : {}),
                        submitted: '1',
                    },
                }),
                {},
                {
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                    only: [
                        'events',
                        'filters',
                        'central_error',
                        'next_cursor',
                        'total',
                        'count',
                        'submitted',
                    ],
                    onFinish: () => setIsSearching(false),
                },
            );
        },
        [isSearching, localFilters, startLocal, endLocal],
    );

    const columns = useMemo<ColumnDef<EventRow>[]>(
        () => [
            {
                accessorKey: 'timeAt',
                header: 'Time',
                cell: ({ row }) => {
                    const value = row.original.timeAt;
                    const date = new Date(value);
                    if (Number.isNaN(date.getTime())) {
                        return value || '—';
                    }

                    return date.toLocaleString();
                },
            },
            { accessorKey: 'eventName', header: 'Event' },
            { accessorKey: 'category', header: 'Category' },
            {
                accessorKey: 'severity',
                header: 'Severity',
                cell: ({ row }) => {
                    const severity = row.original.severity;
                    if (!severity) {
                        return '—';
                    }

                    return (
                        <Badge
                            variant="outline"
                            className={severityBadgeClass(severity)}
                        >
                            {severity}
                        </Badge>
                    );
                },
            },
            { accessorKey: 'sourceType', header: 'Source type' },
            { accessorKey: 'sourceName', header: 'Source' },
            { accessorKey: 'serialNumber', header: 'Serial' },
            {
                id: 'details',
                header: 'Details',
                cell: ({ row }) => {
                    const description = row.original.description.trim();
                    const reason = row.original.reason.trim();
                    if (!description && !reason) {
                        return '—';
                    }

                    return (
                        <span className="line-clamp-2 max-w-md text-muted-foreground">
                            {description || reason}
                            {description && reason ? ` (${reason})` : ''}
                        </span>
                    );
                },
            },
        ],
        [],
    );

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: current_client?.name ?? 'Clients',
            href: clientsIndex().url,
        },
        {
            title: 'Events',
            href: eventsIndex().url,
        },
    ];

    const filteredEvents = useMemo(() => {
        if (!severityFilter) {
            return events;
        }

        return events.filter(
            (event) =>
                event.severity.trim().toLowerCase() === severityFilter,
        );
    }, [events, severityFilter]);

    const totalEvents = filteredEvents.length;
    const totalPages = Math.max(1, Math.ceil(totalEvents / pageSize));
    const safePageIndex = Math.min(pageIndex, totalPages - 1);
    const start = safePageIndex * pageSize;
    const end = Math.min(start + pageSize, totalEvents);
    const pagedEvents = useMemo(
        () => filteredEvents.slice(start, end),
        [filteredEvents, end, start],
    );

    const isClientContext = CLIENT_CONTEXT_TYPES.has(localFilters.context_type);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="mx-auto max-w-7xl px-4">
                <h1 className="text-center text-3xl font-semibold">Events</h1>
                <p className="mt-2 text-center text-sm text-muted-foreground">
                    Query Central network events by context, site, and time
                    window.
                </p>

                <div className="mt-4 flex justify-center">
                    <CentralScopeRefreshButtons
                        centralSitesCache={central_sites_cache}
                        centralGroupsCache={central_groups_cache}
                        reloadOnly={[
                            'central_sites_cache',
                            'central_groups_cache',
                            'site_options',
                            'central_error',
                        ]}
                    />
                </div>

                {(central_error || validationError || clientsError) && (
                    <div
                        className="mt-4 rounded-md border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive"
                        role="alert"
                    >
                        {validationError || central_error || clientsError}
                    </div>
                )}

                <div className="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <select
                        value={localFilters.context_type}
                        onChange={(e) =>
                            handleContextTypeChange(e.target.value)
                        }
                        className={selectClassName}
                        aria-label="Context type"
                        data-test="events-filter-context-type"
                    >
                        <option value="">Select context type</option>
                        {context_type_options.map((type) => (
                            <option key={type} value={type}>
                                {type}
                            </option>
                        ))}
                    </select>

                    <SearchableSelect
                        value={localFilters.context_identifier || undefined}
                        onValueChange={handleContextIdentifierChange}
                        options={contextIdentifierOptions}
                        placeholder={
                            localFilters.context_type === ''
                                ? 'Select context type first'
                                : isClientContext
                                  ? clientsLoading
                                      ? 'Loading clients…'
                                      : 'Select or search client'
                                  : 'Select context identifier'
                        }
                        disabled={
                            localFilters.context_type === '' ||
                            (isClientContext && clientsLoading)
                        }
                        aria-label="Context identifier"
                        data-test="events-filter-context-identifier"
                    />

                    {isClientContext && (
                        <Input
                            type="search"
                            value={localFilters.context_identifier}
                            onChange={(e) =>
                                updateFilter({
                                    context_identifier: e.target.value,
                                    next: '',
                                })
                            }
                            placeholder="Or enter client MAC"
                            aria-label="Client MAC address"
                            data-test="events-filter-client-mac"
                        />
                    )}

                    <SearchableSelect
                        value={localFilters.site_id || undefined}
                        onValueChange={handleSiteIdChange}
                        options={siteSelectOptions}
                        placeholder="Select site"
                        aria-label="Site ID"
                        data-test="events-filter-site-id"
                    />

                    <Input
                        type="datetime-local"
                        value={startLocal}
                        onChange={(e) => {
                            setStartLocal(e.target.value);
                            updateFilter({ next: '' });
                        }}
                        aria-label="Start at"
                        data-test="events-filter-start-at"
                    />

                    <Input
                        type="datetime-local"
                        value={endLocal}
                        onChange={(e) => {
                            setEndLocal(e.target.value);
                            updateFilter({ next: '' });
                        }}
                        aria-label="End at"
                        data-test="events-filter-end-at"
                    />

                    <select
                        value={String(localFilters.limit)}
                        onChange={(e) =>
                            updateFilter({
                                limit: Number(e.target.value),
                                next: '',
                            })
                        }
                        className={selectClassName}
                        aria-label="Limit"
                        data-test="events-filter-limit"
                    >
                        {limit_options.map((limit) => (
                            <option key={limit} value={limit}>
                                {limit} events
                            </option>
                        ))}
                    </select>
                </div>

                <div className="mt-4 flex flex-wrap items-center justify-center gap-3">
                    <Button
                        type="button"
                        onClick={() => submitSearch(null)}
                        disabled={!canSearch || isSearching}
                        data-test="events-search"
                    >
                        {isSearching ? (
                            <>
                                <Loader2 className="size-4 animate-spin" />
                                Searching…
                            </>
                        ) : (
                            <>
                                <Search className="size-4" />
                                Search
                            </>
                        )}
                    </Button>
                </div>

                {submitted && (
                    <div className="mt-8">
                        <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                            <p className="text-sm text-muted-foreground">
                                {events.length === 0
                                    ? 'No events found for this query.'
                                    : totalEvents === 0
                                      ? `No ${severityFilter} events in the loaded results.`
                                      : `Showing ${start + 1}–${end} of ${totalEvents} loaded event${totalEvents === 1 ? '' : 's'}${
                                            severityFilter
                                                ? ` (${severityFilter})`
                                                : ''
                                        }${
                                            total !== null
                                                ? ` (${total} total matching)`
                                                : ''
                                        }`}
                                {count !== null && events.length > 0
                                    ? ` · page count ${count}`
                                    : ''}
                            </p>
                            <div className="flex flex-wrap items-center gap-2">
                                <label
                                    htmlFor="events-severity-filter"
                                    className="text-sm text-muted-foreground"
                                >
                                    Severity
                                </label>
                                <select
                                    id="events-severity-filter"
                                    value={severityFilter}
                                    onChange={(e) =>
                                        setSeverityFilter(
                                            e.target.value as SeverityFilter,
                                        )
                                    }
                                    className={selectClassName + ' w-auto'}
                                    data-test="events-filter-severity"
                                >
                                    <option value="">All severities</option>
                                    {SEVERITY_OPTIONS.map((severity) => (
                                        <option
                                            key={severity}
                                            value={severity}
                                        >
                                            {severity}
                                        </option>
                                    ))}
                                </select>
                                <label
                                    htmlFor="events-page-size"
                                    className="text-sm text-muted-foreground"
                                >
                                    Rows
                                </label>
                                <select
                                    id="events-page-size"
                                    value={pageSize}
                                    onChange={(e) =>
                                        setPageSize(
                                            Number(e.target.value) as
                                                | 10
                                                | 25
                                                | 50
                                                | 100,
                                        )
                                    }
                                    className={selectClassName + ' w-auto'}
                                >
                                    {[10, 25, 50, 100].map((size) => (
                                        <option key={size} value={size}>
                                            {size}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        {totalEvents > 0 && (
                            <DataTable
                                columns={columns}
                                data={pagedEvents}
                                getRowId={(row) =>
                                    row.eventIdentifier ||
                                    `${row.eventId}-${row.timeAt}-${row.serialNumber}`
                                }
                            />
                        )}

                        <div className="mt-4 flex flex-wrap items-center justify-center gap-3">
                            <Button
                                type="button"
                                variant="outline"
                                disabled={safePageIndex <= 0}
                                onClick={() =>
                                    setPageIndex((prev) =>
                                        Math.max(0, prev - 1),
                                    )
                                }
                            >
                                Previous
                            </Button>
                            <span className="text-sm text-muted-foreground">
                                Page {safePageIndex + 1} of {totalPages}
                            </span>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={safePageIndex >= totalPages - 1}
                                onClick={() =>
                                    setPageIndex((prev) =>
                                        Math.min(totalPages - 1, prev + 1),
                                    )
                                }
                            >
                                Next
                            </Button>
                            {next_cursor && (
                                <Button
                                    type="button"
                                    variant="secondary"
                                    disabled={isSearching}
                                    onClick={() => submitSearch(next_cursor)}
                                    data-test="events-load-more"
                                >
                                    Load next page from Central
                                </Button>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
