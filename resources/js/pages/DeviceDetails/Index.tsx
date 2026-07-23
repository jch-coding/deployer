import { Link, router, usePage } from '@inertiajs/react';
import type { ColumnDef, RowSelectionState } from '@tanstack/react-table';
import { Search } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DataTable } from '@/components/ui/data-table';
import { Input } from '@/components/ui/input';
import CentralScopeRefreshButtons, {
    type CentralScopeCacheMeta,
    type CentralScopeGroupsCacheMeta,
} from '@/components/central/CentralScopeRefreshButtons';
import AppLayout from '@/layouts/app-layout';
import { index as clientsIndex } from '@/routes/clients';
import { index as deviceDetailsIndex, show as deviceDetailsShow } from '@/routes/device-details';
import type { BreadcrumbItem, SharedData } from '@/types';

type SiteOption = {
    siteId: string;
    siteName: string;
};

type DeviceRow = {
    deviceName: string;
    serialNumber: string;
    deviceFunction: string;
    model: string;
    ipv4: string;
    status: string;
    deployment: string;
    siteName: string;
};

type DeviceDetailsFilters = {
    site_id: string;
    site_name: string;
    serial_number: string;
    device_name: string;
    device_type: string;
    status: string;
    model: string;
    firmware_version: string;
    deployment: string;
};

type DeviceDetailsIndexProps = {
    devices: DeviceRow[];
    filters: DeviceDetailsFilters;
    site_options: SiteOption[];
    central_error: string | null;
    has_active_filters: boolean;
    device_type_options: string[];
    status_options: string[];
    deployment_options: string[];
    central_sites_cache: CentralScopeCacheMeta;
    central_groups_cache: CentralScopeGroupsCacheMeta;
} & SharedData;

function statusBadgeClass(status: string): string {
    switch (status) {
        case 'ONLINE':
            return 'bg-emerald-100 text-emerald-800 border-emerald-200';
        case 'OFFLINE':
            return 'bg-red-100 text-red-800 border-red-200';
        default:
            return '';
    }
}

function filtersMatchQuery(current: DeviceDetailsFilters, next: DeviceDetailsFilters): boolean {
    return (Object.keys(current) as (keyof DeviceDetailsFilters)[]).every(
        (key) => (current[key] ?? '').trim() === (next[key] ?? '').trim(),
    );
}

function buildQueryFromFilters(filters: DeviceDetailsFilters): Record<string, string> {
    const query: Record<string, string> = {};
    if (filters.site_id.trim() !== '') {
        query.site_id = filters.site_id.trim();
    }
    if (filters.site_name.trim() !== '') {
        query.site_name = filters.site_name.trim();
    }
    if (filters.serial_number.trim() !== '') {
        query.serial_number = filters.serial_number.trim();
    }
    if (filters.device_name.trim() !== '') {
        query.device_name = filters.device_name.trim();
    }
    if (filters.device_type !== '') {
        query.device_type = filters.device_type;
    }
    if (filters.status !== '') {
        query.status = filters.status;
    }
    if (filters.model.trim() !== '') {
        query.model = filters.model.trim();
    }
    if (filters.firmware_version.trim() !== '') {
        query.firmware_version = filters.firmware_version.trim();
    }
    if (filters.deployment !== '') {
        query.deployment = filters.deployment;
    }

    return query;
}

function hasActiveFilters(filters: DeviceDetailsFilters): boolean {
    return (Object.keys(filters) as (keyof DeviceDetailsFilters)[]).some(
        (key) => (filters[key] ?? '').trim() !== '',
    );
}

function showUrlForSerials(serials: string[]): string {
    return deviceDetailsShow.url({
        query: {
            serials,
        },
    });
}

const selectClassName =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs';

export default function Index() {
    const {
        current_client,
        devices,
        filters,
        site_options,
        central_error,
        device_type_options,
        status_options,
        deployment_options,
        central_sites_cache,
        central_groups_cache,
    } = usePage<DeviceDetailsIndexProps>().props;

    const [localFilters, setLocalFilters] = useState<DeviceDetailsFilters>(filters);
    const [isSearching, setIsSearching] = useState(false);
    const [pageSize, setPageSize] = useState<10 | 25 | 50 | 100>(25);
    const [pageIndex, setPageIndex] = useState(0);
    const [rowSelection, setRowSelection] = useState<RowSelectionState>({});

    const hasActiveLocalFilters = useMemo(() => hasActiveFilters(localFilters), [localFilters]);

    const selectedSerials = useMemo(
        () =>
            Object.keys(rowSelection)
                .filter((key) => rowSelection[key] && key.trim() !== '')
                .map((serial) => serial),
        [rowSelection],
    );

    useEffect(() => {
        setLocalFilters(filters);
        setIsSearching(false);
    }, [filters]);

    useEffect(() => {
        setPageIndex(0);
        setRowSelection({});
    }, [devices, pageSize]);

    const updateFilter = useCallback((patch: Partial<DeviceDetailsFilters>) => {
        setLocalFilters((prev) => ({ ...prev, ...patch }));
    }, []);

    const handleSiteIdChange = useCallback(
        (siteId: string) => {
            const match = site_options.find((s) => s.siteId === siteId);
            updateFilter({
                site_id: siteId,
                site_name: match?.siteName ?? '',
            });
        },
        [site_options, updateFilter],
    );

    const handleSiteNameChange = useCallback(
        (siteName: string) => {
            const match = site_options.find((s) => s.siteName === siteName);
            updateFilter({
                site_name: siteName,
                site_id: match?.siteId ?? '',
            });
        },
        [site_options, updateFilter],
    );

    const submitSearch = useCallback(() => {
        if (isSearching) {
            return;
        }

        if (filtersMatchQuery(filters, localFilters) && devices.length > 0) {
            return;
        }

        setIsSearching(true);

        router.get(
            deviceDetailsIndex.url({
                query: {
                    ...buildQueryFromFilters(localFilters),
                    submitted: '1',
                },
            }),
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['devices', 'filters', 'central_error', 'has_active_filters'],
                onFinish: () => setIsSearching(false),
            },
        );
    }, [devices.length, filters, isSearching, localFilters]);

    const viewSelected = useCallback(() => {
        if (selectedSerials.length === 0) {
            return;
        }

        router.get(showUrlForSerials(selectedSerials));
    }, [selectedSerials]);

    const columns = useMemo<ColumnDef<DeviceRow>[]>(
        () => [
            {
                id: 'select',
                header: ({ table }) => (
                    <Checkbox
                        checked={
                            table.getIsAllPageRowsSelected() ||
                            (table.getIsSomePageRowsSelected() && 'indeterminate')
                        }
                        onCheckedChange={(value) => table.toggleAllPageRowsSelected(!!value)}
                        aria-label="Select all on page"
                        data-test="device-details-select-all"
                    />
                ),
                cell: ({ row }) => (
                    <Checkbox
                        checked={row.getIsSelected()}
                        onCheckedChange={(value) => row.toggleSelected(!!value)}
                        aria-label={`Select ${row.original.serialNumber}`}
                        disabled={row.original.serialNumber === ''}
                        data-test="device-details-select-row"
                    />
                ),
                enableSorting: false,
                enableHiding: false,
            },
            {
                accessorKey: 'deviceName',
                header: 'Device Name',
                cell: ({ row }) => {
                    const serial = row.original.serialNumber;
                    if (serial === '') {
                        return row.original.deviceName;
                    }

                    return (
                        <Link
                            href={showUrlForSerials([serial])}
                            className="text-primary underline-offset-4 hover:underline"
                            data-test="device-details-link-name"
                        >
                            {row.original.deviceName || serial}
                        </Link>
                    );
                },
            },
            {
                accessorKey: 'serialNumber',
                header: 'Serial Number',
                cell: ({ row }) => {
                    const serial = row.original.serialNumber;
                    if (serial === '') {
                        return '';
                    }

                    return (
                        <Link
                            href={showUrlForSerials([serial])}
                            className="text-primary underline-offset-4 hover:underline"
                            data-test="device-details-link-serial"
                        >
                            {serial}
                        </Link>
                    );
                },
            },
            { accessorKey: 'deviceFunction', header: 'Device Function' },
            { accessorKey: 'model', header: 'Model' },
            { accessorKey: 'ipv4', header: 'IPv4' },
            {
                accessorKey: 'status',
                header: 'Status',
                cell: ({ row }) => (
                    <Badge variant="outline" className={statusBadgeClass(row.original.status)}>
                        {row.original.status}
                    </Badge>
                ),
            },
            { accessorKey: 'deployment', header: 'Deployment' },
            { accessorKey: 'siteName', header: 'Site Name' },
        ],
        [],
    );

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: current_client?.name ?? 'Clients',
            href: clientsIndex().url,
        },
        {
            title: 'Device Details',
            href: deviceDetailsIndex().url,
        },
    ];

    const totalDevices = devices.length;
    const totalPages = Math.max(1, Math.ceil(totalDevices / pageSize));
    const safePageIndex = Math.min(pageIndex, totalPages - 1);
    const start = safePageIndex * pageSize;
    const end = Math.min(start + pageSize, totalDevices);
    const pagedDevices = useMemo(() => devices.slice(start, end), [devices, end, start]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="mx-auto max-w-7xl px-4">
                <h1 className="text-center text-3xl font-semibold">Device Details</h1>
                <p className="mt-2 text-center text-sm text-muted-foreground">
                    Search Central devices, then select one or more switches to view interfaces.
                </p>

                <div className="mt-4 flex justify-center">
                    <CentralScopeRefreshButtons
                        centralSitesCache={central_sites_cache}
                        centralGroupsCache={central_groups_cache}
                        reloadOnly={[
                            'central_sites_cache',
                            'central_groups_cache',
                            'site_options',
                            'central_error',
                        ]}
                    />
                </div>

                {central_error && (
                    <div
                        className="mt-4 rounded-md border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive"
                        role="alert"
                    >
                        {central_error}
                    </div>
                )}

                <div className="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <select
                        value={localFilters.site_id}
                        onChange={(e) => handleSiteIdChange(e.target.value)}
                        className={selectClassName}
                        data-test="device-details-filter-site-id"
                    >
                        <option value="">All sites (by ID)</option>
                        {site_options.map((site) => (
                            <option key={site.siteId} value={site.siteId}>
                                {site.siteName} ({site.siteId})
                            </option>
                        ))}
                    </select>
                    <select
                        value={localFilters.site_name}
                        onChange={(e) => handleSiteNameChange(e.target.value)}
                        className={selectClassName}
                        data-test="device-details-filter-site-name"
                    >
                        <option value="">All sites (by name)</option>
                        {site_options.map((site) => (
                            <option key={site.siteId} value={site.siteName}>
                                {site.siteName}
                            </option>
                        ))}
                    </select>
                    <div className="relative">
                        <Search
                            className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden
                        />
                        <Input
                            type="search"
                            value={localFilters.serial_number}
                            onChange={(e) => updateFilter({ serial_number: e.target.value })}
                            placeholder="Serial number"
                            className="pl-9"
                            data-test="device-details-filter-serial-number"
                        />
                    </div>
                    <Input
                        type="search"
                        value={localFilters.device_name}
                        onChange={(e) => updateFilter({ device_name: e.target.value })}
                        placeholder="Device name"
                        data-test="device-details-filter-device-name"
                    />
                    <select
                        value={localFilters.device_type}
                        onChange={(e) => updateFilter({ device_type: e.target.value })}
                        className={selectClassName}
                        data-test="device-details-filter-device-type"
                    >
                        <option value="">All device types</option>
                        {device_type_options.map((type) => (
                            <option key={type} value={type}>
                                {type}
                            </option>
                        ))}
                    </select>
                    <select
                        value={localFilters.status}
                        onChange={(e) => updateFilter({ status: e.target.value })}
                        className={selectClassName}
                        data-test="device-details-filter-status"
                    >
                        <option value="">All statuses</option>
                        {status_options.map((status) => (
                            <option key={status} value={status}>
                                {status}
                            </option>
                        ))}
                    </select>
                    <Input
                        type="search"
                        value={localFilters.model}
                        onChange={(e) => updateFilter({ model: e.target.value })}
                        placeholder="Model"
                        data-test="device-details-filter-model"
                    />
                    <Input
                        type="search"
                        value={localFilters.firmware_version}
                        onChange={(e) => updateFilter({ firmware_version: e.target.value })}
                        placeholder="Firmware version"
                        data-test="device-details-filter-firmware-version"
                    />
                    <select
                        value={localFilters.deployment}
                        onChange={(e) => updateFilter({ deployment: e.target.value })}
                        className={selectClassName}
                        data-test="device-details-filter-deployment"
                    >
                        <option value="">All deployments</option>
                        {deployment_options.map((deployment) => (
                            <option key={deployment} value={deployment}>
                                {deployment}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="mt-4 flex flex-wrap items-center justify-center gap-3">
                    <Button
                        type="button"
                        onClick={submitSearch}
                        disabled={!hasActiveLocalFilters || isSearching}
                        className="gap-2"
                        data-test="device-details-search-button"
                    >
                        <Search className="size-4" aria-hidden />
                        {isSearching ? 'Searching…' : 'Search'}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={viewSelected}
                        disabled={selectedSerials.length === 0}
                        data-test="device-details-view-selected"
                    >
                        View selected ({selectedSerials.length})
                    </Button>
                </div>

                <div className="mt-4">
                    {!hasActiveLocalFilters ? (
                        <p
                            className="text-center text-sm text-muted-foreground"
                            data-test="device-details-empty-filters"
                        >
                            Select at least one filter to load devices from Central.
                        </p>
                    ) : (
                        <>
                            <div className="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <div
                                    className="text-sm text-muted-foreground"
                                    data-test="device-details-result-count"
                                >
                                    {totalDevices === 1 ? '1 device' : `${totalDevices} devices`}
                                    {totalDevices > 0 ? ` (showing ${start + 1}–${end})` : null}
                                </div>
                                <div className="flex items-center gap-2">
                                    <span className="text-sm text-muted-foreground">Per page</span>
                                    <select
                                        value={pageSize}
                                        onChange={(e) => {
                                            const next = Number(e.target.value);
                                            if (
                                                next === 10 ||
                                                next === 25 ||
                                                next === 50 ||
                                                next === 100
                                            ) {
                                                setPageSize(next);
                                            }
                                        }}
                                        className={selectClassName}
                                        data-test="device-details-page-size"
                                    >
                                        <option value={10}>10</option>
                                        <option value={25}>25</option>
                                        <option value={50}>50</option>
                                        <option value={100}>100</option>
                                    </select>
                                </div>
                            </div>
                            <DataTable<DeviceRow, unknown>
                                data={pagedDevices}
                                columns={columns}
                                getRowId={(row) => row.serialNumber || row.deviceName}
                                enableRowSelection
                                rowSelection={rowSelection}
                                onRowSelectionChange={setRowSelection}
                            />
                            {totalDevices > 0 ? (
                                <div className="mt-3 flex items-center justify-center gap-3">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setPageIndex((p) => Math.max(0, p - 1))}
                                        disabled={safePageIndex <= 0}
                                        data-test="device-details-page-prev"
                                    >
                                        Prev
                                    </Button>
                                    <span
                                        className="text-sm text-muted-foreground"
                                        data-test="device-details-page-indicator"
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
                                        data-test="device-details-page-next"
                                    >
                                        Next
                                    </Button>
                                </div>
                            ) : null}
                            {devices.length === 0 && !central_error && (
                                <p
                                    className="mt-4 text-center text-sm text-muted-foreground"
                                    data-test="device-details-no-results"
                                >
                                    No devices match the current filters.
                                </p>
                            )}
                        </>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
