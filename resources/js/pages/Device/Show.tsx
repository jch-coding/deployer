import { usePage } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import AppLayout from '@/layouts/app-layout';
import { DataTable } from '@/components/ui/data-table';
import { index as clientsIndex } from '@/routes/clients';
import { show as showDeployment } from '@/routes/deployments';
import { show as showDevice } from '@/routes/devices';
import type { BreadcrumbItem, SharedData } from '@/types';

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
    switch_port_id: number | null;
    lacp_profile_id: number | null;
    stp_profile_id: number | null;
    created_at: string | null;
    updated_at: string | null;
};

function yesNo(value: boolean): string {
    return value ? 'Yes' : 'No';
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
    { accessorKey: 'switch_port_id', header: 'Switch port ID' },
    { accessorKey: 'lacp_profile_id', header: 'LACP profile ID' },
    { accessorKey: 'stp_profile_id', header: 'STP profile ID' },
    { accessorKey: 'created_at', header: 'Created' },
    { accessorKey: 'updated_at', header: 'Updated' },
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
