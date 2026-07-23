import { Link, usePage } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { Download } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import AppLayout from '@/layouts/app-layout';
import {
    downloadSwitchInterfacesCsv,
    type SwitchInterfaceRow,
} from '@/lib/switch-interfaces-csv';
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
    'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs sm:w-auto';

export default function Show() {
    const { current_client, serial, device_name, interfaces, central_error } =
        usePage<DeviceDetailsShowProps>().props;

    const [pageSize, setPageSize] = useState<10 | 25 | 50 | 100>(25);
    const [pageIndex, setPageIndex] = useState(0);

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

    const totalInterfaces = interfaces.length;
    const totalPages = Math.max(1, Math.ceil(totalInterfaces / pageSize));
    const safePageIndex = Math.min(pageIndex, totalPages - 1);
    const start = safePageIndex * pageSize;
    const end = Math.min(start + pageSize, totalInterfaces);
    const pagedInterfaces = useMemo(
        () => interfaces.slice(start, end),
        [interfaces, end, start],
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
                            <p className="mt-1 text-sm text-muted-foreground" data-test="device-details-show-serial">
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
                        <h2 className="text-xl font-semibold" data-test="device-details-interfaces-heading">
                            Interfaces
                        </h2>
                        <Button
                            type="button"
                            variant="outline"
                            className="gap-2"
                            disabled={interfaces.length === 0}
                            onClick={() => downloadSwitchInterfacesCsv(interfaces, serial)}
                            data-test="device-details-export-csv"
                        >
                            <Download className="size-4" aria-hidden />
                            Export CSV
                        </Button>
                    </div>

                    <div className="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div className="text-sm text-muted-foreground" data-test="device-details-interfaces-count">
                            {totalInterfaces === 1
                                ? '1 interface'
                                : `${totalInterfaces} interfaces`}
                            {totalInterfaces > 0 ? ` (showing ${start + 1}–${end})` : null}
                        </div>
                        <div className="flex items-center gap-2">
                            <span className="text-sm text-muted-foreground">Per page</span>
                            <select
                                value={pageSize}
                                onChange={(e) => {
                                    const next = Number(e.target.value);
                                    if (next === 10 || next === 25 || next === 50 || next === 100) {
                                        setPageSize(next);
                                        setPageIndex(0);
                                    }
                                }}
                                className={selectClassName}
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

                    {totalInterfaces > 0 ? (
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
                                onClick={() => setPageIndex((p) => Math.min(totalPages - 1, p + 1))}
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
                </section>
            </div>
        </AppLayout>
    );
}
