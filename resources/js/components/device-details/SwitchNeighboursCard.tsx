import type { ColumnDef } from '@tanstack/react-table';
import { Loader2, RefreshCw, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { DataTable } from '@/components/ui/data-table';
import { csrfHeaders } from '@/lib/csrf';
import {
    buildNeighboursFilterOptions,
    emptyNeighboursTableFilters,
    filterNeighbours,
    hasActiveNeighboursTableFilters,
    type NeighbourRow,
    type NeighboursTableFilters,
} from '@/lib/device-neighbours';
import { neighbours as neighboursRoute } from '@/routes/device-details';

type SwitchNeighboursCardProps = {
    serial: string;
    onClose: () => void;
};

type NeighboursResponse = {
    serial: string;
    neighbours: NeighbourRow[];
    error: string | null;
    message?: string;
};

type CardState =
    | { kind: 'loading' }
    | { kind: 'error'; message: string }
    | { kind: 'ready'; rows: NeighbourRow[] };

const selectClassName =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs';

const filterFields: {
    key: keyof NeighboursTableFilters;
    label: string;
}[] = [
    { key: 'memberSerial', label: 'Member Serial' },
    { key: 'name', label: 'Name' },
    { key: 'type', label: 'Type' },
    { key: 'serial', label: 'Serial' },
    { key: 'health', label: 'Health' },
    { key: 'toPort', label: 'To Port' },
    { key: 'localPort', label: 'Local Port' },
    { key: 'siteId', label: 'Site ID' },
    { key: 'siteName', label: 'Site Name' },
];

const columns: ColumnDef<NeighbourRow>[] = [
    {
        accessorKey: 'memberSerial',
        header: 'Member Serial',
        cell: ({ row }) => (
            <span className="font-mono text-sm">
                {row.original.memberSerial || '—'}
            </span>
        ),
    },
    { accessorKey: 'name', header: 'Name' },
    { accessorKey: 'type', header: 'Type' },
    {
        accessorKey: 'serial',
        header: 'Serial',
        cell: ({ row }) => (
            <span className="font-mono text-sm">
                {row.original.serial || '—'}
            </span>
        ),
    },
    { accessorKey: 'health', header: 'Health' },
    {
        accessorKey: 'toPort',
        header: 'To Port',
        cell: ({ row }) => (
            <span className="font-mono text-sm">
                {row.original.toPort || '—'}
            </span>
        ),
    },
    {
        accessorKey: 'localPort',
        header: 'Local Port',
        cell: ({ row }) => (
            <span className="font-mono text-sm">
                {row.original.localPort || '—'}
            </span>
        ),
    },
    { accessorKey: 'siteId', header: 'Site ID' },
    { accessorKey: 'siteName', header: 'Site Name' },
];

export default function SwitchNeighboursCard({
    serial,
    onClose,
}: SwitchNeighboursCardProps) {
    const [state, setState] = useState<CardState>({ kind: 'loading' });
    const [tableFilters, setTableFilters] = useState<NeighboursTableFilters>(
        emptyNeighboursTableFilters,
    );

    const loadNeighbours = useCallback(
        async (force: boolean) => {
            setState({ kind: 'loading' });
            if (force) {
                setTableFilters(emptyNeighboursTableFilters);
            }

            try {
                const response = await fetch(neighboursRoute.url(), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...csrfHeaders(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ serial }),
                });

                const body = (await response
                    .json()
                    .catch(() => null)) as NeighboursResponse | null;

                if (!response.ok) {
                    throw new Error(
                        body?.error ??
                            body?.message ??
                            `Failed to load neighbours (HTTP ${response.status}).`,
                    );
                }

                if (body === null) {
                    throw new Error('Failed to load neighbours: empty response.');
                }

                if (body.error) {
                    throw new Error(body.error);
                }

                setState({ kind: 'ready', rows: body.neighbours });
            } catch (error) {
                setState({
                    kind: 'error',
                    message:
                        error instanceof Error
                            ? error.message
                            : 'Failed to load neighbours.',
                });
            }
        },
        [serial],
    );

    useEffect(() => {
        void loadNeighbours(false);
    }, [loadNeighbours]);

    const filterOptions = useMemo(() => {
        if (state.kind !== 'ready') {
            return buildNeighboursFilterOptions([]);
        }

        return buildNeighboursFilterOptions(state.rows);
    }, [state]);

    const filteredRows = useMemo(() => {
        if (state.kind !== 'ready') {
            return [];
        }

        return filterNeighbours(state.rows, tableFilters);
    }, [state, tableFilters]);

    const hasActiveFilters = useMemo(
        () => hasActiveNeighboursTableFilters(tableFilters),
        [tableFilters],
    );

    useEffect(() => {
        setTableFilters((prev) => {
            let changed = false;
            const next = { ...prev };

            (Object.keys(prev) as (keyof NeighboursTableFilters)[]).forEach(
                (key) => {
                    const selected = prev[key];
                    if (
                        selected !== '' &&
                        !filterOptions[key].includes(selected)
                    ) {
                        next[key] = '';
                        changed = true;
                    }
                },
            );

            return changed ? next : prev;
        });
    }, [filterOptions]);

    const handleClose = () => {
        setTableFilters(emptyNeighboursTableFilters);
        onClose();
    };

    return (
        <Card
            className="mt-4 gap-0 py-0"
            data-test="device-details-neighbours-card"
        >
            <div className="border-b border-border px-6 py-4">
                <div className="flex items-center justify-between gap-2">
                    <h3 className="text-sm font-semibold">Neighbors</h3>
                    <div className="flex items-center gap-1">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8 shrink-0"
                            disabled={state.kind === 'loading'}
                            onClick={() => void loadNeighbours(true)}
                            aria-label="Refresh neighbours"
                            data-test="device-details-neighbours-refresh"
                        >
                            <RefreshCw className="size-4" aria-hidden />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8 shrink-0"
                            onClick={handleClose}
                            aria-label="Close neighbours"
                            data-test="device-details-neighbours-close"
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
                        data-test="device-details-neighbours-loading"
                    >
                        <Loader2 className="size-4 animate-spin" aria-hidden />
                        Loading neighbours…
                    </div>
                ) : null}

                {state.kind === 'error' ? (
                    <p
                        className="text-sm text-destructive"
                        role="alert"
                        data-test="device-details-neighbours-error"
                    >
                        {state.message}
                    </p>
                ) : null}

                {state.kind === 'ready' ? (
                    <div className="space-y-4">
                        <div
                            className="flex flex-col gap-2 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between"
                            data-test="device-details-neighbours-meta"
                        >
                            <p data-test="device-details-neighbours-count">
                                {hasActiveFilters &&
                                filteredRows.length !== state.rows.length
                                    ? `Showing ${filteredRows.length} of ${state.rows.length}`
                                    : `${state.rows.length} neighbour${state.rows.length === 1 ? '' : 's'}`}
                            </p>
                        </div>

                        {state.rows.length === 0 ? (
                            <p
                                className="text-sm text-muted-foreground"
                                data-test="device-details-neighbours-empty"
                            >
                                No neighbours found for this device.
                            </p>
                        ) : (
                            <>
                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                    {filterFields.map((field) => (
                                        <select
                                            key={field.key}
                                            value={tableFilters[field.key]}
                                            onChange={(e) =>
                                                setTableFilters((prev) => ({
                                                    ...prev,
                                                    [field.key]: e.target.value,
                                                }))
                                            }
                                            className={selectClassName}
                                            data-test={`device-details-neighbours-filter-${field.key}`}
                                            disabled={
                                                filterOptions[field.key]
                                                    .length === 0
                                            }
                                        >
                                            <option value="">
                                                All {field.label}
                                            </option>
                                            {filterOptions[field.key].map(
                                                (option) => (
                                                    <option
                                                        key={option}
                                                        value={option}
                                                    >
                                                        {option}
                                                    </option>
                                                ),
                                            )}
                                        </select>
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
                                                    emptyNeighboursTableFilters,
                                                )
                                            }
                                            data-test="device-details-neighbours-clear-filters"
                                        >
                                            Clear table filters
                                        </Button>
                                    </div>
                                ) : null}

                                {filteredRows.length === 0 ? (
                                    <p
                                        className="text-sm text-muted-foreground"
                                        data-test="device-details-neighbours-no-matches"
                                    >
                                        No neighbours match.
                                    </p>
                                ) : (
                                    <div
                                        className="max-h-[28rem] overflow-auto rounded-md border border-border"
                                        data-test="device-details-neighbours-results"
                                    >
                                        <DataTable
                                            columns={columns}
                                            data={filteredRows}
                                            getRowId={(row, index) =>
                                                `${row.serial}-${row.localPort}-${row.toPort}-${index}`
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
