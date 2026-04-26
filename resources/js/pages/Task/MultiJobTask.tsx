import { router, usePage, usePoll } from '@inertiajs/react';
import { ChevronDown, PowerOffIcon } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { index as clientIndex } from '@/routes/clients';
import { show as showDeployment } from '@/routes/deployments';
import { cancel, show as showTask } from '@/routes/tasks';
import type { BreadcrumbItem, SharedData } from '@/types';

type Pivot = { status: string };

type DeviceRow = {
    id: number;
    name: string;
    serial?: string | number;
    pivot: Pivot;
    device_function?: string;
    site?: string;
    group?: string;
    sku?: string;
    lacp_profile?: { port_list?: string };
    [key: string]: unknown;
};

type DeviceInterfaceRow = {
    id: number;
    device_id: number;
    interface: string;
    pivot: Pivot;
    ip_address?: string | null;
    sw_profile?: string | null;
    [key: string]: unknown;
};

type SubJob = {
    id: number;
    task_type: string;
    status: string;
    status_log: string;
    friendly_label: string;
    completed_count: number;
    total_count: number;
    is_device_based: boolean;
    devices: DeviceRow[];
    interfaces: DeviceInterfaceRow[];
    display_columns: string[];
};

type PageProps = {
    task: { id: number };
    deployment: { id: number; name: string };
    logical_friendly_name: string;
    logical_description: string;
    sub_jobs: SubJob[];
} & SharedData;

function SubJobLog({ status_log }: { status_log: string }) {
    const lines = status_log.split('\\n').filter((line) => line.length > 0);

    return (
        <div className="max-h-[40vh] space-y-2 overflow-y-auto text-sm">
            {lines.length === 0 ? (
                <p className="text-muted-foreground">No log entries yet.</p>
            ) : (
                lines.map((message, index) => (
                    <div key={index} className="mb-2 break-words">
                        {message}
                    </div>
                ))
            )}
        </div>
    );
}

function SubJobCollapsibleLog({ status_log }: { status_log: string }) {
    const [open, setOpen] = useState(false);

    return (
        <Collapsible open={open} onOpenChange={setOpen}>
            <CollapsibleTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    className="mb-2 flex w-full items-center justify-between gap-2 px-0 hover:bg-transparent"
                >
                    <span>Task log</span>
                    <ChevronDown
                        className={cn(
                            'size-4 shrink-0 transition-transform',
                            open && 'rotate-180',
                        )}
                    />
                </Button>
            </CollapsibleTrigger>
            <CollapsibleContent>
                <SubJobLog status_log={status_log} />
            </CollapsibleContent>
        </Collapsible>
    );
}

export default function MultiJobTask() {
    const { task, deployment, logical_friendly_name, logical_description, sub_jobs, current_client } =
        usePage<PageProps>().props;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: current_client?.name ?? 'Clients', href: clientIndex().url },
        { title: 'Client', href: clientIndex().url },
        { title: 'Deployment', href: showDeployment(deployment.id).url },
        { title: 'Task', href: showTask(task.id).url },
    ];

    usePoll(2000);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="absolute top-4 right-4">
                <Button
                    variant="destructive"
                    onClick={() => router.patch(cancel(task.id).url)}
                >
                    <PowerOffIcon /> Cancel task
                </Button>
            </div>
            <div className="mx-auto max-w-7xl px-4">
                <h1 className="text-center text-2xl font-bold">
                    {logical_friendly_name}
                </h1>
                <p className="text-muted-foreground mt-2 text-center text-sm">
                    {logical_description}
                </p>
                <div className="mt-6 flex flex-col gap-6">
                    {sub_jobs.map((sub) => (
                        <Card key={sub.id} className="overflow-hidden">
                            <CardHeader className="border-b pb-3">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <h2 className="text-lg font-semibold">
                                        {sub.friendly_label}
                                    </h2>
                                    <span className="text-muted-foreground text-sm">
                                        {sub.status}
                                    </span>
                                </div>
                            </CardHeader>
                            <CardContent className="pt-4">
                                <div className="flex flex-col gap-4 lg:flex-row lg:items-stretch">
                                    <div className="flex shrink-0 flex-row gap-3 lg:w-40 lg:flex-col lg:items-stretch">
                                        <div
                                            className="bg-muted relative hidden w-2 shrink-0 rounded-full lg:block"
                                            aria-hidden
                                        >
                                            <div
                                                className="bg-primary absolute bottom-0 left-0 w-full rounded-full transition-all"
                                                style={{
                                                    height:
                                                        sub.total_count === 0
                                                            ? '0%'
                                                            : `${(sub.completed_count / sub.total_count) * 100}%`,
                                                }}
                                            />
                                        </div>
                                        <div className="flex flex-1 flex-col justify-center text-center lg:text-left">
                                            <p className="text-muted-foreground text-xs tracking-wide uppercase">
                                                Progress
                                            </p>
                                            <p className="text-2xl font-bold tabular-nums">
                                                {sub.completed_count}
                                                <span className="text-muted-foreground text-lg font-normal">
                                                    {' '}
                                                    / {sub.total_count}
                                                </span>
                                            </p>
                                        </div>
                                    </div>
                                    <div className="min-w-0 flex-1 border-t pt-4 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-6">
                                        <SubJobCollapsibleLog status_log={sub.status_log} />
                                    </div>
                                    <div className="min-w-0 flex-1 border-t pt-4 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-6">
                                        <h3 className="text-muted-foreground mb-3 text-sm font-medium">
                                            {sub.is_device_based
                                                ? 'Devices'
                                                : 'Interfaces'}
                                        </h3>
                                        {sub.is_device_based ? (
                                            <div className="max-h-[50vh] space-y-2 overflow-y-auto">
                                                {sub.devices.map((device) => (
                                                    <div
                                                        key={device.id}
                                                        className={cn(
                                                            'flex flex-wrap items-center justify-between gap-2 text-sm',
                                                            device.pivot.status ===
                                                                'COMPLETED' &&
                                                                'text-green-600 dark:text-green-400',
                                                        )}
                                                    >
                                                        <span>{device.name}</span>
                                                        <span className="text-muted-foreground">
                                                            {device.serial}
                                                        </span>
                                                        {sub.display_columns.map(
                                                            (column) => (
                                                                <span key={column}>
                                                                    {String(
                                                                        device[column] ??
                                                                            '',
                                                                    )}
                                                                </span>
                                                            ),
                                                        )}
                                                        <span>
                                                            {device.pivot.status}
                                                        </span>
                                                    </div>
                                                ))}
                                            </div>
                                        ) : (
                                            <div className="max-h-[50vh] space-y-2 overflow-y-auto">
                                                {sub.interfaces.map(
                                                    (device_interface) => {
                                                        const deviceForInterface =
                                                            sub.devices.find(
                                                                (d) =>
                                                                    d.id ===
                                                                    device_interface.device_id,
                                                            );
                                                        return (
                                                            <div
                                                                key={
                                                                    device_interface.id
                                                                }
                                                                className={cn(
                                                                    'flex flex-wrap items-center justify-between gap-2 text-sm',
                                                                    device_interface
                                                                        .pivot
                                                                        .status ===
                                                                        'COMPLETED' &&
                                                                        'text-green-600 dark:text-green-400',
                                                                )}
                                                            >
                                                                <span>
                                                                    {deviceForInterface?.name ??
                                                                        '—'}
                                                                </span>
                                                                <span>
                                                                    {
                                                                        device_interface.interface
                                                                    }
                                                                </span>
                                                                {sub.display_columns.map(
                                                                    (column) => (
                                                                        <span
                                                                            key={
                                                                                column
                                                                            }
                                                                        >
                                                                            {String(
                                                                                device_interface[
                                                                                    column
                                                                                ] ??
                                                                                    '',
                                                                            )}
                                                                        </span>
                                                                    ),
                                                                )}
                                                                <span>
                                                                    {
                                                                        device_interface
                                                                            .pivot
                                                                            .status
                                                                    }
                                                                </span>
                                                            </div>
                                                        );
                                                    },
                                                )}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
