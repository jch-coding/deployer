import { usePage } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { DataTable } from '@/components/ui/data-table';
import AppLayout from '@/layouts/app-layout';
import { index as clientsIndex } from '@/routes/clients';
import { show as showDeployment } from '@/routes/deployments';
import { show as showDevice } from '@/routes/devices';
import type { BreadcrumbItem, SharedData } from '@/types';

export type SwitchPortDetail = {
    access_vlan: number | null;
    interface_mode: string;
    native_vlan: number | null;
    trunk_vlan_all: boolean | null;
    trunk_vlan_ranges: string | null;
};

export type LacpProfileDetail = {
    mode: string;
    port_id: number | null;
    rate: string;
    trunk_type: string;
    port_list: string[];
};

export type StpProfileDetail = {
    admin_edge_port: boolean;
    admin_edge_port_trunk: boolean;
    bpdu_guard: boolean;
    loop_guard: boolean;
};

export type DeviceInterfaceRow = {
    id: number;
    interface: string;
    description: string | null;
    ip_address: string | null;
    enable: boolean;
    jumbo_frames: boolean;
    routing: boolean;
    vrf_forwarding: string;
    sw_profile: string | null;
    portchannel_lag: string | null;
    switch_port: SwitchPortDetail | null;
    lacp_profile: LacpProfileDetail | null;
    stp_profile: StpProfileDetail | null;
};

function yesNo(value: boolean): string {
    return value ? 'Yes' : 'No';
}

function formatDeviceMetadata(device: {
    site: string | null;
    group: string | null;
    serial: string | null;
    scope_id: string | null;
    device_function: string | null;
}): string | null {
    const parts: string[] = [];
    const pushLabeled = (label: string, value: string | null | undefined) => {
        if (value == null) {
            return;
        }
        const trimmed = String(value).trim();
        if (trimmed === '') {
            return;
        }
        parts.push(`${label} ${trimmed}`);
    };
    pushLabeled('Site', device.site);
    pushLabeled('Group', device.group);
    pushLabeled('Serial', device.serial);
    pushLabeled('Scope ID', device.scope_id);
    if (device.device_function != null) {
        const fn = String(device.device_function).trim();
        if (fn !== '') {
            parts.push(fn);
        }
    }
    return parts.length > 0 ? parts.join(' · ') : null;
}

function hasIpAddress(row: DeviceInterfaceRow): boolean {
    return Boolean(row.ip_address?.trim());
}

function formatInterfaceDisplay(row: DeviceInterfaceRow): string {
    const tags: string[] = [];
    if (hasIpAddress(row)) {
        tags.push('VLAN');
    }
    if (row.lacp_profile) {
        tags.push('LAG');
    }
    const prefix = tags.length > 0 ? `${tags.join(' ')} ` : '';
    return `${prefix}${row.interface}`;
}

function displaySwitchPortCell(
    row: DeviceInterfaceRow,
    pick: (sp: SwitchPortDetail) => string | number | boolean | null,
): string {
    if (hasIpAddress(row) || row.switch_port == null) {
        return '—';
    }
    const v = pick(row.switch_port);
    if (v === null || v === '') {
        return '—';
    }
    if (typeof v === 'boolean') {
        return yesNo(v);
    }
    return String(v);
}

function displayLacpCell(
    row: DeviceInterfaceRow,
    pick: (lp: LacpProfileDetail) => string | number | string[] | null,
): string {
    if (row.lacp_profile == null) {
        return '—';
    }
    const v = pick(row.lacp_profile);
    if (v === null) {
        return '—';
    }
    if (Array.isArray(v)) {
        return v.length > 0 ? v.map(String).join(', ') : '—';
    }
    if (typeof v === 'string' && v.trim() === '') {
        return '—';
    }
    return String(v);
}

function displayStpCell(row: DeviceInterfaceRow, key: keyof StpProfileDetail): string {
    if (row.stp_profile == null) {
        return '—';
    }
    return yesNo(Boolean(row.stp_profile[key]));
}

const interfaceColumns: ColumnDef<DeviceInterfaceRow>[] = [
    { accessorKey: 'id', header: 'ID' },
    {
        id: 'interface',
        header: 'Interface',
        accessorFn: (row) => formatInterfaceDisplay(row),
    },
    { accessorKey: 'description', header: 'Description' },
    { accessorKey: 'ip_address', header: 'IP address' },
    {
        accessorKey: 'enable',
        header: 'Enabled',
        cell: ({ getValue }) => yesNo(Boolean(getValue())),
    },
    {
        accessorKey: 'jumbo_frames',
        header: 'Jumbo frames',
        cell: ({ getValue }) => yesNo(Boolean(getValue())),
    },
    {
        accessorKey: 'routing',
        header: 'Routing',
        cell: ({ getValue }) => yesNo(Boolean(getValue())),
    },
    { accessorKey: 'vrf_forwarding', header: 'VRF forwarding' },
    { accessorKey: 'sw_profile', header: 'Switch profile' },
    { accessorKey: 'portchannel_lag', header: 'Port-channel / LAG' },
    {
        id: 'switch_port_mode',
        header: 'Port mode',
        accessorFn: (row) => displaySwitchPortCell(row, (sp) => sp.interface_mode),
    },
    {
        id: 'switch_port_access_vlan',
        header: 'Access VLAN',
        accessorFn: (row) => displaySwitchPortCell(row, (sp) => sp.access_vlan),
    },
    {
        id: 'switch_port_native_vlan',
        header: 'Native VLAN',
        accessorFn: (row) => displaySwitchPortCell(row, (sp) => sp.native_vlan),
    },
    {
        id: 'switch_port_trunk_vlan_all',
        header: 'Trunk all VLANs',
        accessorFn: (row) => displaySwitchPortCell(row, (sp) => sp.trunk_vlan_all),
    },
    {
        id: 'switch_port_trunk_vlan_ranges',
        header: 'Trunk VLAN ranges',
        accessorFn: (row) => displaySwitchPortCell(row, (sp) => sp.trunk_vlan_ranges),
    },
    {
        id: 'lacp_mode',
        header: 'LACP mode',
        accessorFn: (row) => displayLacpCell(row, (lp) => lp.mode),
    },
    {
        id: 'lacp_port_id',
        header: 'LACP port ID',
        accessorFn: (row) => displayLacpCell(row, (lp) => lp.port_id),
    },
    {
        id: 'lacp_rate',
        header: 'LACP rate',
        accessorFn: (row) => displayLacpCell(row, (lp) => lp.rate),
    },
    {
        id: 'lacp_trunk_type',
        header: 'LACP trunk type',
        accessorFn: (row) => displayLacpCell(row, (lp) => lp.trunk_type),
    },
    {
        id: 'lacp_port_list',
        header: 'LACP port list',
        accessorFn: (row) => displayLacpCell(row, (lp) => lp.port_list),
    },
    {
        id: 'stp_admin_edge',
        header: 'STP admin edge',
        accessorFn: (row) => displayStpCell(row, 'admin_edge_port'),
    },
    {
        id: 'stp_admin_edge_trunk',
        header: 'STP admin edge trunk',
        accessorFn: (row) => displayStpCell(row, 'admin_edge_port_trunk'),
    },
    {
        id: 'stp_bpdu_guard',
        header: 'STP BPDU guard',
        accessorFn: (row) => displayStpCell(row, 'bpdu_guard'),
    },
    {
        id: 'stp_loop_guard',
        header: 'STP loop guard',
        accessorFn: (row) => displayStpCell(row, 'loop_guard'),
    },
];

type DeviceShowProps = {
    device: {
        id: number;
        name: string;
        site: string | null;
        group: string | null;
        serial: string | null;
        scope_id: string | null;
        device_function: string | null;
    };
    deployment: {
        id: number;
        name: string;
    };
    interfaces: DeviceInterfaceRow[];
} & SharedData;

export default function Show() {
    const { device, deployment, current_client, interfaces } =
        usePage<DeviceShowProps>().props;

    const deviceSubtitle = formatDeviceMetadata(device);

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: current_client?.name ?? 'Clients',
            href: clientsIndex().url,
        },
        {
            title: deployment.name,
            href: showDeployment(deployment.id).url,
        },
        {
            title: device.name,
            href: showDevice(device.id).url,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="space-y-4 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">{device.name}</h1>
                    {deviceSubtitle ? (
                        <p className="text-muted-foreground text-sm">
                            {deviceSubtitle}
                        </p>
                    ) : null}
                </div>
                <div>
                    <h2 className="mb-2 text-lg font-medium">Interfaces</h2>
                    <DataTable<DeviceInterfaceRow, unknown>
                        data={interfaces}
                        columns={interfaceColumns}
                        getRowId={(row) => String(row.id)}
                    />
                </div>
            </div>
        </AppLayout>
    );
}
