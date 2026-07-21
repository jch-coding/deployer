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
import { index as webhooksIndex } from '@/routes/webhooks';
import type { BreadcrumbItem, SharedData } from '@/types';
import type { Paginator } from '@/types/deployer';

type WebhookEventRow = {
    id: number;
    alert_type: string | null;
    serial: string | null;
    disposition: string;
    payload: Record<string, unknown>;
    created_at: string | null;
    human_created_at: string | null;
};

type WebhookIndexProps = {
    events: Paginator<WebhookEventRow>;
} & SharedData;

function dispositionBadgeClass(disposition: string): string {
    switch (disposition) {
        case 'accepted':
            return 'bg-emerald-100 text-emerald-800 border-emerald-200';
        case 'ignored':
            return 'bg-slate-200 text-slate-800 border-slate-300';
        default:
            return '';
    }
}

export default function Index() {
    const { current_client, events } = usePage<WebhookIndexProps>().props;
    const [rows, setRows] = useState<WebhookEventRow[]>(events.data);
    const [expandedId, setExpandedId] = useState<number | null>(null);

    useEffect(() => {
        setRows(events.data);
    }, [events.data]);

    const onWebhookReceived = useCallback((payload: WebhookEventRow) => {
        setRows((prev) => {
            if (prev.some((row) => row.id === payload.id)) {
                return prev;
            }

            return [payload, ...prev].slice(0, events.per_page);
        });
    }, [events.per_page]);

    useEcho<WebhookEventRow>(
        current_client ? `clients.${current_client.id}.webhooks` : '',
        '.CentralWebhookReceived',
        onWebhookReceived,
        [current_client?.id, onWebhookReceived],
    );

    const columns = useMemo<ColumnDef<WebhookEventRow>[]>(
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
                accessorKey: 'alert_type',
                header: 'Alert type',
                cell: ({ row }) => row.original.alert_type ?? '—',
            },
            {
                accessorKey: 'serial',
                header: 'Serial',
                cell: ({ row }) => row.original.serial ?? '—',
            },
            {
                accessorKey: 'disposition',
                header: 'Disposition',
                cell: ({ row }) => (
                    <Badge variant="outline" className={dispositionBadgeClass(row.original.disposition)}>
                        {row.original.disposition}
                    </Badge>
                ),
            },
            {
                id: 'payload',
                header: 'Payload',
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
            title: 'Webhook',
            href: webhooksIndex().url,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="mx-auto max-w-7xl px-4">
                <h1 className="text-center text-3xl font-semibold">Webhook</h1>
                <p className="mt-2 text-center text-sm text-muted-foreground">
                    Classic Central webhook payloads for the current client
                </p>

                <div className="mt-6">
                    <DataTable<WebhookEventRow, unknown>
                        data={rows}
                        columns={columns}
                        getRowId={(row) => String(row.id)}
                    />
                    {expandedId !== null && (
                        <pre className="mt-4 max-h-96 overflow-auto rounded-md border bg-muted/40 p-4 text-xs">
                            {JSON.stringify(
                                rows.find((row) => row.id === expandedId)?.payload ?? {},
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
