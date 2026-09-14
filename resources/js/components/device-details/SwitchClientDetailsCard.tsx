import type { ColumnDef } from '@tanstack/react-table';
import { Loader2, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { DataTable } from '@/components/ui/data-table';
import { Input } from '@/components/ui/input';
import {
    buildClientDetailsFilterOptions,
    emptyClientDetailsTableFilters,
    filterClientDetailsRows,
    hasActiveClientDetailsTableFilters,
    splitClientTags,
    type ClientDetailsError,
    type ClientDetailsRow,
    type ClientDetailsTableFilters,
} from '@/lib/client-details';
import { csrfHeaders } from '@/lib/csrf';
import { clientDetails as clientDetailsRoute } from '@/routes/device-details';

export type ClientDetailsInterfaceInput = {
    name: string;
    neighbour: string;
    neighbourSerial: string;
};

type SwitchClientDetailsCardProps = {
    serial: string;
    interfaces: ClientDetailsInterfaceInput[];
    onClose: () => void;
};

type ClientDetailsResponse = {
    rows: ClientDetailsRow[];
    errors: ClientDetailsError[];
    error: string | null;
    message?: string;
};

type CardState =
    | { kind: 'loading' }
    | { kind: 'error'; message: string }
    | {
          kind: 'ready';
          rows: ClientDetailsRow[];
          errors: ClientDetailsError[];
      };

const selectClassName =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs';

const filterFields: {
    key: keyof ClientDetailsTableFilters;
    label: string;
}[] = [
    { key: 'ipv4', label: 'IPv4' },
    { key: 'clientVendor', label: 'Vendor' },
    { key: 'port', label: 'Port' },
    { key: 'vlanId', label: 'VLAN ID' },
    { key: 'clientOperatingSystem', label: 'OS' },
    { key: 'clientFunction', label: 'Function' },
    { key: 'role', label: 'Role' },
    { key: 'clientTags', label: 'Tags' },
    { key: 'clientManufacturer', label: 'Manufacturer' },
    { key: 'clientCategory', label: 'Category' },
    { key: 'authenticationType', label: 'Auth Type' },
];

const columns: ColumnDef<ClientDetailsRow>[] = [
    { accessorKey: 'ipv4', header: 'IPv4' },
    { accessorKey: 'clientVendor', header: 'Vendor' },
    {
        accessorKey: 'port',
        header: 'Port',
        cell: ({ row }) => (
            <span className="font-mono text-sm">
                {row.original.port || '—'}
            </span>
        ),
    },
    { accessorKey: 'vlanId', header: 'VLAN ID' },
    { accessorKey: 'clientOperatingSystem', header: 'OS' },
    { accessorKey: 'clientFunction', header: 'Function' },
    { accessorKey: 'role', header: 'Role' },
    {
        accessorKey: 'clientTags',
        header: 'Tags',
        cell: ({ row }) => {
            const tags = splitClientTags(row.original.clientTags);
            if (tags.length === 0) {
                return '—';
            }

            return (
                <div className="flex flex-wrap gap-1">
                    {tags.map((tag) => (
                        <Badge key={tag} variant="secondary">
                            {tag}
                        </Badge>
                    ))}
                </div>
            );
        },
    },
    { accessorKey: 'clientManufacturer', header: 'Manufacturer' },
    { accessorKey: 'clientCategory', header: 'Category' },
    { accessorKey: 'authenticationType', header: 'Auth Type' },
];

export default function SwitchClientDetailsCard({
    serial,
    interfaces,
    onClose,
}: SwitchClientDetailsCardProps) {
    const [state, setState] = useState<CardState>({ kind: 'loading' });
    const [tableFilters, setTableFilters] = useState<ClientDetailsTableFilters>(
        emptyClientDetailsTableFilters,
    );
    const [searchQuery, setSearchQuery] = useState('');

    const loadClientDetails = useCallback(async () => {
        setState({ kind: 'loading' });
        setTableFilters(emptyClientDetailsTableFilters);
        setSearchQuery('');

        try {
            const response = await fetch(clientDetailsRoute.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...csrfHeaders(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ serial, interfaces }),
            });

            const body = (await response
                .json()
                .catch(() => null)) as ClientDetailsResponse | null;

            if (!response.ok) {
                throw new Error(
                    body?.error ??
                        body?.message ??
                        `Failed to load client details (HTTP ${response.status}).`,
                );
            }

            if (body === null) {
                throw new Error('Failed to load client details: empty response.');
            }

            if (body.error) {
                throw new Error(body.error);
            }

            setState({
                kind: 'ready',
                rows: body.rows ?? [],
                errors: body.errors ?? [],
            });
        } catch (error) {
            setState({
                kind: 'error',
                message:
                    error instanceof Error
                        ? error.message
                        : 'Failed to load client details.',
            });
        }
    }, [interfaces, serial]);

    useEffect(() => {
        void loadClientDetails();
    }, [loadClientDetails]);

    const filterOptions = useMemo(() => {
        if (state.kind !== 'ready') {
            return buildClientDetailsFilterOptions([]);
        }

        return buildClientDetailsFilterOptions(state.rows);
    }, [state]);

    const filteredRows = useMemo(() => {
        if (state.kind !== 'ready') {
            return [];
        }

        return filterClientDetailsRows(state.rows, tableFilters, searchQuery);
    }, [searchQuery, state, tableFilters]);

    const hasActiveFilters = useMemo(
        () =>
            hasActiveClientDetailsTableFilters(tableFilters) ||
            searchQuery.trim() !== '',
        [searchQuery, tableFilters],
    );

    useEffect(() => {
        setTableFilters((prev) => {
            let changed = false;
            const next = { ...prev };

            (Object.keys(prev) as (keyof ClientDetailsTableFilters)[]).forEach(
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
        setTableFilters(emptyClientDetailsTableFilters);
        setSearchQuery('');
        onClose();
    };

    return (
        <Card
            className="mt-4 gap-0 py-0"
            data-test="device-details-client-details-card"
        >
            <div className="border-b border-border px-6 py-4">
                <div className="flex items-center justify-between gap-2">
                    <h3 className="text-sm font-semibold">Client Details</h3>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-8 shrink-0"
                        onClick={handleClose}
                        aria-label="Close client details"
                        data-test="device-details-client-details-close"
                    >
                        <X className="size-4" aria-hidden />
                    </Button>
                </div>
            </div>

            <CardContent className="px-6 py-4">
                {state.kind === 'loading' ? (
                    <div
                        className="flex items-center gap-2 text-sm text-muted-foreground"
                        data-test="device-details-client-details-loading"
                    >
                        <Loader2 className="size-4 animate-spin" aria-hidden />
                        Loading client details…
                    </div>
                ) : null}

                {state.kind === 'error' ? (
                    <p
                        className="text-sm text-destructive"
                        role="alert"
                        data-test="device-details-client-details-error"
                    >
                        {state.message}
                    </p>
                ) : null}

                {state.kind === 'ready' ? (
                    <div className="space-y-4">
                        {state.errors.length > 0 ? (
                            <div
                                className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
                                data-test="device-details-client-details-soft-errors"
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
                            className="flex flex-col gap-2 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between"
                            data-test="device-details-client-details-meta"
                        >
                            <p data-test="device-details-client-details-count">
                                {hasActiveFilters &&
                                filteredRows.length !== state.rows.length
                                    ? `Showing ${filteredRows.length} of ${state.rows.length}`
                                    : `${state.rows.length} client${state.rows.length === 1 ? '' : 's'}`}
                            </p>
                        </div>

                        {state.rows.length === 0 ? (
                            <p
                                className="text-sm text-muted-foreground"
                                data-test="device-details-client-details-empty"
                            >
                                No client details found for the selected
                                interfaces.
                            </p>
                        ) : (
                            <>
                                <Input
                                    value={searchQuery}
                                    onChange={(e) =>
                                        setSearchQuery(e.target.value)
                                    }
                                    placeholder="Search role or tags…"
                                    data-test="device-details-client-details-search"
                                />

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
                                            data-test={`device-details-client-details-filter-${field.key}`}
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
                                            onClick={() => {
                                                setTableFilters(
                                                    emptyClientDetailsTableFilters,
                                                );
                                                setSearchQuery('');
                                            }}
                                            data-test="device-details-client-details-clear-filters"
                                        >
                                            Clear table filters
                                        </Button>
                                    </div>
                                ) : null}

                                {filteredRows.length === 0 ? (
                                    <p
                                        className="text-sm text-muted-foreground"
                                        data-test="device-details-client-details-no-matches"
                                    >
                                        No clients match.
                                    </p>
                                ) : (
                                    <div
                                        className="max-h-[28rem] overflow-auto rounded-md border border-border"
                                        data-test="device-details-client-details-results"
                                    >
                                        <DataTable
                                            columns={columns}
                                            data={filteredRows}
                                            getRowId={(row, index) =>
                                                `${row.macAddress}-${row.interface}-${index}`
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
