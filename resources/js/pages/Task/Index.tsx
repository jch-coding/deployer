import { Link, router, usePage } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { DataTable } from '@/components/ui/data-table';
import { Input } from '@/components/ui/input';
import LaravelPaginator from '@/components/ui/LaravelPaginator';
import AppLayout from '@/layouts/app-layout';
import { index as clientsIndex } from '@/routes/clients';
import { index as taskIndex, show as showTask } from '@/routes/tasks';
import type { BreadcrumbItem, SharedData } from '@/types';
import type { Paginator } from '@/types/deployer';

type TaskRow = {
    id: number;
    task_name: string;
    deployment_name: string | null;
    client_name: string | null;
    status: string;
    item_count: number;
};

type TaskIndexProps = {
    tasks: Paginator<TaskRow>;
    status_options: string[];
    filters: {
        task_name?: string;
        deployment_name?: string;
        status?: string;
    };
} & SharedData;

const columns: ColumnDef<TaskRow>[] = [
    {
        accessorKey: 'task_name',
        header: 'Task Name',
        cell: ({ row }) => (
            <Link
                href={showTask(row.original.id).url}
                className="text-primary font-medium hover:underline"
            >
                {row.original.task_name}
            </Link>
        ),
    },
    {
        accessorKey: 'deployment_name',
        header: 'Deployment Name',
    },
    {
        accessorKey: 'client_name',
        header: 'Client Name',
    },
    {
        accessorKey: 'status',
        header: 'Status',
    },
    {
        accessorKey: 'item_count',
        header: 'Items',
    },
];

export default function Index() {
    const { current_client, filters, tasks, status_options } = usePage<TaskIndexProps>().props;
    const [taskNameFilter, setTaskNameFilter] = useState(filters.task_name ?? '');
    const [deploymentNameFilter, setDeploymentNameFilter] = useState(filters.deployment_name ?? '');
    const [statusFilter, setStatusFilter] = useState(filters.status ?? '');

    useEffect(() => {
        setTaskNameFilter(filters.task_name ?? '');
        setDeploymentNameFilter(filters.deployment_name ?? '');
        setStatusFilter(filters.status ?? '');
    }, [filters.task_name, filters.deployment_name, filters.status]);

    useEffect(() => {
        const currentTaskName = filters.task_name ?? '';
        const currentDeploymentName = filters.deployment_name ?? '';
        const currentStatus = filters.status ?? '';

        if (
            taskNameFilter.trim() === currentTaskName.trim()
            && deploymentNameFilter.trim() === currentDeploymentName.trim()
            && statusFilter === currentStatus
        ) {
            return;
        }

        const handle = window.setTimeout(() => {
            router.get(
                taskIndex.url({
                    query: {
                        ...(taskNameFilter.trim() !== '' ? { task_name: taskNameFilter.trim() } : {}),
                        ...(deploymentNameFilter.trim() !== '' ? { deployment_name: deploymentNameFilter.trim() } : {}),
                        ...(statusFilter !== '' ? { status: statusFilter } : {}),
                    },
                }),
                {},
                {
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                    only: ['tasks', 'filters'],
                },
            );
        }, 350);

        return () => window.clearTimeout(handle);
    }, [taskNameFilter, deploymentNameFilter, statusFilter, filters.task_name, filters.deployment_name, filters.status]);

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: current_client?.name ?? 'Clients',
            href: clientsIndex().url,
        },
        {
            title: 'Tasks',
            href: taskIndex().url,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="mx-auto max-w-7xl px-4">
                <h1 className="text-center text-3xl font-semibold">Tasks</h1>
                <div className="mt-6 grid gap-3 md:grid-cols-3">
                    <div className="relative">
                        <Search
                            className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden
                        />
                        <Input
                            type="search"
                            value={taskNameFilter}
                            onChange={(e) => setTaskNameFilter(e.target.value)}
                            placeholder="Filter by task name"
                            className="pl-9"
                            data-test="tasks-filter-task-name"
                        />
                    </div>
                    <Input
                        type="search"
                        value={deploymentNameFilter}
                        onChange={(e) => setDeploymentNameFilter(e.target.value)}
                        placeholder="Filter by deployment name"
                        data-test="tasks-filter-deployment-name"
                    />
                    <select
                        value={statusFilter}
                        onChange={(e) => setStatusFilter(e.target.value)}
                        className="h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs"
                        data-test="tasks-filter-status"
                    >
                        <option value="">All statuses</option>
                        {status_options.map((status) => (
                            <option key={status} value={status}>
                                {status}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="mt-4">
                    <DataTable<TaskRow, unknown>
                        data={tasks.data}
                        columns={columns}
                        getRowId={(row) => String(row.id)}
                    />
                    {tasks.total > tasks.per_page && <LaravelPaginator TPaginator={tasks} />}
                </div>
            </div>
        </AppLayout>
    );
}
