import { Link, usePage } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { Download } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import AppLayout from '@/layouts/app-layout';
import {
    downloadSwitchInterfacesCsv,
    formatAllowedVlanIds,
    type SwitchInterfaceRow,
} from '@/lib/switch-interfaces-csv';
import {
    buildSwitchInterfaceFilterOptions,
    emptySwitchInterfacesTableFilters,
    filterSwitchInterfaces,
    hasActiveSwitchInterfacesTableFilters,
    type SwitchInterfacesTableFilters,
} from '@/lib/switch-interfaces-table-filters';
import { index as clientsIndex } from '@/routes/clients';
import { index as deviceDetailsIndex } from '@/routes/device-details';
import type { BreadcrumbItem, SharedData } from '@/types';

type DeviceDetailsShowProps = {
    serial: string;
    device_name: string;
    interfaces: SwitchInterfaceRow[];
    central_error: string | null;
} & SharedData;

function statusBadgeClass(status: string): string {
    switch (status) {
        case 'Connected':
        case 'Up':
            return 'bg-emerald-100 text-emerald-800 border-emerald-200';
        case 'Not Connected':
        case 'Down':
            return 'bg-red-100 text-red-800 border-red-200';
        default:
            return '';
    }
}

const selectClassName =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs';

const filterFields: {
    key: keyof SwitchInterfacesTableFilters;
    label: string;
    testId: string;
}[] = [
    { key: 'name', label: 'Name', testId: 'device-details-iface-filter-name' },
    { key: 'status', label: 'Status', testId: 'device-details-iface-filter-status' },
    {
        key: 'operStatus',
        label: 'Oper Status',
        testId: 'device-details-iface-filter-oper-status',
    },
    { key: 'neighbour', label: 'Neighbour', testId: 'device-details-iface-filter-neighbour' },
    {
        key: 'neighbourSerial',
        label: 'Neighbour Serial',
        testId: 'device-details-iface-filter-neighbour-serial',
    },
    { key: 'vlanMode', label: 'VLAN Mode', testId: 'device-details-iface-filter-vlan-mode' },
    {
        key: 'allowedVlanIds',
        label: 'Allowed VLAN IDs',
        testId: 'device-details-iface-filter-allowed-vlan-ids',
    },
    {
        key: 'nativeVlan',
        label: 'Native VLAN',
        testId: 'device-details-iface-filter-native-vlan',
    },
    { key: 'poeClass', label: 'PoE Class', testId: 'device-details-iface-filter-poe-class' },
    {
        key: 'neighbourFamily',
        label: 'Neighbour Family',
        testId: 'device-details-iface-filter-neighbour-family',
    },
    {
        key: 'neighbourFunction',
        label: 'Neighbour Function',
        testId: 'device-details-iface-filter-neighbour-function',
    },
    {
        key: 'neighbourType',
        label: 'Neighbour Type',
        testId: 'device-details-iface-filter-neighbour-type',
    },
    {
        key: 'transceiverType',
        label: 'Transceiver Type',
        testId: 'device-details-iface-filter-transceiver-type',
    },
];

export default function Show() {
    const { current_client, serial, device_name, interfaces, central_error } =
        usePage<DeviceDetailsShowProps>().props;

    const [pageSize, setPageSize] = useState<10 | 25 | 50 | 100>(25);
    const [pageIndex, setPageIndex] = useState(0);
    const [tableFilters, setTableFilters] = useState<SwitchInterfacesTableFilters>(
        emptySwitchInterfacesTableFilters,
    );

    const filterOptions = useMemo(
        () => buildSwitchInterfaceFilterOptions(interfaces),
        [interfaces],
    );

    const filteredInterfaces = useMemo(
        () => filterSwitchInterfaces(interfaces, tableFilters),
        [interfaces, tableFilters],
    );

    const hasActiveTableFilters = useMemo(
        () => hasActiveSwitchInterfacesTableFilters(tableFilters),
        [tableFilters],
    );

    useEffect(() => {
        setPageIndex(0);
    }, [tableFilters, pageSize, interfaces]);

    useEffect(() => {
        setTableFilters((prev) => {
            let changed = false;
            const next = { ...prev };

            (Object.keys(prev) as (keyof SwitchInterfacesTableFilters)[]).forEach((key) => {
                const selected = prev[key];
                if (selected !== '' && !filterOptions[key].includes(selected)) {
                    next[key] = '';
                    changed = true;
                }
            });

            return changed ? next : prev;
        });
    }, [filterOptions]);

    const columns = useMemo<ColumnDef<SwitchInterfaceRow>[]>(
        () => [
            { accessorKey: 'name', header: 'Name' },
            {
                accessorKey: 'status',
                header: 'Status',
                cell: ({ row }) => (
                    <Badge variant="outline" className={statusBadgeClass(row.original.status)}>
                        {row.original.status}
                    </Badge>
                ),
            },
            {
                accessorKey: 'operStatus',
                header: 'Oper Status',
                cell: ({ row }) => (
                    <Badge variant="outline" className={statusBadgeClass(row.original.operStatus)}>
                        {row.original.operStatus}
                    </Badge>
                ),
            },
            { accessorKey: 'neighbour', header: 'Neighbour' },
            { accessorKey: 'neighbourSerial', header: 'Neighbour Serial' },
            { accessorKey: 'vlanMode', header: 'VLAN Mode' },
            {
                accessorKey: 'allowedVlanIds',
                header: 'Allowed VLAN IDs',
                cell: ({ row }) => formatAllowedVlanIds(row.original.allowedVlanIds),
            },
            { accessorKey: 'nativeVlan', header: 'Native VLAN' },
            { accessorKey: 'poeClass', header: 'PoE Class' },
            { accessorKey: 'neighbourFamily', header: 'Neighbour Family' },
            { accessorKey: 'neighbourFunction', header: 'Neighbour Function' },
            { accessorKey: 'neighbourType', header: 'Neighbour Type' },
            { accessorKey: 'transceiverType', header: 'Transceiver Type' },
        ],
        [],
    );

    const title = device_name !== '' ? device_name : serial;

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: current_client?.name ?? 'Clients',
            href: clientsIndex().url,
        },
        {
            title: 'Device Details',
            href: deviceDetailsIndex().url,
        },
        {
            title,
            href: '#',
        },
    ];

    const totalFiltered = filteredInterfaces.length;
    const totalPages = Math.max(1, Math.ceil(totalFiltered / pageSize));
    const safePageIndex = Math.min(pageIndex, totalPages - 1);
    const start = safePageIndex * pageSize;
    const end = Math.min(start + pageSize, totalFiltered);
    const pagedInterfaces = useMemo(
        () => filteredInterfaces.slice(start, end),
        [filteredInterfaces, end, start],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="mx-auto max-w-7xl px-4">
                <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1 className="text-3xl font-semibold" data-test="device-details-show-title">
                            {title}
                        </h1>
                        {device_name !== '' ? (
                            <p
                                className="mt-1 text-sm text-muted-foreground"
                                data-test="device-details-show-serial"
                            >
                                Serial: {serial}
                            </p>
                        ) : null}
                    </div>
                    <Button variant="outline" asChild>
                        <Link href={deviceDetailsIndex().url} data-test="device-details-back-link">
                            Back to search
                        </Link>
                    </Button>
                </div>

                {central_error && (
                    <div
                        className="mt-4 rounded-md border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive"
                        role="alert"
                        data-test="device-details-show-error"
                    >
                        {central_error}
                    </div>
                )}

                <section className="mt-8">
                    <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <h2
                            className="text-xl font-semibold"
                            data-test="device-details-interfaces-heading"
                        >
                            Interfaces
                        </h2>
                        <Button
                            type="button"
                            variant="outline"
                            className="gap-2"
                            disabled={filteredInterfaces.length === 0}
                            onClick={() => downloadSwitchInterfacesCsv(filteredInterfaces, serial)}
                            data-test="device-details-export-csv"
                        >
                            <Download className="size-4" aria-hidden />
                            Export CSV
                        </Button>
                    </div>

                    <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
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
                                data-test={field.testId}
                                disabled={filterOptions[field.key].length === 0}
                            >
                                <option value="">All {field.label}</option>
                                {filterOptions[field.key].map((option) => (
                                    <option key={option} value={option}>
                                        {option}
                                    </option>
                                ))}
                            </select>
                        ))}
                    </div>

                    {hasActiveTableFilters ? (
                        <div className="mb-3 flex justify-end">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => setTableFilters(emptySwitchInterfacesTableFilters)}
                                data-test="device-details-iface-clear-filters"
                            >
                                Clear table filters
                            </Button>
                        </div>
                    ) : null}

                    <div className="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div
                            className="text-sm text-muted-foreground"
                            data-test="device-details-interfaces-count"
                        >
                            {totalFiltered === 0
                                ? '0 interfaces'
                                : `Showing ${start + 1}–${end} of ${totalFiltered}`}
                            {totalFiltered !== interfaces.length
                                ? ` (filtered from ${interfaces.length})`
                                : null}
                        </div>
                        <div className="flex items-center gap-2">
                            <span className="text-sm text-muted-foreground">Per page</span>
                            <select
                                value={pageSize}
                                onChange={(e) => {
                                    const next = Number(e.target.value);
                                    if (next === 10 || next === 25 || next === 50 || next === 100) {
                                        setPageSize(next);
                                    }
                                }}
                                className={`${selectClassName} sm:w-auto`}
                                data-test="device-details-interfaces-page-size"
                            >
                                <option value={10}>10</option>
                                <option value={25}>25</option>
                                <option value={50}>50</option>
                                <option value={100}>100</option>
                            </select>
                        </div>
                    </div>

                    <DataTable<SwitchInterfaceRow, unknown>
                        data={pagedInterfaces}
                        columns={columns}
                        getRowId={(row) => row.name || `${row.neighbourSerial}-${row.status}`}
                    />

                    {totalFiltered > 0 ? (
                        <div className="mt-3 flex items-center justify-center gap-3">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setPageIndex((p) => Math.max(0, p - 1))}
                                disabled={safePageIndex <= 0}
                                data-test="device-details-interfaces-page-prev"
                            >
                                Prev
                            </Button>
                            <span
                                className="text-sm text-muted-foreground"
                                data-test="device-details-interfaces-page-indicator"
                            >
                                Page {safePageIndex + 1} of {totalPages}
                            </span>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    setPageIndex((p) => Math.min(totalPages - 1, p + 1))
                                }
                                disabled={safePageIndex >= totalPages - 1}
                                data-test="device-details-interfaces-page-next"
                            >
                                Next
                            </Button>
                        </div>
                    ) : null}

                    {interfaces.length === 0 && !central_error ? (
                        <p
                            className="mt-4 text-center text-sm text-muted-foreground"
                            data-test="device-details-interfaces-empty"
                        >
                            No interfaces found for this device.
                        </p>
                    ) : null}

                    {interfaces.length > 0 && totalFiltered === 0 ? (
                        <p
                            className="mt-4 text-center text-sm text-muted-foreground"
                            data-test="device-details-interfaces-no-filter-matches"
                        >
                            No interfaces match the current table filters.
                        </p>
                    ) : null}
                </section>
            </div>
        </AppLayout>
    );
}
