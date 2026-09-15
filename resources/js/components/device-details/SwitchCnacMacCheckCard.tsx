import type { ColumnDef, RowSelectionState } from '@tanstack/react-table';
import { router } from '@inertiajs/react';
import { Loader2, RefreshCw, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import CnacStaticTagsPicker from '@/components/central/CnacStaticTagsPicker';
import type { ClientDetailsInterfaceInput } from '@/components/device-details/SwitchClientDetailsCard';
import { formatRefreshedAt } from '@/components/central/CentralScopeRefreshButtons';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { DataTable } from '@/components/ui/data-table';
import { csrfHeaders } from '@/lib/csrf';
import { refresh as refreshMacRegistrations } from '@/routes/central-scope-cache/mac-registrations';
import {
    cnacMacCheck as cnacMacCheckRoute,
    cnacMacRegister as cnacMacRegisterRoute,
} from '@/routes/device-details';

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
    availableStaticTags?: string[];
    cache?: {
        refreshed_at?: string | null;
        error?: string | null;
    };
};

type CnacMacRegisterResponse = {
    success: boolean;
    error: string | null;
    results: Array<{
        macAddress: string;
        action: string;
        staticTags: string[];
        error: string | null;
    }>;
    availableStaticTags?: string[];
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
          availableStaticTags: string[];
      };

const dataColumns: ColumnDef<CnacMacCheckRow>[] = [
    {
        id: 'select',
        header: ({ table }) => (
            <Checkbox
                checked={
                    table.getIsAllPageRowsSelected() ||
                    (table.getIsSomePageRowsSelected() && 'indeterminate')
                }
                onCheckedChange={(value) =>
                    table.toggleAllPageRowsSelected(!!value)
                }
                aria-label="Select all MAC rows"
                data-test="device-details-cnac-mac-select-all"
            />
        ),
        cell: ({ row }) => (
            <Checkbox
                checked={row.getIsSelected()}
                onCheckedChange={(value) => row.toggleSelected(!!value)}
                aria-label={`Select ${row.original.macAddress}`}
                data-test="device-details-cnac-mac-select-row"
            />
        ),
        enableSorting: false,
        enableHiding: false,
    },
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
    const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
    const [staticTags, setStaticTags] = useState<string[]>([]);
    const [registering, setRegistering] = useState(false);

    const runCheck = useCallback(async () => {
        setState({ kind: 'loading' });
        setRowSelection({});

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
                availableStaticTags: body?.availableStaticTags ?? [],
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

    const selectedMacs = useMemo(() => {
        if (state.kind !== 'ready') {
            return [];
        }

        const selectedIds = new Set(
            Object.entries(rowSelection)
                .filter(([, selected]) => selected)
                .map(([id]) => id),
        );

        const macs: string[] = [];
        for (const row of state.rows) {
            const id = `${row.interface}:${row.macAddress}`;
            if (!selectedIds.has(id)) {
                continue;
            }
            if (!macs.includes(row.macAddress)) {
                macs.push(row.macAddress);
            }
        }

        return macs;
    }, [rowSelection, state]);

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

    const handleRegister = async () => {
        if (selectedMacs.length === 0 || registering) {
            return;
        }

        setRegistering(true);
        try {
            const response = await fetch(cnacMacRegisterRoute.url(), {
                method: 'POST',
                headers: {
                    ...csrfHeaders(),
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    macs: selectedMacs,
                    static_tags: staticTags,
                }),
            });

            const body = (await response.json().catch(() => null)) as
                | CnacMacRegisterResponse
                | null;

            if (!response.ok || !body?.success) {
                const detail =
                    body?.results?.find((row) => row.error)?.error ??
                    body?.error ??
                    `Failed to register MAC addresses (HTTP ${response.status}).`;
                toast.error(detail);
                return;
            }

            const created = body.results.filter((row) => row.action === 'created').length;
            const updated = body.results.filter((row) => row.action === 'updated').length;
            const imported = body.results.filter((row) => row.action === 'imported').length;
            const parts = [
                created > 0 ? `${created} created` : null,
                updated > 0 ? `${updated} updated` : null,
                imported > 0 ? `${imported} imported` : null,
            ].filter(Boolean);

            toast.success(
                parts.length > 0
                    ? `CNAC MAC registration: ${parts.join(', ')}.`
                    : 'CNAC MAC registration succeeded.',
            );
            setStaticTags([]);
            setRowSelection({});
            await runCheck();
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Failed to register MAC addresses.',
            );
        } finally {
            setRegistering(false);
        }
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
                                columns={dataColumns}
                                data={state.rows}
                                getRowId={(row) =>
                                    `${row.interface}:${row.macAddress}`
                                }
                                enableRowSelection
                                rowSelection={rowSelection}
                                onRowSelectionChange={setRowSelection}
                            />
                        </div>

                        <div className="space-y-2 rounded-md border border-border p-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <label className="text-sm font-medium">
                                    Static tags to add (optional)
                                </label>
                                <span className="text-xs text-muted-foreground">
                                    {selectedMacs.length} MAC
                                    {selectedMacs.length === 1 ? '' : 's'} selected
                                </span>
                            </div>
                            <CnacStaticTagsPicker
                                value={staticTags}
                                onChange={setStaticTags}
                                availableTags={state.availableStaticTags}
                                disabled={registering}
                            />
                            <p className="text-xs text-muted-foreground">
                                Existing registrations keep current tags and
                                gain any tags you select here.
                            </p>
                            <Button
                                type="button"
                                disabled={
                                    selectedMacs.length === 0 || registering
                                }
                                data-test="device-details-cnac-mac-register"
                                onClick={() => void handleRegister()}
                            >
                                {registering ? (
                                    <>
                                        <Loader2
                                            className="size-4 animate-spin"
                                            aria-hidden
                                        />
                                        Adding…
                                    </>
                                ) : (
                                    'Add to CNAC'
                                )}
                            </Button>
                        </div>
                    </div>
                ) : null}
            </CardContent>
        </Card>
    );
}
