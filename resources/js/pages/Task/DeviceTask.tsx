import { Link, router, usePage, usePoll } from '@inertiajs/react';
import { PowerOffIcon, Trash2Icon } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    ChevronRightCircleIcon,
} from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { index as clientIndex } from '@/routes/clients';
import  { show as showDeployment } from '@/routes/deployments';
import { show as showTask, cancel, clear_queue, check as checkTask } from '@/routes/tasks';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Button } from '@/components/ui/button';
import { toast } from 'sonner';

type TaskDevice = {
    id: number;
    name: string;
    serial: string;
    pivot: { status: string };
    lacp_profile?: { port_list?: string };
    [key: string]: unknown;
};

type DeviceTaskPageProps = SharedData & {
    task: { id: number; task_type: string; status_log: string };
    task_friendly_name: string;
    devices: TaskDevice[];
    display_columns?: string[];
    deployment: { id: number; name: string };
    can_run_central_check?: boolean;
};

export default function Show() {
    const { current_client, flash, task, task_friendly_name, devices, display_columns, deployment, can_run_central_check } =
        usePage<DeviceTaskPageProps>().props;
    const displayColumns = display_columns ?? [];
    const canRunCentralCheck = can_run_central_check === true;
    const completedDevices= devices.filter(
        (device) => device.pivot.status === 'COMPLETED',
    );
    const [isCancelling, setIsCancelling] = useState(false);
    const [isClearingQueue, setIsClearingQueue] = useState(false);
    const isLagTask = task.task_type === 'CONFIGURE_LAG_INTERFACE';

    const formatColumnLabel = (column: string) =>
        column
            .split('_')
            .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
            .join(' ');

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: current_client?.name ?? 'Clients',
            href: clientIndex().url,
        },
        {
            title: deployment.name,
            href: showDeployment(deployment.id).url,
        },
        {
            title: 'Task',
            href: showTask(task.id).url,
        },
    ]

    usePoll(2000)

    useEffect(() => {
        if (flash?.success) {
            toast.success(flash.success);
        }
        if (flash?.error) {
            toast.error(flash.error);
        }
    }, [flash?.success, flash?.error]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="absolute top-4 right-4 flex items-center gap-2">
                <Button
                    variant="outline"
                    disabled={isClearingQueue || isCancelling}
                    onClick={() => {
                        setIsClearingQueue(true);
                        router.post(clear_queue(task.id).url, {}, {
                            onFinish: () => setIsClearingQueue(false),
                        });
                    }}
                >
                    <Trash2Icon /> Clear Queue
                </Button>
                <Button
                    variant="destructive"
                    disabled={isClearingQueue || isCancelling}
                    onClick={() => {
                        setIsCancelling(true);
                        router.patch(cancel(task.id).url, {}, {
                            onFinish: () => setIsCancelling(false),
                        });
                    }}
                >
                    <PowerOffIcon /> Cancel Task
                </Button>
            </div>
            <div className="text-center text-2xl font-bold">
                {task_friendly_name}
            </div>
            <div className="mx-auto my-2 flex min-w-7xl gap-4">
                <div className="max-w-[400px]">
                    {canRunCentralCheck && (
                        <div className="mb-2">
                            <Button variant="outline" className="w-full" asChild>
                                <Link href={checkTask(task.id).url}>
                                    Verify in Central
                                </Link>
                            </Button>
                        </div>
                    )}
                    <Card className="h-[75vh] overflow-y-auto">
                        <CardHeader className="text-center text-2xl font-bold">
                            Progress
                        </CardHeader>
                        <CardDescription className="flex items-center justify-center">
                            <span className="p-1 text-3xl font-bold">
                                {completedDevices.length}
                            </span>
                            <ChevronRightCircleIcon />
                            <span className="p-1 text-3xl font-bold">
                                {devices.length}
                            </span>
                        </CardDescription>
                        <CardContent>
                            {task.status_log
                                .split('\\n')
                                .map((message, index) => (
                                    <div key={index} className="mb-2 text-sm">
                                        {message}
                                    </div>
                                ))}
                        </CardContent>
                    </Card>
                </div>
                <div className="flex-1">
                    <Card className="h-[75vh] overflow-y-auto">
                        <CardHeader className="text-center text-2xl font-bold">
                            Devices Provisioned
                        </CardHeader>
                        <CardContent>
                            <div className="mb-3 grid grid-cols-4 gap-2 border-b pb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                <span>Name</span>
                                <span>Serial</span>
                                <span>Task Field</span>
                                <span>Status</span>
                            </div>
                            {devices.map((device) => (
                                <div
                                    key={device.id}
                                    className={cn(
                                        'mb-2 grid grid-cols-4 gap-2 text-sm',
                                        device.pivot.status === 'COMPLETED' &&
                                            'text-green-500',
                                    )}
                                >
                                    <span>{device.name}</span>
                                    <span>{device.serial}</span>
                                    <span className="truncate">
                                        {isLagTask
                                            ? device.lacp_profile?.port_list
                                            : displayColumns
                                                  .map((column: string) =>
                                                      `${formatColumnLabel(column)}: ${device[column] ?? 'N/A'}`)
                                                  .join(' | ')}
                                    </span>
                                    <span>{device.pivot.status}</span>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
