import { Link, router, usePage } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { AlarmClock, Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import { DialogClose } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import LaravelPaginator from '@/components/ui/LaravelPaginator';
import TaskDurationDialog from '@/components/ui/TaskDurationDialog';
import AppLayout from '@/layouts/app-layout';
import { index as clientsIndex } from '@/routes/clients';
import { check as checkTask, index as taskIndex, relaunch, show as showTask } from '@/routes/tasks';

function remediationCheckUrl(taskId: number): string {
    return `/tasks/${taskId}/remediation-check`;
}
import type { BreadcrumbItem, SharedData } from '@/types';
import type { Paginator } from '@/types/deployer';

type TaskRow = {
    id: number;
    task_name: string;
    deployment_name: string | null;
    client_name: string | null;
    status: string;
    item_count: number;
    human_updated_at: string;
    deployment_time: number | null;
    wait_time: number;
    supports_central_check: boolean;
    supports_remediation_check?: boolean;
    can_run_central_check: boolean;
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

function statusBadgeClass(status: string): string {
    switch (status) {
        case 'COMPLETED':
            return 'bg-emerald-100 text-emerald-800 border-emerald-200';
        case 'FAILED':
            return 'bg-red-100 text-red-800 border-red-200';
        case 'TIMED_OUT':
            return 'bg-orange-100 text-orange-900 border-orange-200';
        case 'CANCELLED':
            return 'bg-slate-200 text-slate-800 border-slate-300';
        case 'IN_PROGRESS':
            return 'bg-blue-100 text-blue-800 border-blue-200';
        case 'PENDING':
            return 'bg-amber-100 text-amber-900 border-amber-200';
        default:
            return '';
    }
}

function canRelaunch(status: string): boolean {
    return status === 'FAILED' || status === 'TIMED_OUT' || status === 'CANCELLED';
}

function deploymentTimeToHoursMinutes(deploymentTime: number | null): { hours: number; minutes: number } {
    const total = deploymentTime ?? 0;
    return {
        hours: Math.floor(total / 60),
        minutes: total % 60,
    };
}

function TaskRowActions({ task }: { task: TaskRow }) {
    const disabled = !canRelaunch(task.status);
    const initial = deploymentTimeToHoursMinutes(task.deployment_time);
    const [deploymentTimeHours, setDeploymentTimeHours] = useState(initial.hours);
    const [deploymentTimeMinutes, setDeploymentTimeMinutes] = useState(initial.minutes);
    const [waitTimeMinutes, setWaitTimeMinutes] = useState(task.wait_time ?? 1);

    useEffect(() => {
        const next = deploymentTimeToHoursMinutes(task.deployment_time);
        setDeploymentTimeHours(next.hours);
        setDeploymentTimeMinutes(next.minutes);
        setWaitTimeMinutes(task.wait_time ?? 1);
    }, [task.deployment_time, task.wait_time]);

    const relaunchWithTimers = () => {
        if (disabled) {
            return;
        }
        router.post(relaunch(task.id).url, {
            deployment_time: deploymentTimeHours * 60 + deploymentTimeMinutes,
            wait_time: waitTimeMinutes,
        });
    };

    return (
        <div className="flex items-center gap-2">
            {task.can_run_central_check && task.supports_remediation_check && (
                <Button variant="outline" size="sm" asChild>
                    <Link href={remediationCheckUrl(task.id)}>Verify</Link>
                </Button>
            )}
            {task.can_run_central_check && !task.supports_remediation_check && (
                <Button variant="outline" size="sm" asChild>
                    <Link href={checkTask(task.id).url}>Verify</Link>
                </Button>
            )}
            <TaskDurationDialog
                deploymentTimeHours={deploymentTimeHours}
                deploymentTimeMinutes={deploymentTimeMinutes}
                waitTimeMinutes={waitTimeMinutes}
                onDeploymentTimeHoursChange={setDeploymentTimeHours}
                onDeploymentTimeMinutesChange={setDeploymentTimeMinutes}
                onWaitTimeMinutesChange={setWaitTimeMinutes}
                hoursInputId={`tasks-relaunch-hours-${task.id}`}
                minutesInputId={`tasks-relaunch-minutes-${task.id}`}
                waitTimeInputId={`tasks-relaunch-wait-${task.id}`}
                tooltipLabel="Relaunch with custom timers"
                trigger={
                    <Button
                        type="button"
                        size="icon"
                        variant="outline"
                        disabled={disabled}
                        data-test={`tasks-relaunch-timers-${task.id}`}
                        aria-label="Relaunch with custom timers"
                    >
                        <AlarmClock className="size-4" aria-hidden />
                    </Button>
                }
                footer={
                    <DialogClose asChild>
                        <Button
                            type="button"
                            disabled={disabled}
                            data-test={`tasks-relaunch-timers-submit-${task.id}`}
                            onClick={relaunchWithTimers}
                        >
                            Relaunch
                        </Button>
                    </DialogClose>
                }
            />
            <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={disabled}
                onClick={() => {
                    if (disabled) {
                        return;
                    }
                    router.post(relaunch(task.id).url);
                }}
                data-test={`tasks-relaunch-${task.id}`}
            >
                Relaunch
            </Button>
        </div>
    );
}

export default function Index() {
    const { current_client, filters, tasks, status_options } = usePage<TaskIndexProps>().props;
    const [taskNameFilter, setTaskNameFilter] = useState(filters.task_name ?? '');
    const [deploymentNameFilter, setDeploymentNameFilter] = useState(filters.deployment_name ?? '');
    const [statusFilter, setStatusFilter] = useState(filters.status ?? '');

    const columns = useMemo<ColumnDef<TaskRow>[]>(
        () => [
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
                cell: ({ row }) => (
                    <Badge variant="outline" className={statusBadgeClass(row.original.status)}>
                        {row.original.status}
                    </Badge>
                ),
            },
            {
                accessorKey: 'human_updated_at',
                header: 'Last updated',
            },
            {
                accessorKey: 'item_count',
                header: 'Items',
            },
            {
                id: 'actions',
                header: 'Actions',
                cell: ({ row }) => <TaskRowActions task={row.original} />,
            },
        ],
        [],
    );

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
