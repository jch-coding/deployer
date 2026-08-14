import { usePage } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import type { ColumnDef } from '@tanstack/react-table';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import CreateCloudflareTunnelWizard from '@/components/ui/CreateCloudflareTunnelWizard';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import { Input } from '@/components/ui/input';
import LaravelPaginator from '@/components/ui/LaravelPaginator';
import { useInertiaPoll } from '@/hooks/use-inertia-poll';
import AppLayout from '@/layouts/app-layout';
import { csrfHeaders } from '@/lib/csrf';
import { index as clientsIndex } from '@/routes/clients';
import { index as webhooksIndex } from '@/routes/webhooks';
import {
    start as startTunnel,
    stop as stopTunnel,
} from '@/routes/webhooks/cloudflare_tunnel';
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

export type CloudflareTunnelStatus = {
    binary: boolean;
    binary_path: string | null;
    logged_in: boolean;
    name: string | null;
    hostname: string | null;
    running: boolean;
    pid: number | null;
    message: string | null;
    available: boolean;
};

type WebhookIndexProps = {
    events: Paginator<WebhookEventRow>;
    cloudflare_tunnel: CloudflareTunnelStatus;
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

function tunnelBadge(status: CloudflareTunnelStatus): { label: string; className: string } {
    if (!status.available || !status.binary) {
        return {
            label: 'unavailable',
            className: 'bg-amber-100 text-amber-900 border-amber-200',
        };
    }
    if (status.running) {
        return {
            label: 'running',
            className: 'bg-emerald-100 text-emerald-800 border-emerald-200',
        };
    }

    return {
        label: 'stopped',
        className: 'bg-slate-200 text-slate-800 border-slate-300',
    };
}

export default function Index() {
    const { current_client, events, cloudflare_tunnel } = usePage<WebhookIndexProps>().props;
    const [rows, setRows] = useState<WebhookEventRow[]>(events.data);
    const [expandedId, setExpandedId] = useState<number | null>(null);
    const [tunnelName, setTunnelName] = useState(cloudflare_tunnel.name ?? 'deployer');
    const [tunnelBusy, setTunnelBusy] = useState(false);

    useEffect(() => {
        setRows(events.data);
    }, [events.data]);

    useEffect(() => {
        if (cloudflare_tunnel.name) {
            setTunnelName(cloudflare_tunnel.name);
        }
    }, [cloudflare_tunnel.name]);

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

    // Fallback when Echo/Reverb is unreachable (HTTPS tunnel + local ws://).
    useInertiaPoll(['events', 'cloudflare_tunnel'], 5000);

    const startOrStop = useCallback(
        async (action: 'start' | 'stop') => {
            const trimmed = tunnelName.trim();
            if (action === 'start' && !trimmed) {
                toast.error('Enter a tunnel name.');

                return;
            }
            setTunnelBusy(true);
            try {
                const url = action === 'start' ? startTunnel.url() : stopTunnel.url();
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...csrfHeaders(),
                    },
                    credentials: 'same-origin',
                    body: action === 'start' ? JSON.stringify({ name: trimmed }) : undefined,
                });
                const data = (await res.json().catch(() => ({}))) as {
                    ok?: boolean;
                    message?: string;
                    errors?: Record<string, string[]>;
                };
                if (!res.ok || data.ok === false) {
                    toast.error(
                        data.message ??
                            (data.errors
                                ? Object.values(data.errors).flat().join(' ')
                                : `Failed to ${action} tunnel.`),
                    );

                    return;
                }
                toast.success(data.message ?? (action === 'start' ? 'Tunnel started.' : 'Tunnel stopped.'));
            } finally {
                setTunnelBusy(false);
            }
        },
        [tunnelName],
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

    const badge = tunnelBadge(cloudflare_tunnel);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="relative mx-auto max-w-7xl px-4">
                <div className="absolute top-3 right-3 z-10 flex max-w-[min(100%,28rem)] flex-col items-end gap-2">
                    <div className="flex flex-wrap items-center justify-end gap-2">
                        <Input
                            className="h-8 w-36"
                            value={tunnelName}
                            onChange={(e) => setTunnelName(e.target.value)}
                            placeholder="tunnel name"
                            aria-label="Cloudflare tunnel name"
                            autoComplete="off"
                        />
                        {cloudflare_tunnel.running ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={tunnelBusy}
                                onClick={() => startOrStop('stop')}
                            >
                                Stop
                            </Button>
                        ) : (
                            <Button
                                type="button"
                                size="sm"
                                disabled={tunnelBusy || !cloudflare_tunnel.available}
                                onClick={() => startOrStop('start')}
                            >
                                Start
                            </Button>
                        )}
                        <CreateCloudflareTunnelWizard
                            initiallyLoggedIn={cloudflare_tunnel.logged_in}
                            defaultName={cloudflare_tunnel.name ?? tunnelName}
                            defaultHostname={cloudflare_tunnel.hostname}
                        />
                    </div>
                    <div className="flex flex-wrap items-center justify-end gap-2 text-xs text-muted-foreground">
                        <Badge variant="outline" className={badge.className}>
                            {badge.label}
                        </Badge>
                        {cloudflare_tunnel.hostname && (
                            <span className="max-w-[16rem] truncate" title={cloudflare_tunnel.hostname}>
                                {cloudflare_tunnel.hostname}
                            </span>
                        )}
                    </div>
                    {cloudflare_tunnel.message && (
                        <p className="max-w-sm text-right text-xs text-amber-700 dark:text-amber-300">
                            {cloudflare_tunnel.message}
                        </p>
                    )}
                </div>

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
