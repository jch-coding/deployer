import type { ColumnDef } from '@tanstack/react-table';
import { Loader2, RefreshCw, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { DataTable } from '@/components/ui/data-table';
import { Input } from '@/components/ui/input';
import { csrfHeaders } from '@/lib/csrf';
import { pollCxAsyncOperation } from '@/lib/cx-async-operation';
import {
    filterMacAddressTableRows,
    parseMacAddressTableOutput,
    type MacAddressTableRow,
} from '@/lib/mac-address-table';
import { showCommands as showCommandsRoute } from '@/routes/device-details';
import { result as showCommandsResultRoute } from '@/routes/device-details/show-commands';

const MAC_ADDRESS_TABLE_COMMAND = 'show mac-address-table';

type SwitchMacAddressTableCardProps = {
    serial: string;
    onClose: () => void;
};

type CardState =
    | { kind: 'loading'; progressPercent?: number }
    | { kind: 'error'; message: string }
    | {
          kind: 'ready';
          rows: MacAddressTableRow[];
          ageTime: string | null;
          count: number | null;
          raw: string;
          parseFailed: boolean;
      };

const columns: ColumnDef<MacAddressTableRow>[] = [
    {
        accessorKey: 'mac',
        header: 'MAC Address',
        cell: ({ row }) => (
            <span className="font-mono text-sm">{row.original.mac}</span>
        ),
    },
    {
        accessorKey: 'vlan',
        header: 'VLAN',
    },
    {
        accessorKey: 'type',
        header: 'Type',
    },
    {
        accessorKey: 'port',
        header: 'Port',
        cell: ({ row }) => (
            <span className="font-mono text-sm">{row.original.port}</span>
        ),
    },
];

export default function SwitchMacAddressTableCard({
    serial,
    onClose,
}: SwitchMacAddressTableCardProps) {
    const [state, setState] = useState<CardState>({ kind: 'loading' });
    const [searchQuery, setSearchQuery] = useState('');

    const fetchMacAddressTable = useCallback(async () => {
        setState({ kind: 'loading' });
        setSearchQuery('');

        try {
            const response = await fetch(showCommandsRoute.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...csrfHeaders(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    serial,
                    commands: [MAC_ADDRESS_TABLE_COMMAND],
                }),
            });

            const body = (await response.json().catch(() => null)) as {
                task_id?: string;
                error?: string;
            } | null;

            if (!response.ok) {
                throw new Error(
                    body?.error ??
                        `Failed to start show mac-address-table (HTTP ${response.status}).`,
                );
            }

            const taskId = body?.task_id?.trim();
            if (!taskId) {
                throw new Error('Central did not return a task id.');
            }

            const pollBody = await pollCxAsyncOperation(
                showCommandsResultRoute.url({ serial, taskId }),
                {
                    onProgress: (progressBody) => {
                        setState({
                            kind: 'loading',
                            progressPercent: progressBody.progressPercent,
                        });
                    },
                },
            );

            const output =
                pollBody.output?.results?.find(
                    (result) => result.command === MAC_ADDRESS_TABLE_COMMAND,
                )?.output ??
                pollBody.output?.results?.[0]?.output ??
                '';

            const parsed = parseMacAddressTableOutput(output);

            setState({
                kind: 'ready',
                rows: parsed.rows,
                ageTime: parsed.ageTime,
                count: parsed.count,
                raw: parsed.raw,
                parseFailed: parsed.rows.length === 0 && output.trim() !== '',
            });
        } catch (error) {
            setState({
                kind: 'error',
                message:
                    error instanceof Error
                        ? error.message
                        : 'Failed to load MAC address table.',
            });
        }
    }, [serial]);

    useEffect(() => {
        void fetchMacAddressTable();
    }, [fetchMacAddressTable]);

    const filteredRows = useMemo(() => {
        if (state.kind !== 'ready') {
            return [];
        }

        return filterMacAddressTableRows(state.rows, searchQuery);
    }, [searchQuery, state]);

    const handleClose = () => {
        setSearchQuery('');
        onClose();
    };

    return (
        <Card
            className="mt-4 gap-0 py-0"
            data-test="device-details-mac-address-table-card"
        >
            <div className="border-b border-border px-6 py-4">
                <div className="flex items-center justify-between gap-2">
                    <h3 className="text-sm font-semibold">MAC address table</h3>
                    <div className="flex items-center gap-1">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8 shrink-0"
                            disabled={state.kind === 'loading'}
                            onClick={() => void fetchMacAddressTable()}
                            aria-label="Refresh MAC address table"
                            data-test="device-details-mac-address-table-refresh"
                        >
                            <RefreshCw className="size-4" aria-hidden />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8 shrink-0"
                            onClick={handleClose}
                            aria-label="Close MAC address table"
                            data-test="device-details-mac-address-table-close"
                        >
                            <X className="size-4" aria-hidden />
                        </Button>
                    </div>
                </div>
                <p className="mt-1 font-mono text-xs text-muted-foreground">
                    &gt; {MAC_ADDRESS_TABLE_COMMAND}
                </p>
            </div>

            <CardContent className="px-6 py-4">
                {state.kind === 'loading' ? (
                    <div
                        className="flex items-center gap-2 text-sm text-muted-foreground"
                        data-test="device-details-mac-address-table-loading"
                    >
                        <Loader2 className="size-4 animate-spin" aria-hidden />
                        Loading MAC address table
                        {typeof state.progressPercent === 'number'
                            ? ` (${state.progressPercent}%)`
                            : '…'}
                    </div>
                ) : null}

                {state.kind === 'error' ? (
                    <p
                        className="text-sm text-destructive"
                        role="alert"
                        data-test="device-details-mac-address-table-error"
                    >
                        {state.message}
                    </p>
                ) : null}

                {state.kind === 'ready' ? (
                    <div className="space-y-4">
                        <div
                            className="flex flex-col gap-2 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between"
                            data-test="device-details-mac-address-table-meta"
                        >
                            <div className="space-y-1">
                                {state.ageTime ? (
                                    <p>MAC age-time: {state.ageTime}</p>
                                ) : null}
                                {state.count !== null ? (
                                    <p>
                                        {state.count} MAC address
                                        {state.count === 1 ? '' : 'es'}
                                    </p>
                                ) : null}
                            </div>
                            <p data-test="device-details-mac-address-table-count">
                                {searchQuery.trim() !== '' &&
                                filteredRows.length !== state.rows.length
                                    ? `Showing ${filteredRows.length} of ${state.rows.length}`
                                    : `${state.rows.length} entr${state.rows.length === 1 ? 'y' : 'ies'}`}
                            </p>
                        </div>

                        {state.parseFailed ? (
                            <div className="space-y-2">
                                <p
                                    className="text-sm text-destructive"
                                    role="alert"
                                    data-test="device-details-mac-address-table-parse-error"
                                >
                                    Could not parse MAC address table output.
                                </p>
                                <pre className="max-h-64 overflow-auto font-mono text-sm whitespace-pre-wrap text-muted-foreground">
                                    {state.raw || '(no output)'}
                                </pre>
                            </div>
                        ) : (
                            <>
                                <Input
                                    value={searchQuery}
                                    onChange={(event) =>
                                        setSearchQuery(event.target.value)
                                    }
                                    placeholder="Search MAC, VLAN, type, or port"
                                    data-test="device-details-mac-address-table-search"
                                />

                                {state.rows.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No MAC addresses in table.
                                    </p>
                                ) : filteredRows.length === 0 ? (
                                    <p
                                        className="text-sm text-muted-foreground"
                                        data-test="device-details-mac-address-table-no-matches"
                                    >
                                        No MAC addresses match.
                                    </p>
                                ) : (
                                    <div
                                        className="max-h-[28rem] overflow-auto rounded-md border border-border"
                                        data-test="device-details-mac-address-table-results"
                                    >
                                        <DataTable
                                            columns={columns}
                                            data={filteredRows}
                                            getRowId={(row) =>
                                                `${row.mac}-${row.vlan}-${row.port}`
                                            }
                                        />
                                    </div>
                                )}
                            </>
                        )}
                    </div>
                ) : null}
            </CardContent>
        </Card>
    );
}
