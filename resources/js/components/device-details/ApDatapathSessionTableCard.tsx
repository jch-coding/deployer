import type { ColumnDef } from '@tanstack/react-table';
import { Loader2, RefreshCw, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { DataTable } from '@/components/ui/data-table';
import { Input } from '@/components/ui/input';
import {
    DATAPATH_SESSION_TABLE_COMMAND,
    getCachedOrFetchDatapathSessionTable,
} from '@/lib/datapath-session-search';
import {
    emptyDatapathSessionTableFilters,
    expandOffloadFlags,
    expandSessionFlags,
    filterDatapathSessionTableRows,
    formatProtocolLabel,
    hasActiveDatapathSessionTableFilters,
    parseHexCounter,
    type DatapathSessionTableFilters,
    type DatapathSessionTableRow,
} from '@/lib/datapath-session-table';

type ApDatapathSessionTableCardProps = {
    serial: string;
    onClose: () => void;
};

type CardState =
    | { kind: 'loading'; progressPercent?: number }
    | { kind: 'error'; message: string }
    | {
          kind: 'ready';
          rows: DatapathSessionTableRow[];
          raw: string;
          parseFailed: boolean;
      };

const filterFields: {
    key: keyof DatapathSessionTableFilters;
    label: string;
}[] = [
    { key: 'sourceIp', label: 'Source IP' },
    { key: 'destinationIp', label: 'Destination IP' },
    { key: 'prot', label: 'Prot' },
    { key: 'sport', label: 'SPort' },
    { key: 'dport', label: 'Dport' },
    { key: 'cntr', label: 'Cntr' },
    { key: 'prio', label: 'Prio' },
    { key: 'tos', label: 'ToS' },
    { key: 'age', label: 'Age' },
    { key: 'destination', label: 'Destination' },
    { key: 'tage', label: 'TAge' },
    { key: 'packets', label: 'Packets' },
    { key: 'bytes', label: 'Bytes' },
    { key: 'flags', label: 'Flags' },
    { key: 'offloadFlags', label: 'Offload flags' },
];

function FlagBadges({
    flags,
    expand,
}: {
    flags: string;
    expand: (flags: string) => string[];
}) {
    if (flags === '') {
        return <span className="text-muted-foreground">—</span>;
    }

    const names = expand(flags);

    return (
        <div className="flex max-w-xs flex-wrap gap-1">
            {names.map((name, index) => (
                <Badge
                    key={`${flags[index] ?? name}-${index}`}
                    variant="secondary"
                    className="font-normal"
                    title={flags[index] ?? name}
                >
                    {name}
                </Badge>
            ))}
        </div>
    );
}

const columns: ColumnDef<DatapathSessionTableRow>[] = [
    {
        id: 'sourceIp',
        accessorKey: 'sourceIp',
        header: 'Source IP',
        cell: ({ row }) => (
            <span className="font-mono text-sm">{row.original.sourceIp}</span>
        ),
    },
    {
        id: 'destinationIp',
        accessorKey: 'destinationIp',
        header: 'Destination IP',
        cell: ({ row }) => (
            <span className="font-mono text-sm">
                {row.original.destinationIp || '—'}
            </span>
        ),
    },
    {
        id: 'prot',
        accessorKey: 'prot',
        header: 'Prot',
        cell: ({ row }) => {
            const label = formatProtocolLabel(row.original.prot);
            return (
                <span className="font-mono text-sm" title={row.original.prot}>
                    {label || '—'}
                </span>
            );
        },
    },
    {
        id: 'sport',
        accessorKey: 'sport',
        header: 'SPort',
        cell: ({ row }) => (
            <span className="font-mono text-sm">
                {row.original.sport || '—'}
            </span>
        ),
    },
    {
        id: 'dport',
        accessorKey: 'dport',
        header: 'Dport',
        cell: ({ row }) => (
            <span className="font-mono text-sm">
                {row.original.dport || '—'}
            </span>
        ),
    },
    {
        id: 'cntr',
        accessorKey: 'cntr',
        header: 'Cntr',
    },
    {
        id: 'prio',
        accessorKey: 'prio',
        header: 'Prio',
    },
    {
        id: 'tos',
        accessorKey: 'tos',
        header: 'ToS',
    },
    {
        id: 'age',
        accessorKey: 'age',
        header: 'Age',
    },
    {
        id: 'destination',
        accessorKey: 'destination',
        header: 'Destination',
        cell: ({ row }) => (
            <span className="font-mono text-sm">
                {row.original.destination || '—'}
            </span>
        ),
    },
    {
        id: 'tage',
        accessorKey: 'tage',
        header: 'TAge',
        cell: ({ row }) => (
            <span className="font-mono text-sm" title={row.original.tage}>
                {parseHexCounter(row.original.tage) || '—'}
            </span>
        ),
    },
    {
        id: 'packets',
        accessorKey: 'packets',
        header: 'Packets',
        cell: ({ row }) => (
            <span className="font-mono text-sm" title={row.original.packets}>
                {parseHexCounter(row.original.packets) || '—'}
            </span>
        ),
    },
    {
        id: 'bytes',
        accessorKey: 'bytes',
        header: 'Bytes',
        cell: ({ row }) => (
            <span className="font-mono text-sm" title={row.original.bytes}>
                {parseHexCounter(row.original.bytes) || '—'}
            </span>
        ),
    },
    {
        id: 'flags',
        accessorKey: 'flags',
        header: 'Flags',
        cell: ({ row }) => (
            <FlagBadges
                flags={row.original.flags}
                expand={expandSessionFlags}
            />
        ),
    },
    {
        id: 'offloadFlags',
        accessorKey: 'offloadFlags',
        header: 'Offload flags',
        cell: ({ row }) => (
            <FlagBadges
                flags={row.original.offloadFlags}
                expand={expandOffloadFlags}
            />
        ),
    },
];

export default function ApDatapathSessionTableCard({
    serial,
    onClose,
}: ApDatapathSessionTableCardProps) {
    const [state, setState] = useState<CardState>({ kind: 'loading' });
    const [tableFilters, setTableFilters] = useState<DatapathSessionTableFilters>(
        emptyDatapathSessionTableFilters,
    );

    const loadTable = useCallback(
        async (force: boolean) => {
            setState({ kind: 'loading' });
            if (force) {
                setTableFilters(emptyDatapathSessionTableFilters);
            }

            try {
                const entry = await getCachedOrFetchDatapathSessionTable(
                    serial,
                    {
                        force,
                        onProgress: (progressPercent) => {
                            setState({
                                kind: 'loading',
                                progressPercent,
                            });
                        },
                    },
                );

                const { table } = entry;

                setState({
                    kind: 'ready',
                    rows: table.rows,
                    raw: table.raw,
                    parseFailed:
                        table.rows.length === 0 && table.raw.trim() !== '',
                });
            } catch (error) {
                setState({
                    kind: 'error',
                    message:
                        error instanceof Error
                            ? error.message
                            : 'Failed to load datapath session table.',
                });
            }
        },
        [serial],
    );

    useEffect(() => {
        void loadTable(false);
    }, [loadTable]);

    const filteredRows = useMemo(() => {
        if (state.kind !== 'ready') {
            return [];
        }

        return filterDatapathSessionTableRows(state.rows, tableFilters);
    }, [state, tableFilters]);

    const hasActiveFilters = useMemo(
        () => hasActiveDatapathSessionTableFilters(tableFilters),
        [tableFilters],
    );

    const handleClose = () => {
        setTableFilters(emptyDatapathSessionTableFilters);
        onClose();
    };

    return (
        <Card
            className="mt-4 gap-0 py-0"
            data-test="device-details-datapath-session-table-card"
        >
            <div className="border-b border-border px-6 py-4">
                <div className="flex items-center justify-between gap-2">
                    <h3 className="text-sm font-semibold">
                        Datapath session table
                    </h3>
                    <div className="flex items-center gap-1">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8 shrink-0"
                            disabled={state.kind === 'loading'}
                            onClick={() => void loadTable(true)}
                            aria-label="Refresh datapath session table"
                            data-test="device-details-datapath-session-table-refresh"
                        >
                            <RefreshCw className="size-4" aria-hidden />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8 shrink-0"
                            onClick={handleClose}
                            aria-label="Close datapath session table"
                            data-test="device-details-datapath-session-table-close"
                        >
                            <X className="size-4" aria-hidden />
                        </Button>
                    </div>
                </div>
                <p className="mt-1 font-mono text-xs text-muted-foreground">
                    &gt; {DATAPATH_SESSION_TABLE_COMMAND}
                </p>
            </div>

            <CardContent className="px-6 py-4">
                {state.kind === 'loading' ? (
                    <div
                        className="flex items-center gap-2 text-sm text-muted-foreground"
                        data-test="device-details-datapath-session-table-loading"
                    >
                        <Loader2 className="size-4 animate-spin" aria-hidden />
                        Loading datapath session table
                        {typeof state.progressPercent === 'number'
                            ? ` (${state.progressPercent}%)`
                            : '…'}
                    </div>
                ) : null}

                {state.kind === 'error' ? (
                    <p
                        className="text-sm text-destructive"
                        role="alert"
                        data-test="device-details-datapath-session-table-error"
                    >
                        {state.message}
                    </p>
                ) : null}

                {state.kind === 'ready' ? (
                    <div className="space-y-4">
                        <div
                            className="flex flex-col gap-2 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between"
                            data-test="device-details-datapath-session-table-meta"
                        >
                            <p data-test="device-details-datapath-session-table-count">
                                {hasActiveFilters &&
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
                                    data-test="device-details-datapath-session-table-parse-error"
                                >
                                    Could not parse datapath session table
                                    output.
                                </p>
                                <pre className="max-h-64 overflow-auto font-mono text-sm whitespace-pre-wrap text-muted-foreground">
                                    {state.raw || '(no output)'}
                                </pre>
                            </div>
                        ) : (
                            <>
                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                    {filterFields.map((field) => (
                                        <Input
                                            key={field.key}
                                            value={tableFilters[field.key]}
                                            onChange={(event) =>
                                                setTableFilters((prev) => ({
                                                    ...prev,
                                                    [field.key]:
                                                        event.target.value,
                                                }))
                                            }
                                            placeholder={`Filter ${field.label}`}
                                            data-test={`device-details-datapath-session-filter-${field.key}`}
                                        />
                                    ))}
                                </div>

                                {hasActiveFilters ? (
                                    <div className="flex justify-end">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setTableFilters(
                                                    emptyDatapathSessionTableFilters,
                                                )
                                            }
                                            data-test="device-details-datapath-session-clear-filters"
                                        >
                                            Clear table filters
                                        </Button>
                                    </div>
                                ) : null}

                                {state.rows.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No datapath sessions in table.
                                    </p>
                                ) : filteredRows.length === 0 ? (
                                    <p
                                        className="text-sm text-muted-foreground"
                                        data-test="device-details-datapath-session-table-no-matches"
                                    >
                                        No sessions match the current filters.
                                    </p>
                                ) : (
                                    <div
                                        className="max-h-[28rem] overflow-auto rounded-md border border-border"
                                        data-test="device-details-datapath-session-table-results"
                                    >
                                        <DataTable
                                            columns={columns}
                                            data={filteredRows}
                                            enableColumnPicker
                                            columnPickerTitle="Columns"
                                            stickyLeftColumnIds={['sourceIp']}
                                            getRowId={(row, index) =>
                                                `${row.sourceIp}-${row.destinationIp}-${row.prot}-${row.sport}-${row.dport}-${index}`
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
