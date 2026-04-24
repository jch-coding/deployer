import { usePage } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import AppLayout from '@/layouts/app-layout';
import { DataTable } from '@/components/ui/data-table';
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

function formatSwitchPort(sp: SwitchPortDetail | null): string {
    if (!sp) {
        return '—';
    }
    const parts: string[] = [sp.interface_mode];
    if (sp.access_vlan != null) {
        parts.push(`access VLAN ${sp.access_vlan}`);
    }
    if (sp.native_vlan != null) {
        parts.push(`native VLAN ${sp.native_vlan}`);
    }
    if (sp.trunk_vlan_all) {
        parts.push('trunk all VLANs');
    } else if (sp.trunk_vlan_ranges) {
        parts.push(`trunk ranges ${sp.trunk_vlan_ranges}`);
    }
    return parts.join(' · ');
}

function formatLacpProfile(lp: LacpProfileDetail | null): string {
    if (!lp) {
        return '—';
    }
    const parts: string[] = [lp.mode, lp.rate];
    if (lp.port_id != null) {
        parts.push(`port ${lp.port_id}`);
    }
    parts.push(lp.trunk_type);
    if (lp.port_list.length > 0) {
        parts.push(`ports ${lp.port_list.map(String).join(', ')}`);
    }
    return parts.join(' · ');
}

function formatStpProfile(stp: StpProfileDetail | null): string {
    if (!stp) {
        return '—';
    }
    const flags: string[] = [];
    if (stp.admin_edge_port) {
        flags.push('admin edge');
    }
    if (stp.admin_edge_port_trunk) {
        flags.push('admin edge trunk');
    }
    if (stp.bpdu_guard) {
        flags.push('BPDU guard');
    }
    if (stp.loop_guard) {
        flags.push('loop guard');
    }
    return flags.length > 0 ? flags.join(' · ') : '—';
}

const interfaceColumns: ColumnDef<DeviceInterfaceRow>[] = [
    { accessorKey: 'id', header: 'ID' },
    { accessorKey: 'interface', header: 'Interface' },
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
        id: 'switch_port',
        header: 'Switch port',
        accessorFn: (row) => formatSwitchPort(row.switch_port),
    },
    {
        id: 'lacp_profile',
        header: 'LACP profile',
        accessorFn: (row) => formatLacpProfile(row.lacp_profile),
    },
    {
        id: 'stp_profile',
        header: 'STP profile',
        accessorFn: (row) => formatStpProfile(row.stp_profile),
    },
];

type DeviceShowProps = {
    device: {
        id: number;
        name: string;
        serial: string;
        device_function: string;
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
                    <p className="text-muted-foreground text-sm">
                        Serial {device.serial} · {device.device_function}
                    </p>
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
