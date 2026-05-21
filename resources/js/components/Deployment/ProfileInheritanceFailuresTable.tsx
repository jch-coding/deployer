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

type ProfileRow = {
    id: string;
    device_name: string;
    issue: string;
    current_source: string;
    error: string;
};

function buildProfileRows(
    staticRoutes: StaticRouteDevice[],
    dnsResults: DnsDevice[],
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
            existing.issue = 'Both';
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

    return Array.from(byDevice.values()).sort((a, b) => a.device_name.localeCompare(b.device_name));
}

type ProfileInheritanceFailuresTableProps = {
    staticRoutes: StaticRouteDevice[];
    dnsResults: DnsDevice[];
};

export default function ProfileInheritanceFailuresTable({
    staticRoutes,
    dnsResults,
}: ProfileInheritanceFailuresTableProps) {
    const rows = useMemo(
        () => buildProfileRows(staticRoutes, dnsResults),
        [staticRoutes, dnsResults],
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
                    All devices inherit static routes at site level and DNS at site collection level.
                </p>
            </div>
        );
    }

    return (
        <div className="space-y-2">
            <h3 className="text-base font-semibold">Profile inheritance</h3>
            <p className="text-muted-foreground text-xs">
                Devices without site-level static route inheritance or site-collection DNS inheritance.
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
): { static_route: number[]; dns: number[] } {
    return {
        static_route: staticRoutes
            .filter((d) => d.source !== 'site' || d.error)
            .map((d) => d.device_id),
        dns: dnsResults
            .filter((d) => d.source !== 'site_collection' || d.error)
            .map((d) => d.device_id),
    };
}
