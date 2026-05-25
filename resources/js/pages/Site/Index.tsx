import { router, usePage } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { Search } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { DataTable } from '@/components/ui/data-table';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { index as clientsIndex } from '@/routes/clients';
import { index as sitesIndex } from '@/routes/sites';
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
    siteName: string;
};

type SiteFilters = {
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

type SiteIndexProps = {
    devices: DeviceRow[];
    filters: SiteFilters;
    site_options: SiteOption[];
    central_error: string | null;
    has_active_filters: boolean;
    device_type_options: string[];
    status_options: string[];
    deployment_options: string[];
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

function filtersMatchQuery(current: SiteFilters, next: SiteFilters): boolean {
    return (Object.keys(current) as (keyof SiteFilters)[]).every(
        (key) => (current[key] ?? '').trim() === (next[key] ?? '').trim(),
    );
}

function buildQueryFromFilters(filters: SiteFilters): Record<string, string> {
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

const selectClassName =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs';

export default function Index() {
    const {
        current_client,
        devices,
        filters,
        site_options,
        central_error,
        has_active_filters,
        device_type_options,
        status_options,
        deployment_options,
    } = usePage<SiteIndexProps>().props;

    const [localFilters, setLocalFilters] = useState<SiteFilters>(filters);

    useEffect(() => {
        setLocalFilters(filters);
    }, [filters]);

    const updateFilter = useCallback((patch: Partial<SiteFilters>) => {
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

    useEffect(() => {
        if (filtersMatchQuery(filters, localFilters)) {
            return;
        }

        const handle = window.setTimeout(() => {
            router.get(
                sitesIndex.url({ query: buildQueryFromFilters(localFilters) }),
                {},
                {
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                    only: ['devices', 'filters', 'central_error', 'has_active_filters'],
                },
            );
        }, 350);

        return () => window.clearTimeout(handle);
    }, [localFilters, filters]);

    const columns = useMemo<ColumnDef<DeviceRow>[]>(
        () => [
            { accessorKey: 'deviceName', header: 'Device Name' },
            { accessorKey: 'serialNumber', header: 'Serial Number' },
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
            title: 'Sites',
            href: sitesIndex().url,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="mx-auto max-w-7xl px-4">
                <h1 className="text-center text-3xl font-semibold">Sites</h1>
                <p className="mt-2 text-center text-sm text-muted-foreground">
                    Filter Central devices by site and device attributes. Add at least one filter to search.
                </p>

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
                        data-test="sites-filter-site-id"
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
                        data-test="sites-filter-site-name"
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
                            data-test="sites-filter-serial-number"
                        />
                    </div>
                    <Input
                        type="search"
                        value={localFilters.device_name}
                        onChange={(e) => updateFilter({ device_name: e.target.value })}
                        placeholder="Device name"
                        data-test="sites-filter-device-name"
                    />
                    <select
                        value={localFilters.device_type}
                        onChange={(e) => updateFilter({ device_type: e.target.value })}
                        className={selectClassName}
                        data-test="sites-filter-device-type"
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
                        data-test="sites-filter-status"
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
                        data-test="sites-filter-model"
                    />
                    <Input
                        type="search"
                        value={localFilters.firmware_version}
                        onChange={(e) => updateFilter({ firmware_version: e.target.value })}
                        placeholder="Firmware version"
                        data-test="sites-filter-firmware-version"
                    />
                    <select
                        value={localFilters.deployment}
                        onChange={(e) => updateFilter({ deployment: e.target.value })}
                        className={selectClassName}
                        data-test="sites-filter-deployment"
                    >
                        <option value="">All deployments</option>
                        {deployment_options.map((deployment) => (
                            <option key={deployment} value={deployment}>
                                {deployment}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="mt-4">
                    {!has_active_filters ? (
                        <p className="text-center text-sm text-muted-foreground" data-test="sites-empty-filters">
                            Select at least one filter to load devices from Central.
                        </p>
                    ) : (
                        <>
                            <p className="mb-3 text-sm text-muted-foreground" data-test="sites-result-count">
                                {devices.length === 1
                                    ? '1 device'
                                    : `${devices.length} devices`}
                            </p>
                            <DataTable<DeviceRow, unknown>
                                data={devices}
                                columns={columns}
                                getRowId={(row) => row.serialNumber || row.deviceName}
                            />
                            {devices.length === 0 && !central_error && (
                                <p
                                    className="mt-4 text-center text-sm text-muted-foreground"
                                    data-test="sites-no-results"
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
