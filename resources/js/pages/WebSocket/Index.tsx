import { usePage } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import type { ColumnDef } from '@tanstack/react-table';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import LaravelPaginator from '@/components/ui/LaravelPaginator';
import AppLayout from '@/layouts/app-layout';
import { index as clientsIndex } from '@/routes/clients';
import { index as streamingIndex } from '@/routes/streaming';
import type { BreadcrumbItem, SharedData } from '@/types';
import type { Paginator } from '@/types/deployer';

type DeviceState = {
    serial: string;
    status: number;
};

type StreamDecoded = {
    aps?: DeviceState[];
    switches?: DeviceState[];
    data_elements?: number[];
};

type StreamEventRow = {
    id: number;
    subject: string | null;
    customer_id: string | null;
    timestamp: number | null;
    decoded: StreamDecoded;
    created_at: string | null;
    human_created_at: string | null;
};

type WebSocketIndexProps = {
    events: Paginator<StreamEventRow>;
} & SharedData;

const STATUS_UP = 1;

function statusLabel(status: number): string {
    return status === STATUS_UP ? 'UP' : 'DOWN';
}

function statusBadgeClass(status: number): string {
    return status === STATUS_UP
        ? 'bg-emerald-100 text-emerald-800 border-emerald-200'
        : 'bg-red-100 text-red-800 border-red-200';
}

function DeviceSummary({ devices, label }: { devices: DeviceState[]; label: string }) {
    if (devices.length === 0) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <div className="flex flex-wrap gap-1">
            {devices.map((device) => (
                <Badge
                    key={`${label}-${device.serial}`}
                    variant="outline"
                    className={statusBadgeClass(device.status)}
                    title={`${label}: ${device.serial}`}
                >
                    {device.serial} {statusLabel(device.status)}
                </Badge>
            ))}
        </div>
    );
}

export default function Index() {
    const { current_client, events } = usePage<WebSocketIndexProps>().props;
    const [rows, setRows] = useState<StreamEventRow[]>(events.data);
    const [expandedId, setExpandedId] = useState<number | null>(null);

    useEffect(() => {
        setRows(events.data);
    }, [events.data]);

    const onStreamReceived = useCallback((payload: StreamEventRow) => {
        setRows((prev) => {
            if (prev.some((row) => row.id === payload.id)) {
                return prev;
            }

            return [payload, ...prev].slice(0, events.per_page);
        });
    }, [events.per_page]);

    useEcho<StreamEventRow>(
        current_client ? `clients.${current_client.id}.streaming` : '',
        '.CentralStreamMessageReceived',
        onStreamReceived,
        [current_client?.id, onStreamReceived],
    );

    const columns = useMemo<ColumnDef<StreamEventRow>[]>(
        () => [
            {
                accessorKey: 'human_created_at',
                header: 'Received',
                cell: ({ row }) => (
                    <span title={row.original.created_at ?? undefined}>
                        {row.original.human_created_at ?? row.original.created_at ?? '—'}
                    </span>
                ),
            },
            {
                accessorKey: 'subject',
                header: 'Subject',
                cell: ({ row }) => row.original.subject ?? '—',
            },
            {
                accessorKey: 'customer_id',
                header: 'Customer',
                cell: ({ row }) => row.original.customer_id ?? '—',
            },
            {
                id: 'aps',
                header: 'APs',
                cell: ({ row }) => (
                    <DeviceSummary devices={row.original.decoded?.aps ?? []} label="AP" />
                ),
            },
            {
                id: 'switches',
                header: 'Switches',
                cell: ({ row }) => (
                    <DeviceSummary devices={row.original.decoded?.switches ?? []} label="Switch" />
                ),
            },
            {
                id: 'decoded',
                header: 'Decoded',
                cell: ({ row }) => (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            setExpandedId((current) =>
                                current === row.original.id ? null : row.original.id,
                            )
                        }
                    >
                        {expandedId === row.original.id ? 'Hide' : 'View'}
                    </Button>
                ),
            },
        ],
        [expandedId],
    );

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: current_client?.name ?? 'Clients',
            href: clientsIndex().url,
        },
        {
            title: 'WebSocket',
            href: streamingIndex().url,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="mx-auto max-w-7xl px-4">
                <h1 className="text-center text-3xl font-semibold">WebSocket</h1>
                <p className="mt-2 text-center text-sm text-muted-foreground">
                    Decoded Classic Central streaming monitoring messages for the current client
                </p>

                <div className="mt-6">
                    <DataTable<StreamEventRow, unknown>
                        data={rows}
                        columns={columns}
                        getRowId={(row) => String(row.id)}
                    />
                    {expandedId !== null && (
                        <pre className="mt-4 max-h-96 overflow-auto rounded-md border bg-muted/40 p-4 text-xs">
                            {JSON.stringify(
                                rows.find((row) => row.id === expandedId)?.decoded ?? {},
                                null,
                                2,
                            )}
                        </pre>
                    )}
                    {events.total > events.per_page && <LaravelPaginator TPaginator={events} />}
                </div>
            </div>
        </AppLayout>
    );
}
