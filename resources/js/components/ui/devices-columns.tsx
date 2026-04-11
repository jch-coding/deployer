import { router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { MoreHorizontal, Pencil, TrashIcon } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { destroy as deleteDevice, edit as editDevice } from '@/routes/devices';

export type DeviceDef = {
    id: number;
    name: string;
    serial: string | number;
    device_function: string;
    interfaces: {
        id: number;
        interface: string;
        ip_address?: string;
        sw_profile?: string;
        description?: string;
        lacp_profile_id?: number;
    }[]
}

function EditableDeviceNameCell({ id, name }: { id: number; name: string }) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(name);

    const onBlur = () => {
        if (draft === name) {
            setEditing(false);
            return;
        }
        router.put(editDevice(id).url, { name: draft });
        setEditing(false);
    };

    return editing ? (
        <Input
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            onBlur={onBlur}
        />
    ) : (
        <p className="group flex items-baseline justify-between">
            {name}
            <span>
                <Button
                    type="button"
                    onClick={() => {
                        setDraft(name);
                        setEditing(true);
                    }}
                    variant="ghost"
                    className="opacity-0 group-hover:opacity-100"
                >
                    <Pencil />
                </Button>
            </span>
        </p>
    );
}

function EditableDeviceSerialCell({
    id,
    serial,
}: {
    id: number;
    serial: string | number;
}) {
    const serverSerial = String(serial ?? '');
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(serverSerial);

    const onBlur = () => {
        if (draft === serverSerial) {
            setEditing(false);
            return;
        }
        router.put(editDevice(id).url, { serial: draft });
        setEditing(false);
    };

    return editing ? (
        <Input
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            onBlur={onBlur}
        />
    ) : (
        <p className="group flex items-baseline justify-between">
            {serverSerial}
            <span>
                <Button
                    type="button"
                    onClick={() => {
                        setDraft(serverSerial);
                        setEditing(true);
                    }}
                    variant="ghost"
                    className="opacity-0 group-hover:opacity-100"
                    aria-label="Edit serial"
                >
                    <Pencil />
                </Button>
            </span>
        </p>
    );
}

export const columns: ColumnDef<DeviceDef>[] = [
    {
        accessorKey: 'id',
        header: 'ID',
    },
    {
        accessorKey: 'name',
        header: 'Name',
        cell: ({ row }) => (
            <EditableDeviceNameCell
                id={row.original.id}
                name={row.original.name}
            />
        ),
    },
    {
        accessorKey: 'serial',
        header: 'Serial',
        cell: ({ row }) => (
            <EditableDeviceSerialCell
                id={row.original.id}
                serial={row.original.serial}
            />
        ),
    },
    {
        accessorKey: 'device_function',
        header: 'Device Function',
    },
    {
        id: "actions",
        header: 'Interfaces',
        cell: ({ row }) => (
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        className="h-8 w-8 p-0"
                        data-test="actions-open"
                    >
                        <span className="sr-only">Open menu</span>
                        <MoreHorizontal className="h-5 w-5" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="overflow-y-scroll max-h-24">
                    <DropdownMenuLabel>Interfaces</DropdownMenuLabel>
                    {
                        row.original.interfaces.map((device_interface) => (
                            <DropdownMenuItem key={device_interface.id}>
                                {! device_interface.interface.includes('/') && ! device_interface.lacp_profile_id ? 'VLAN' : '' } {device_interface.lacp_profile_id ? 'LAG' : '' } {device_interface.interface} {device_interface.ip_address ? ` - IP: ${device_interface.ip_address}`: '' } {device_interface.sw_profile ? `(${device_interface.sw_profile})` : ''} {device_interface.description ? ` - desc: ${device_interface.description}` : ''}
                            </DropdownMenuItem>
                        ))
                    }
                </DropdownMenuContent>
            </DropdownMenu>
        )
    },
    {
        'id' : 'delete',
        cell: ({row}) => (
            <Button variant="outline" className="hover:bg-red-500 hover:text-white" onClick={() => router.delete(deleteDevice(row.original.id))}><TrashIcon /></Button>
        )
    }
]
