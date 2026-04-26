import { router, usePage, usePoll } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import {
    ChevronRightCircleIcon, PowerOffIcon, Trash2Icon,
} from 'lucide-react';
import { index as clientIndex } from '@/routes/clients';
import { show as showDeployment } from '@/routes/deployments';
import { cancel, clear_queue, show as showTask } from '@/routes/tasks';
import type { BreadcrumbItem, SharedData } from '@/types';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { useEffect, useState } from 'react';

export default function Show() {
    const { current_client, flash } = usePage<SharedData>().props;
    const task = usePage().props.task
    const task_friendly_name = usePage().props.task_friendly_name
    const devices = usePage().props.devices
    const interfaces = usePage().props.interfaces
    const deployment = usePage().props.deployment
    const completedDeviceInterfaces = interfaces.filter(
        (device_interface) => device_interface.pivot.status === 'COMPLETED',
    );
    const displayColumns = usePage().props.display_columns ?? []
    const [isCancelling, setIsCancelling] = useState(false);
    const [isClearingQueue, setIsClearingQueue] = useState(false);

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
    ];

    usePoll(2000)

    useEffect(() => {
        if (flash?.success) {
            toast.success(flash.success, { id: 'task-flash-success' });
        }
        if (flash?.error) {
            toast.error(flash.error, { id: 'task-flash-error' });
        }
    }, [flash?.success, flash?.error]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="absolute top-4 right-4 flex items-center gap-2">
                <Button
                    variant="outline"
                    disabled={isCancelling || isClearingQueue}
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
                    disabled={isCancelling || isClearingQueue}
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
                    <Card className="h-[75vh] overflow-y-auto">
                        <CardHeader className="text-center text-2xl font-bold">
                            Progress
                        </CardHeader>
                        <CardDescription className="flex items-center justify-center">
                            <span className="p-1 text-3xl font-bold">
                                {completedDeviceInterfaces.length}
                            </span>
                            <ChevronRightCircleIcon />
                            <span className="p-1 text-3xl font-bold">
                                {interfaces.length}
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
                            {interfaces.map((device_interface) => {
                                const deviceForInterface = devices.find(
                                    (device) =>
                                        device.id ===
                                        device_interface.device_id,
                                );
                                return (
                                    <div
                                        key={device_interface.id}
                                        className={cn(
                                            'mb-2 flex items-center justify-between text-sm',
                                            device_interface.pivot.status ===
                                                'COMPLETED' && 'text-green-500',
                                        )}
                                    >
                                        <span>{deviceForInterface.name}</span>
                                        <span>
                                            {device_interface.interface}
                                        </span>
                                        {displayColumns.map((column) => (
                                            <span key={column}>
                                                {device_interface[column]}
                                            </span>
                                        ))}
                                        <span>
                                            {device_interface.pivot.status}
                                        </span>
                                    </div>
                                );
                            })}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
