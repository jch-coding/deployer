import type { ColumnDef } from '@tanstack/react-table';
import { Download, GitCompareArrows, Loader2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import { csrfHeaders } from '@/lib/csrf';
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
import { compareProfiles as compareProfilesRoute } from '@/routes/device-details';

export type SwitchDetailsPayload = {
    serial: string;
    device_name: string;
    interfaces: SwitchInterfaceRow[];
    central_error: string | null;
};

type ProfileCompareStatus =
    | 'match'
    | 'mismatch'
    | 'missing_profile'
    | 'missing_interface'
    | 'no_profile';

type ProfileCompareDifference = {
    field: string;
    expected: unknown;
    actual: unknown;
};

type ProfileCompareInterface = {
    name: string;
    sw_profile: string | null;
    status: ProfileCompareStatus;
    differences: ProfileCompareDifference[];
};

type ProfileCompareSummary = {
    profiles: number;
    matches: number;
    mismatches: number;
    missing_profiles: number;
    missing_interfaces: number;
    no_profile: number;
};

type ProfileCompareResult = {
    serial: string;
    profiles: Array<{
        name: string;
        scope_level: string | null;
        scope_id: string | null;
        found: boolean;
        interface_names: string[];
    }>;
    interfaces: ProfileCompareInterface[];
    summary: ProfileCompareSummary;
    error: string | null;
    message?: string;
};

type SwitchInterfaceRowWithCompare = SwitchInterfaceRow & {
    profileCompareStatus?: ProfileCompareStatus | null;
    profileCompareSwProfile?: string | null;
};

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

function profileCompareBadgeClass(status: ProfileCompareStatus): string {
    switch (status) {
        case 'match':
            return 'bg-emerald-100 text-emerald-800 border-emerald-200';
        case 'mismatch':
            return 'bg-amber-100 text-amber-900 border-amber-200';
        case 'missing_profile':
        case 'missing_interface':
            return 'bg-red-100 text-red-800 border-red-200';
        case 'no_profile':
        default:
            return 'bg-muted text-muted-foreground border-border';
    }
}

function profileCompareLabel(status: ProfileCompareStatus): string {
    switch (status) {
        case 'match':
            return 'Match';
        case 'mismatch':
            return 'Mismatch';
        case 'missing_profile':
            return 'Missing profile';
        case 'missing_interface':
            return 'Missing interface';
        case 'no_profile':
            return 'No profile';
    }
}

function formatDifferenceValue(value: unknown): string {
    if (value === null || value === undefined) {
        return '—';
    }
    if (Array.isArray(value)) {
        return value.length === 0 ? '[]' : value.join(', ');
    }

    return String(value);
}

const selectClassName =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs';

const filterFields: {
    key: keyof SwitchInterfacesTableFilters;
    label: string;
}[] = [
    { key: 'name', label: 'Name' },
    { key: 'status', label: 'Status' },
    { key: 'operStatus', label: 'Oper Status' },
    { key: 'neighbour', label: 'Neighbour' },
    { key: 'neighbourSerial', label: 'Neighbour Serial' },
    { key: 'vlanMode', label: 'VLAN Mode' },
    { key: 'allowedVlanIds', label: 'Allowed VLAN IDs' },
    { key: 'nativeVlan', label: 'Native VLAN' },
    { key: 'poeClass', label: 'PoE Class' },
    { key: 'neighbourFamily', label: 'Neighbour Family' },
    { key: 'neighbourFunction', label: 'Neighbour Function' },
    { key: 'neighbourType', label: 'Neighbour Type' },
    { key: 'transceiverType', label: 'Transceiver Type' },
];

type SwitchInterfacesPanelProps = {
    switchDetails: SwitchDetailsPayload;
};

export default function SwitchInterfacesPanel({ switchDetails }: SwitchInterfacesPanelProps) {
    const { serial, device_name, interfaces, central_error } = switchDetails;
    const title = device_name !== '' ? device_name : serial;

    const [pageSize, setPageSize] = useState<10 | 25 | 50 | 100>(25);
    const [pageIndex, setPageIndex] = useState(0);
    const [tableFilters, setTableFilters] = useState<SwitchInterfacesTableFilters>(
        emptySwitchInterfacesTableFilters,
    );
    const [compareLoading, setCompareLoading] = useState(false);
    const [compareError, setCompareError] = useState<string | null>(null);
    const [compareResult, setCompareResult] = useState<ProfileCompareResult | null>(null);

    const compareByName = useMemo(() => {
        const map = new Map<string, ProfileCompareInterface>();
        for (const item of compareResult?.interfaces ?? []) {
            map.set(item.name, item);
        }

        return map;
    }, [compareResult]);

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
        setCompareResult(null);
        setCompareError(null);
    }, [serial, interfaces]);

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

    const runCompare = useCallback(async () => {
        setCompareLoading(true);
        setCompareError(null);

        try {
            const response = await fetch(compareProfilesRoute.url(), {
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

            const body = (await response.json().catch(() => null)) as ProfileCompareResult | null;

            if (!response.ok) {
                throw new Error(
                    body?.error ??
                        body?.message ??
                        `Compare failed (HTTP ${response.status}).`,
                );
            }

            if (body === null) {
                throw new Error('Compare failed: empty response.');
            }

            if (body.error) {
                throw new Error(body.error);
            }

            setCompareResult(body);
        } catch (error) {
            setCompareResult(null);
            setCompareError(error instanceof Error ? error.message : 'Compare failed.');
        } finally {
            setCompareLoading(false);
        }
    }, [serial]);

    const columns = useMemo<ColumnDef<SwitchInterfaceRowWithCompare>[]>(
        () => [
            { accessorKey: 'name', header: 'Name' },
            {
                id: 'profileCompare',
                header: 'Profile check',
                cell: ({ row }) => {
                    const status = row.original.profileCompareStatus;
                    if (!status) {
                        return <span className="text-muted-foreground">—</span>;
                    }

                    return (
                        <div className="flex flex-col gap-1">
                            <Badge
                                variant="outline"
                                className={profileCompareBadgeClass(status)}
                                data-test="device-details-profile-compare-status"
                            >
                                {profileCompareLabel(status)}
                            </Badge>
                            {row.original.profileCompareSwProfile ? (
                                <span className="text-xs text-muted-foreground">
                                    {row.original.profileCompareSwProfile}
                                </span>
                            ) : null}
                        </div>
                    );
                },
            },
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

    const totalFiltered = filteredInterfaces.length;
    const totalPages = Math.max(1, Math.ceil(totalFiltered / pageSize));
    const safePageIndex = Math.min(pageIndex, totalPages - 1);
    const start = safePageIndex * pageSize;
    const end = Math.min(start + pageSize, totalFiltered);
    const pagedInterfaces = useMemo(
        () =>
            filteredInterfaces.slice(start, end).map((row) => {
                const compare = compareByName.get(row.name);

                return {
                    ...row,
                    profileCompareStatus: compare?.status ?? null,
                    profileCompareSwProfile: compare?.sw_profile ?? null,
                } satisfies SwitchInterfaceRowWithCompare;
            }),
        [compareByName, filteredInterfaces, end, start],
    );

    const mismatchDetails = useMemo(
        () =>
            (compareResult?.interfaces ?? []).filter(
                (item) => item.status === 'mismatch' && item.differences.length > 0,
            ),
        [compareResult],
    );

    return (
        <section className="mt-8 border-t border-border pt-8 first:mt-0 first:border-t-0 first:pt-0">
            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2
                        className="text-xl font-semibold"
                        data-test="device-details-switch-title"
                    >
                        {title}
                    </h2>
                    {device_name !== '' ? (
                        <p
                            className="mt-1 text-sm text-muted-foreground"
                            data-test="device-details-switch-serial"
                        >
                            Serial: {serial}
                        </p>
                    ) : null}
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        className="gap-2"
                        disabled={compareLoading || Boolean(central_error)}
                        onClick={() => void runCompare()}
                        data-test="device-details-compare-profiles"
                    >
                        {compareLoading ? (
                            <Loader2 className="size-4 animate-spin" aria-hidden />
                        ) : (
                            <GitCompareArrows className="size-4" aria-hidden />
                        )}
                        Compare switch profile interfaces
                    </Button>
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
            </div>

            <h3 className="mb-3 text-lg font-medium" data-test="device-details-interfaces-heading">
                Interfaces
            </h3>

            {central_error && (
                <div
                    className="mb-4 rounded-md border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive"
                    role="alert"
                    data-test="device-details-switch-error"
                >
                    {central_error}
                </div>
            )}

            {compareError && (
                <div
                    className="mb-4 rounded-md border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive"
                    role="alert"
                    data-test="device-details-compare-error"
                >
                    {compareError}
                </div>
            )}

            {compareResult && !compareError ? (
                <div
                    className="mb-4 rounded-md border border-border bg-muted/30 px-4 py-3 text-sm"
                    data-test="device-details-compare-summary"
                >
                    <p className="font-medium">Profile comparison summary</p>
                    <p className="mt-1 text-muted-foreground">
                        Profiles: {compareResult.summary.profiles} · Matches:{' '}
                        {compareResult.summary.matches} · Mismatches:{' '}
                        {compareResult.summary.mismatches} · Missing profiles:{' '}
                        {compareResult.summary.missing_profiles} · Missing interfaces:{' '}
                        {compareResult.summary.missing_interfaces} · No profile:{' '}
                        {compareResult.summary.no_profile}
                    </p>
                    {compareResult.profiles.length > 0 ? (
                        <ul className="mt-2 list-inside list-disc text-muted-foreground">
                            {compareResult.profiles.map((profile) => (
                                <li key={profile.name}>
                                    {profile.name}
                                    {profile.found
                                        ? ` (found at ${profile.scope_level})`
                                        : ' (not found in scope chain)'}
                                </li>
                            ))}
                        </ul>
                    ) : null}
                    {mismatchDetails.length > 0 ? (
                        <div className="mt-3 space-y-2" data-test="device-details-compare-mismatches">
                            <p className="font-medium">Mismatch details</p>
                            {mismatchDetails.map((item) => (
                                <div key={item.name} className="rounded border border-border bg-background px-3 py-2">
                                    <p className="font-medium">
                                        {item.name}
                                        {item.sw_profile ? ` · ${item.sw_profile}` : ''}
                                    </p>
                                    <ul className="mt-1 list-inside list-disc text-muted-foreground">
                                        {item.differences.map((diff) => (
                                            <li key={`${item.name}-${diff.field}`}>
                                                {diff.field}: expected{' '}
                                                {formatDifferenceValue(diff.expected)}, actual{' '}
                                                {formatDifferenceValue(diff.actual)}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ))}
                        </div>
                    ) : null}
                </div>
            ) : null}

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
                        data-test={`device-details-iface-filter-${field.key}`}
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

            <DataTable<SwitchInterfaceRowWithCompare, unknown>
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

            {interfaces.length > 0 && totalFiltered === 0 ? (
                <p
                    className="mt-4 text-center text-sm text-muted-foreground"
                    data-test="device-details-interfaces-no-filter-matches"
                >
                    No interfaces match the current table filters.
                </p>
            ) : null}
        </section>
    );
}
