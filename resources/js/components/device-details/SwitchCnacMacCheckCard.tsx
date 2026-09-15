import type { ColumnDef } from '@tanstack/react-table';
import { router } from '@inertiajs/react';
import { Loader2, RefreshCw, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import type { ClientDetailsInterfaceInput } from '@/components/device-details/SwitchClientDetailsCard';
import { formatRefreshedAt } from '@/components/central/CentralScopeRefreshButtons';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { DataTable } from '@/components/ui/data-table';
import { csrfHeaders } from '@/lib/csrf';
import { refresh as refreshMacRegistrations } from '@/routes/central-scope-cache/mac-registrations';
import { cnacMacCheck as cnacMacCheckRoute } from '@/routes/device-details';

export type CnacMacCheckRow = {
    interface: string;
    macAddress: string;
    registered: boolean;
    clientName: string | null;
    enabled: boolean | null;
    staticTags: string[];
};

export type CnacMacCheckError = {
    interface: string;
    message: string;
};

type SwitchCnacMacCheckCardProps = {
    serial: string;
    interfaces: ClientDetailsInterfaceInput[];
    onClose: () => void;
};

type CnacMacCheckResponse = {
    rows: CnacMacCheckRow[];
    errors: CnacMacCheckError[];
    error: string | null;
    cache?: {
        refreshed_at?: string | null;
        error?: string | null;
    };
};

type CardState =
    | { kind: 'loading' }
    | { kind: 'error'; message: string }
    | {
          kind: 'ready';
          rows: CnacMacCheckRow[];
          errors: CnacMacCheckError[];
          refreshedAt: string | null;
      };

const columns: ColumnDef<CnacMacCheckRow>[] = [
    {
        accessorKey: 'interface',
        header: 'Port',
        cell: ({ row }) => (
            <span className="font-mono text-sm">{row.original.interface}</span>
        ),
    },
    {
        accessorKey: 'macAddress',
        header: 'MAC',
        cell: ({ row }) => (
            <span className="font-mono text-sm">{row.original.macAddress}</span>
        ),
    },
    {
        accessorKey: 'registered',
        header: 'Registered',
        cell: ({ row }) =>
            row.original.registered ? (
                <Badge variant="default">Yes</Badge>
            ) : (
                <Badge variant="secondary">No</Badge>
            ),
    },
    {
        id: 'staticTags',
        header: 'Static tags',
        cell: ({ row }) =>
            row.original.staticTags.length > 0
                ? row.original.staticTags.join(', ')
                : '—',
    },
    {
        accessorKey: 'clientName',
        header: 'Client name',
        cell: ({ row }) => row.original.clientName || '—',
    },
    {
        accessorKey: 'enabled',
        header: 'Enabled',
        cell: ({ row }) => {
            if (row.original.enabled === null) {
                return '—';
            }

            return row.original.enabled ? 'true' : 'false';
        },
    },
];

export default function SwitchCnacMacCheckCard({
    serial,
    interfaces,
    onClose,
}: SwitchCnacMacCheckCardProps) {
    const [state, setState] = useState<CardState>({ kind: 'loading' });
    const [refreshingCache, setRefreshingCache] = useState(false);

    const runCheck = useCallback(async () => {
        setState({ kind: 'loading' });

        try {
            const response = await fetch(cnacMacCheckRoute.url(), {
                method: 'POST',
                headers: {
                    ...csrfHeaders(),
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    serial,
                    interfaces,
                }),
            });

            const body = (await response.json().catch(() => null)) as
                | CnacMacCheckResponse
                | null;

            if (!response.ok) {
                throw new Error(
                    body?.error ??
                        `CNAC MAC check failed (HTTP ${response.status}).`,
                );
            }

            setState({
                kind: 'ready',
                rows: body?.rows ?? [],
                errors: body?.errors ?? [],
                refreshedAt: body?.cache?.refreshed_at ?? null,
            });
        } catch (error) {
            setState({
                kind: 'error',
                message:
                    error instanceof Error
                        ? error.message
                        : 'Failed to check CNAC MAC registrations.',
            });
        }
    }, [interfaces, serial]);

    useEffect(() => {
        void runCheck();
    }, [runCheck]);

    const registeredCount = useMemo(() => {
        if (state.kind !== 'ready') {
            return 0;
        }

        return state.rows.filter((row) => row.registered).length;
    }, [state]);

    const handleRefresh = () => {
        if (refreshingCache) {
            return;
        }

        setRefreshingCache(true);
        router.post(
            refreshMacRegistrations.url(),
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    void runCheck().finally(() => setRefreshingCache(false));
                },
                onError: () => setRefreshingCache(false),
            },
        );
    };

    return (
        <Card
            className="mt-4 gap-0 py-0"
            data-test="device-details-cnac-mac-check-card"
        >
            <div className="border-b border-border px-6 py-4">
                <div className="flex items-center justify-between gap-2">
                    <div>
                        <h3 className="text-sm font-semibold">
                            CNAC MAC registration check
                        </h3>
                        {state.kind === 'ready' ? (
                            <p className="mt-1 text-xs text-muted-foreground">
                                Last refreshed{' '}
                                {formatRefreshedAt(state.refreshedAt)}
                            </p>
                        ) : null}
                    </div>
                    <div className="flex items-center gap-1">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8 shrink-0"
                            onClick={handleRefresh}
                            disabled={
                                state.kind === 'loading' || refreshingCache
                            }
                            aria-label="Refresh CNAC MAC table and re-check"
                            data-test="device-details-cnac-mac-check-refresh"
                        >
                            <RefreshCw
                                className={`size-4 ${
                                    refreshingCache || state.kind === 'loading'
                                        ? 'animate-spin'
                                        : ''
                                }`}
                                aria-hidden
                            />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8 shrink-0"
                            onClick={onClose}
                            aria-label="Close CNAC MAC check"
                            data-test="device-details-cnac-mac-check-close"
                        >
                            <X className="size-4" aria-hidden />
                        </Button>
                    </div>
                </div>
            </div>

            <CardContent className="px-6 py-4">
                {state.kind === 'loading' ? (
                    <div
                        className="flex items-center gap-2 text-sm text-muted-foreground"
                        data-test="device-details-cnac-mac-check-loading"
                    >
                        <Loader2 className="size-4 animate-spin" aria-hidden />
                        Checking CNAC MAC table…
                    </div>
                ) : null}

                {state.kind === 'error' ? (
                    <p
                        className="text-sm text-destructive"
                        role="alert"
                        data-test="device-details-cnac-mac-check-error"
                    >
                        {state.message}
                    </p>
                ) : null}

                {state.kind === 'ready' ? (
                    <div className="space-y-4">
                        {state.errors.length > 0 ? (
                            <div
                                className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-100"
                                data-test="device-details-cnac-mac-check-soft-errors"
                            >
                                <p className="font-medium">
                                    Some interfaces could not be resolved
                                </p>
                                <ul className="mt-1 list-inside list-disc">
                                    {state.errors.map((item, index) => (
                                        <li
                                            key={`${item.interface}-${index}`}
                                        >
                                            {item.interface}: {item.message}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ) : null}

                        <div
                            className="text-sm text-muted-foreground"
                            data-test="device-details-cnac-mac-check-meta"
                        >
                            {state.rows.length === 0
                                ? '0 MAC addresses resolved'
                                : `${registeredCount} of ${state.rows.length} registered`}
                        </div>

                        <div className="max-h-[28rem] overflow-auto rounded-md border border-border">
                            <DataTable
                                columns={columns}
                                data={state.rows}
                                getRowId={(row) =>
                                    `${row.interface}:${row.macAddress}`
                                }
                            />
                        </div>
                    </div>
                ) : null}
            </CardContent>
        </Card>
    );
}
