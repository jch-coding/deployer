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

import { destroy } from '@/routes/deployments';
import { Link, router } from '@inertiajs/react';

type DeploymentDef = {
    id: number;
    name: string;
    devices_count: number;
}

export const columns: ColumnDef<DeploymentDef>[] = [
    {
      accessorKey: 'id',
      header: 'ID',
    },
    {
        accessorKey: 'name',
        header: 'Name',
        cell: ({ row }) => <Link href={`/deployments/${row.getValue('id')}`} className="hover:underline" data-test="deployment-link">{row.getValue('name')}</Link>
    },
    {
        accessorKey: 'devices_count',
        header: () => <div className="text-right">Devices</div>,
        cell: ({ row }) => <div className="text-right">{row.getValue('devices_count')}</div>
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
                            router.delete(destroy(row.getValue('id')))
                        }}
                        >
                        Delete
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        )
    }
]
