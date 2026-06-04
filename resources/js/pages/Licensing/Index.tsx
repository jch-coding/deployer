import { router, usePage } from '@inertiajs/react';
import type { ColumnDef, RowSelectionState } from '@tanstack/react-table';
import { KeyRound, Search } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { DataTable } from '@/components/ui/data-table';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { index as clientsIndex } from '@/routes/clients';
import LicenseSelect, {
    type AvailableSubscription,
} from '@/components/licensing/LicenseSelect';
import { subscriptionTagKeys } from '@/lib/subscription-tags';
import RenewLicensingButton from '@/components/licensing/RenewLicensingButton';
import { assign, index as licensingIndex, remove, unassign } from '@/routes/licensing';
import type { BreadcrumbItem, SharedData } from '@/types';

type LicensingDeviceRow = {
    serial: string;
    model: string;
    mac: string;
    device_type: string;
    name: string;
    licensed: boolean;
    assigned_services: string[];
    subscription_key: string;
    tags: string[] | Record<string, string>;
    subscription_sku: string;
    license_type: string;
    start_date: number | null;
    end_date: number | null;
    subscription_status: string;
    subscription_type: string;
    acpapp_name: string;
    device_sku: string;
    deployer_device_id: number | null;
};

type LicensingFilters = {
    start_date_from: string;
    start_date_to: string;
    end_date_from: string;
    end_date_to: string;
    license_type: string;
    subscription_sku: string;
    service: string;
    serial_number: string;
    device_name: string;
    subscription_key: string;
    subscription_tags: string;
    model: string;
};

const emptyFilters: LicensingFilters = {
    start_date_from: '',
    start_date_to: '',
    end_date_from: '',
    end_date_to: '',
    license_type: '',
    subscription_sku: '',
    service: '',
    serial_number: '',
    device_name: '',
    subscription_key: '',
    subscription_tags: '',
    model: '',
};

type LicensingIndexProps = {
    devices: LicensingDeviceRow[];
    enabled_services: string[];
    available_subscriptions: AvailableSubscription[];
    subscription_summary: {
        total_devices: number;
        licensed_devices: number;
        unlicensed_devices: number;
        available_subscriptions: number;
        subscription_keys: number;
    };
    filter_options: {
        license_types: string[];
        subscription_skus: string[];
    };
    filters: LicensingFilters;
    has_active_filters: boolean;
    central_error: string | null;
    licensing_synced_at: string | null;
} & SharedData;

const selectClassName =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs';

function formatEpochDate(value: number | null): string {
    if (value === null) {
        return '—';
    }

    return new Date(value).toLocaleDateString();
}

function filtersMatchQuery(current: LicensingFilters, next: LicensingFilters): boolean {
    return (Object.keys(current) as (keyof LicensingFilters)[]).every(
        (key) => (current[key] ?? '').trim() === (next[key] ?? '').trim(),
    );
}

function buildQueryFromFilters(filters: LicensingFilters): Record<string, string> {
    const query: Record<string, string> = {};

    (Object.keys(filters) as (keyof LicensingFilters)[]).forEach((key) => {
        const value = (filters[key] ?? '').trim();
        if (value !== '') {
            query[key] = value;
        }
    });

    return query;
}

function licensedBadgeClass(licensed: boolean): string {
    return licensed
        ? 'bg-emerald-100 text-emerald-800 border-emerald-200'
        : 'bg-amber-100 text-amber-800 border-amber-200';
}

export default function Index() {
    const {
        current_client,
        devices,
        enabled_services,
        subscription_summary,
        filter_options,
        filters,
        has_active_filters,
        central_error,
        available_subscriptions,
        licensing_synced_at,
        flash,
    } = usePage<LicensingIndexProps>().props;

    const [localFilters, setLocalFilters] = useState<LicensingFilters>(filters);
    const [isSearching, setIsSearching] = useState(false);
    const [pageSize, setPageSize] = useState<10 | 25 | 50 | 100>(25);
    const [pageIndex, setPageIndex] = useState(0);
    const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
    const [selectedSubscriptionKey, setSelectedSubscriptionKey] = useState(
        available_subscriptions[0]?.subscription_key ?? '',
    );
    const [isSubmitting, setIsSubmitting] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: current_client?.name ?? 'Clients', href: clientsIndex().url },
        { title: 'Licensing', href: licensingIndex().url },
    ];

    useEffect(() => {
        setLocalFilters(filters);
        setIsSearching(false);
    }, [filters]);

    useEffect(() => {
        setPageIndex(0);
    }, [devices, pageSize]);

    const selectedSerials = useMemo(
        () =>
            Object.keys(rowSelection)
                .filter((key) => rowSelection[key])
                .map((serial) => serial),
        [rowSelection],
    );

    useEffect(() => {
        if (available_subscriptions.length === 0) {
            if (selectedSubscriptionKey !== '') {
                setSelectedSubscriptionKey('');
            }

            return;
        }

        const hasSelection = available_subscriptions.some(
            (s) => s.subscription_key === selectedSubscriptionKey,
        );

        if (!hasSelection) {
            setSelectedSubscriptionKey(available_subscriptions[0].subscription_key);
        }
    }, [available_subscriptions, selectedSubscriptionKey]);

    const selectedSubscription = useMemo(
        () => available_subscriptions.find((s) => s.subscription_key === selectedSubscriptionKey),
        [available_subscriptions, selectedSubscriptionKey],
    );

    const selectedDevicesWithSubscription = useMemo(
        () =>
            devices.filter(
                (device) =>
                    selectedSerials.includes(device.serial) &&
                    (device.subscription_key ?? '').trim() !== '',
            ),
        [devices, selectedSerials],
    );

    useEffect(() => {
        if (flash?.success) {
            toast.success(flash.success);
        }
        if (flash?.error) {
            toast.error(flash.error);
        }
    }, [flash?.success, flash?.error]);

    const paginatedDevices = useMemo(() => {
        const start = pageIndex * pageSize;

        return devices.slice(start, start + pageSize);
    }, [devices, pageIndex, pageSize]);

    const totalPages = Math.max(1, Math.ceil(devices.length / pageSize));

    const updateFilter = useCallback((patch: Partial<LicensingFilters>) => {
        setLocalFilters((prev) => ({ ...prev, ...patch }));
    }, []);

    const submitSearch = useCallback(() => {
        if (isSearching || filtersMatchQuery(filters, localFilters)) {
            return;
        }

        setIsSearching(true);
        router.get(
            licensingIndex.url({ query: buildQueryFromFilters(localFilters) }),
            {},
            {
                preserveState: true,
                preserveScroll: true,
                only: [
                    'devices',
                    'filters',
                    'has_active_filters',
                    'subscription_summary',
                    'filter_options',
                    'central_error',
                    'available_subscriptions',
                ],
                onFinish: () => setIsSearching(false),
            },
        );
    }, [filters, isSearching, localFilters]);

    const runAssignAction = useCallback(() => {
        if (selectedSerials.length === 0) {
            toast.error('Select at least one device.');

            return;
        }

        if (!selectedSubscriptionKey) {
            toast.error('Select a license.');

            return;
        }

        if (
            selectedSubscription &&
            selectedSerials.length > selectedSubscription.available
        ) {
            toast.error(
                `Only ${selectedSubscription.available} seat(s) available on this license.`,
            );

            return;
        }

        setIsSubmitting(true);
        router.post(
            assign.url(),
            {
                subscription_key: selectedSubscriptionKey,
                serials: selectedSerials,
            },
            {
                preserveScroll: true,
                onFinish: () => setIsSubmitting(false),
            },
        );
    }, [selectedSerials, selectedSubscription, selectedSubscriptionKey]);

    const runUnassignAction = useCallback(() => {
        if (selectedSerials.length === 0) {
            toast.error('Select at least one device.');

            return;
        }

        if (selectedDevicesWithSubscription.length === 0) {
            toast.error('Selected devices must have a subscription to remove.');

            return;
        }

        setIsSubmitting(true);
        router.post(
            unassign.url(),
            {
                serials: selectedSerials,
            },
            {
                preserveScroll: true,
                onFinish: () => setIsSubmitting(false),
            },
        );
    }, [selectedSerials, selectedDevicesWithSubscription.length]);

    const runRemoveFromWorkspaceAction = useCallback(() => {
        if (selectedSerials.length === 0) {
            toast.error('Select at least one device.');

            return;
        }

        if (
            !window.confirm(
                `Remove ${selectedSerials.length} device(s) from the GreenLake workspace? This unassigns their subscription and application.`,
            )
        ) {
            return;
        }

        setIsSubmitting(true);
        router.post(
            remove.url(),
            {
                serials: selectedSerials,
            },
            {
                preserveScroll: true,
                onSuccess: () => setRowSelection({}),
                onFinish: () => setIsSubmitting(false),
            },
        );
    }, [selectedSerials]);

    const clearFilters = useCallback(() => {
        setLocalFilters(emptyFilters);
        setIsSearching(true);
        router.get(
            licensingIndex.url(),
            {},
            {
                preserveState: true,
                preserveScroll: true,
                only: [
                    'devices',
                    'filters',
                    'has_active_filters',
                    'subscription_summary',
                    'filter_options',
                    'central_error',
                    'available_subscriptions',
                ],
                onFinish: () => setIsSearching(false),
            },
        );
    }, []);

    const columns = useMemo<ColumnDef<LicensingDeviceRow>[]>(
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
                    />
                ),
                cell: ({ row }) => (
                    <Checkbox
                        checked={row.getIsSelected()}
                        onCheckedChange={(value) => row.toggleSelected(!!value)}
                        aria-label={`Select ${row.original.serial}`}
                    />
                ),
                enableSorting: false,
                enableHiding: false,
            },
            { accessorKey: 'serial', header: 'Serial' },
            { accessorKey: 'name', header: 'Name' },
            { accessorKey: 'model', header: 'Model' },
            { accessorKey: 'device_type', header: 'Type' },
            {
                accessorKey: 'licensed',
                header: 'Licensed',
                cell: ({ row }) => (
                    <Badge variant="outline" className={licensedBadgeClass(row.original.licensed)}>
                        {row.original.licensed ? 'Yes' : 'No'}
                    </Badge>
                ),
            },
            {
                accessorKey: 'assigned_services',
                header: 'Services',
                cell: ({ row }) =>
                    row.original.assigned_services.length > 0
                        ? row.original.assigned_services.join(', ')
                        : '—',
            },
            {
                accessorKey: 'subscription_key',
                header: 'Subscription key',
                cell: ({ row }) => {
                    const key = row.original.subscription_key || '—';
                    const tags = subscriptionTagKeys(row.original.tags);

                    if (tags.length === 0) {
                        return key;
                    }

                    return (
                        <div className="flex flex-col gap-1">
                            <span>{key}</span>
                            <div className="flex flex-wrap gap-1">
                                {tags.map((tag) => (
                                    <Badge key={tag} variant="secondary" className="text-xs font-normal">
                                        {tag}
                                    </Badge>
                                ))}
                            </div>
                        </div>
                    );
                },
            },
            { accessorKey: 'license_type', header: 'License type' },
            {
                accessorKey: 'start_date',
                header: 'Start',
                cell: ({ row }) => formatEpochDate(row.original.start_date),
            },
            {
                accessorKey: 'end_date',
                header: 'End',
                cell: ({ row }) => formatEpochDate(row.original.end_date),
            },
            { accessorKey: 'subscription_sku', header: 'Subscription SKU' },
            { accessorKey: 'device_sku', header: 'Device SKU' },
            { accessorKey: 'subscription_status', header: 'Status' },
        ],
        [],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex items-center gap-2">
                        <KeyRound className="size-6 text-muted-foreground" />
                        <h1 className="text-2xl font-semibold tracking-tight">Licensing</h1>
                    </div>
                    <RenewLicensingButton
                        licensingSyncedAt={licensing_synced_at}
                        showSyncedHint
                    />
                </div>

                {central_error && (
                    <div className="rounded-md border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive">
                        {central_error}
                    </div>
                )}

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Devices
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold">
                            {subscription_summary.total_devices}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Licensed
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold">
                            {subscription_summary.licensed_devices}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Unlicensed
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold">
                            {subscription_summary.unlicensed_devices}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Available pool
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold">
                            {subscription_summary.available_subscriptions}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Subscription keys
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold">
                            {subscription_summary.subscription_keys}
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Input
                        type="date"
                        value={localFilters.start_date_from}
                        onChange={(e) => updateFilter({ start_date_from: e.target.value })}
                        aria-label="Start date from"
                    />
                    <Input
                        type="date"
                        value={localFilters.start_date_to}
                        onChange={(e) => updateFilter({ start_date_to: e.target.value })}
                        aria-label="Start date to"
                    />
                    <Input
                        type="date"
                        value={localFilters.end_date_from}
                        onChange={(e) => updateFilter({ end_date_from: e.target.value })}
                        aria-label="End date from"
                    />
                    <Input
                        type="date"
                        value={localFilters.end_date_to}
                        onChange={(e) => updateFilter({ end_date_to: e.target.value })}
                        aria-label="End date to"
                    />
                    <select
                        value={localFilters.license_type}
                        onChange={(e) => updateFilter({ license_type: e.target.value })}
                        className={selectClassName}
                    >
                        <option value="">All license types</option>
                        {filter_options.license_types.map((type) => (
                            <option key={type} value={type}>
                                {type}
                            </option>
                        ))}
                    </select>
                    <select
                        value={localFilters.subscription_sku}
                        onChange={(e) => updateFilter({ subscription_sku: e.target.value })}
                        className={selectClassName}
                    >
                        <option value="">All subscription SKUs</option>
                        {filter_options.subscription_skus.map((sku) => (
                            <option key={sku} value={sku}>
                                {sku}
                            </option>
                        ))}
                    </select>
                    <select
                        value={localFilters.service}
                        onChange={(e) => updateFilter({ service: e.target.value })}
                        className={selectClassName}
                    >
                        <option value="">All assigned services</option>
                        {enabled_services.map((service) => (
                            <option key={service} value={service}>
                                {service}
                            </option>
                        ))}
                    </select>
                    <div className="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-1">
                        <Button onClick={submitSearch} disabled={isSearching}>
                            <Search className="mr-2 size-4" />
                            {isSearching ? 'Applying…' : 'Apply filters'}
                        </Button>
                        {has_active_filters && (
                            <Button variant="outline" onClick={clearFilters} disabled={isSearching}>
                                Clear filters
                            </Button>
                        )}
                    </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
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
                            data-test="licensing-filter-serial-number"
                        />
                    </div>
                    <Input
                        type="search"
                        value={localFilters.device_name}
                        onChange={(e) => updateFilter({ device_name: e.target.value })}
                        placeholder="Device name"
                        data-test="licensing-filter-device-name"
                    />
                    <Input
                        type="search"
                        value={localFilters.subscription_key}
                        onChange={(e) => updateFilter({ subscription_key: e.target.value })}
                        placeholder="Subscription key"
                        data-test="licensing-filter-subscription-key"
                    />
                    <Input
                        type="search"
                        value={localFilters.subscription_tags}
                        onChange={(e) => updateFilter({ subscription_tags: e.target.value })}
                        placeholder="Subscription tags (comma-separated)"
                        data-test="licensing-filter-subscription-tags"
                    />
                    <Input
                        type="search"
                        value={localFilters.model}
                        onChange={(e) => updateFilter({ model: e.target.value })}
                        placeholder="Model"
                        data-test="licensing-filter-model"
                    />
                </div>

                <div className="flex flex-wrap items-end gap-3 rounded-lg border p-4">
                    <div className="min-w-[280px] flex-1">
                        <label className="mb-1 block text-sm font-medium" htmlFor="licensing-license">
                            Available license
                        </label>
                        <LicenseSelect
                            key={`license-select-${available_subscriptions.length}-${available_subscriptions[0]?.subscription_key ?? 'none'}`}
                            id="licensing-license"
                            value={selectedSubscriptionKey}
                            subscriptions={available_subscriptions}
                            onChange={setSelectedSubscriptionKey}
                        />
                        <p className="mt-1 text-xs text-muted-foreground">
                            GreenLake assigns the selected subscription key directly to each device.
                        </p>
                    </div>
                    <Button
                        variant="default"
                        disabled={
                            isSubmitting ||
                            selectedSerials.length === 0 ||
                            !selectedSubscriptionKey
                        }
                        onClick={runAssignAction}
                    >
                        Assign now ({selectedSerials.length})
                    </Button>
                    <Button
                        variant="secondary"
                        disabled={
                            isSubmitting ||
                            selectedSerials.length === 0 ||
                            selectedDevicesWithSubscription.length === 0
                        }
                        onClick={runUnassignAction}
                    >
                        Unassign now ({selectedSerials.length})
                    </Button>
                    <Button
                        variant="destructive"
                        disabled={isSubmitting || selectedSerials.length === 0}
                        onClick={runRemoveFromWorkspaceAction}
                        data-test="licensing-remove-from-workspace"
                    >
                        Remove from workspace ({selectedSerials.length})
                    </Button>
                </div>

                <DataTable
                    columns={columns}
                    data={paginatedDevices}
                    getRowId={(row) => row.serial}
                    enableRowSelection
                    rowSelection={rowSelection}
                    onRowSelectionChange={setRowSelection}
                    stickyLeftColumnIds={['select', 'serial']}
                />

                <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <span className="text-muted-foreground">
                        Showing {devices.length === 0 ? 0 : pageIndex * pageSize + 1}–
                        {Math.min((pageIndex + 1) * pageSize, devices.length)} of {devices.length}
                    </span>
                    <div className="flex items-center gap-2">
                        <select
                            value={pageSize}
                            onChange={(e) => setPageSize(Number(e.target.value) as 10 | 25 | 50 | 100)}
                            className={selectClassName}
                        >
                            <option value={10}>10 / page</option>
                            <option value={25}>25 / page</option>
                            <option value={50}>50 / page</option>
                            <option value={100}>100 / page</option>
                        </select>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={pageIndex === 0}
                            onClick={() => setPageIndex((prev) => Math.max(0, prev - 1))}
                        >
                            Previous
                        </Button>
                        <span>
                            Page {pageIndex + 1} of {totalPages}
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={pageIndex >= totalPages - 1}
                            onClick={() => setPageIndex((prev) => Math.min(totalPages - 1, prev + 1))}
                        >
                            Next
                        </Button>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
