import { type ColumnDef } from '@tanstack/react-table';
import { MoreHorizontal, PencilIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { destroy as deleteDevice, edit as editDevice } from '@/routes/devices';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Form, router } from '@inertiajs/react';
import { Dialog, DialogClose, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Field } from '@/components/ui/field';
import { useState } from 'react';
import { Input } from '@/components/ui/input';
import { Pencil } from 'lucide-react';

type DeviceDef = {
    id: number;
    name: string;
    serial: number;
    device_function: string;
    interfaces: {
        id: number;
        interface: string;
        ip_address?: string;
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
                <DropdownMenuContent align="end">
                    <DropdownMenuLabel>Interfaces</DropdownMenuLabel>
                    {
                        row.original.interfaces.map((device_interface) => (
                            <DropdownMenuItem key={device_interface.id}>
                                {device_interface.interface}
                            </DropdownMenuItem>
                        ))
                    }
                </DropdownMenuContent>
            </DropdownMenu>
        )
    }
]

const EditDeviceModal = (id : number) => {
    const [isDialogOpen, setIsDialogOpen] = useState(false);
    return (
        <>
        <Dialog open={isDialogOpen} onOpenChange={setIsDialogOpen}>
            <DialogHeader>
                <DialogTitle>
                    Edit Device
                </DialogTitle>
            </DialogHeader>
            <DialogContent>
                <Form action={editDevice(id)} method="PUT">
                    <Field>
                        <label htmlFor="name">Name</label>
                        <input type="text" name="name" id="name" />
                    </Field>
                    <Field>
                        <label htmlFor="serial">Serial</label>
                        <input type="text" name="serial" id="serial" />
                    </Field>
                    <Field>
                        <label htmlFor="device_function">Device Function</label>
                        <input type="text" name="device_function" id="device_function" />
                    </Field>
                    <Field>
                        <label htmlFor="group">Device Group</label>
                        <input type="text" name="group" id="group" />
                    </Field>
                    <Field>
                        <label htmlFor="site">Site</label>
                        <input type="text" name="site" id="site" />
                    </Field>
                </Form>
            </DialogContent>
            <DialogFooter>
                <DialogClose asChild>
                    <Button variant="secondary">Cancel</Button>
                </DialogClose>
                <Button type="submit">Edit</Button>
            </DialogFooter>
        </Dialog>
        </>
    )
}
