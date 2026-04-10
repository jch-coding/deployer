import { router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { MoreHorizontal, TrashIcon } from 'lucide-react';
import { Pencil } from 'lucide-react';
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

type DeviceDef = {
    id: number;
    name: string;
    serial: number;
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

export const columns: ColumnDef<DeviceDef>[] = [
    {
        accessorKey: 'id',
        header: 'ID',
    },
    {
      accessorKey: 'name',
      header: 'Name',
        cell: ({ row }) => {
          const initialValue = row.getValue('name');
          const [value, setValue] = useState(initialValue);
          const [editing, setEditing] = useState(false);

          const onBlur = () => {
              if (value === initialValue) return;
              router.put(editDevice(row.getValue('id')), {name: value})
              setEditing(false);
          }

          return (
              editing ?
              <Input
                  value={value}
                  onChange={(e) => setValue(e.target.value)}
                  onBlur={onBlur}
                  />
                  :
                  <p className="flex justify-between items-baseline group">{value}<span><Button onClick={() => setEditing(true)} variant="ghost" className="opacity-0 group-hover:opacity-100"><Pencil/></Button></span></p>
          )
        }
    },
    {
        accessorKey: 'serial',
        header: 'Serial',
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
