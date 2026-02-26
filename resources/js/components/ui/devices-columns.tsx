import { type ColumnDef } from '@tanstack/react-table';
import { MoreHorizontal } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

type DeviceDef = {
    name: string;
    serial: number;
    device_function: string;
}

export const columns: ColumnDef<DeviceDef>[] = [
    {
      accessorKey: 'name',
      header: 'Name',
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
                    <DropdownMenuLabel>Actions</DropdownMenuLabel>
                    <DropdownMenuItem
                        onClick={() => {}}
                        >
                        Edit
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        data-test="delete"
                        onClick={() => {
                        }}
                        >
                        Delete
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        )
    }
]
