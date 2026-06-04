import type { ColumnDef } from '@tanstack/react-table';
import { useMemo } from 'react';
import { DataTable } from '@/components/ui/data-table';

export type StaticRouteDevice = {
    device_id: number;
    device_name: string;
    error: string | null;
    source: 'device' | 'group' | 'site' | null;
};

export type DnsDevice = {
    device_id: number;
    device_name: string;
    error: string | null;
    source: 'device' | 'group' | 'site' | 'site_collection' | null;
};

export type LocalManagementDevice = {
    device_id: number;
    device_name: string;
    error: string | null;
    source: 'device' | 'group' | 'site' | 'site_collection' | null;
};

type ProfileRow = {
    id: string;
    device_name: string;
    issue: string;
    current_source: string;
    error: string;
};

function mergeIssueLabel(current: string, next: string): string {
    const labels =
        current === 'Both'
            ? ['Static route', 'DNS']
            : current
              ? current.split(', ')
              : [];
    if (!labels.includes(next)) {
        labels.push(next);
    }

    return labels.join(', ');
}

function buildProfileRows(
    staticRoutes: StaticRouteDevice[],
    dnsResults: DnsDevice[],
    localManagementResults: LocalManagementDevice[],
): ProfileRow[] {
    const byDevice = new Map<number, ProfileRow>();

    for (const device of staticRoutes.filter((d) => d.source !== 'site' || d.error)) {
        byDevice.set(device.device_id, {
            id: String(device.device_id),
            device_name: device.device_name,
            issue: 'Static route',
            current_source: device.source ?? '—',
            error: device.error ?? '',
        });
    }

    for (const device of dnsResults.filter((d) => d.source !== 'site_collection' || d.error)) {
        const existing = byDevice.get(device.device_id);
        if (existing) {
            existing.issue = mergeIssueLabel(existing.issue, 'DNS');
            existing.current_source = `${existing.current_source} / ${device.source ?? '—'}`;
            if (device.error) {
                existing.error = [existing.error, device.error].filter(Boolean).join('; ');
            }
        } else {
            byDevice.set(device.device_id, {
                id: String(device.device_id),
                device_name: device.device_name,
                issue: 'DNS',
                current_source: device.source ?? '—',
                error: device.error ?? '',
            });
        }
    }

    for (const device of localManagementResults.filter(
        (d) => d.source === 'device' || d.error,
    )) {
        const existing = byDevice.get(device.device_id);
        if (existing) {
            existing.issue = mergeIssueLabel(existing.issue, 'Local management');
            existing.current_source = `${existing.current_source} / ${device.source ?? '—'}`;
            if (device.error) {
                existing.error = [existing.error, device.error].filter(Boolean).join('; ');
            }
        } else {
            byDevice.set(device.device_id, {
                id: String(device.device_id),
                device_name: device.device_name,
                issue: 'Local management',
                current_source: device.source ?? '—',
                error: device.error ?? '',
            });
        }
    }

    return Array.from(byDevice.values()).sort((a, b) => a.device_name.localeCompare(b.device_name));
}

type ProfileInheritanceFailuresTableProps = {
    staticRoutes: StaticRouteDevice[];
    dnsResults: DnsDevice[];
    localManagementResults: LocalManagementDevice[];
};

export default function ProfileInheritanceFailuresTable({
    staticRoutes,
    dnsResults,
    localManagementResults,
}: ProfileInheritanceFailuresTableProps) {
    const rows = useMemo(
        () => buildProfileRows(staticRoutes, dnsResults, localManagementResults),
        [staticRoutes, dnsResults, localManagementResults],
    );

    const columns = useMemo<ColumnDef<ProfileRow>[]>(
        () => [
            { accessorKey: 'device_name', header: 'Device' },
            { accessorKey: 'issue', header: 'Issue' },
            { accessorKey: 'current_source', header: 'Current source' },
            { accessorKey: 'error', header: 'Error' },
        ],
        [],
    );

    if (rows.length === 0) {
        return (
            <div className="space-y-2">
                <h3 className="text-base font-semibold">Profile inheritance</h3>
                <p className="text-muted-foreground text-sm">
                    All devices inherit static routes at site level, DNS at site collection level,
                    and have no device-level local management profile overrides.
                </p>
            </div>
        );
    }

    return (
        <div className="space-y-2">
            <h3 className="text-base font-semibold">Profile inheritance</h3>
            <p className="text-muted-foreground text-xs">
                Devices without site-level static route inheritance, site-collection DNS
                inheritance, or with device-level local management profile overrides.
            </p>
            <div className="overflow-x-auto rounded-md border">
                <DataTable columns={columns} data={rows} getRowId={(row) => row.id} />
            </div>
        </div>
    );
}

export function collectProfileDeviceIds(
    staticRoutes: StaticRouteDevice[],
    dnsResults: DnsDevice[],
    localManagementResults: LocalManagementDevice[],
): { static_route: number[]; dns: number[]; local_management: number[] } {
    return {
        static_route: staticRoutes
            .filter((d) => d.source !== 'site' || d.error)
            .map((d) => d.device_id),
        dns: dnsResults
            .filter((d) => d.source !== 'site_collection' || d.error)
            .map((d) => d.device_id),
        local_management: localManagementResults
            .filter((d) => d.source === 'device' || d.error)
            .map((d) => d.device_id),
    };
}
