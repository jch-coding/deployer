import { router, usePage, usePoll } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import {
    ChevronRightCircleIcon, PowerOffIcon,
} from 'lucide-react';
import { dashboard } from '@/routes';
import { index as clientIndex } from '@/routes/clients';
import { show as showDeployment } from '@/routes/deployments';
import { cancel, show as showTask } from '@/routes/tasks';
import type { BreadcrumbItem } from '@/types';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';

export default function Show() {
    const task = usePage().props.task
    const task_friendly_name = usePage().props.task_friendly_name
    const devices = usePage().props.devices
    const interfaces = usePage().props.interfaces
    const deployment = usePage().props.deployment
    const completedDeviceInterfaces = interfaces.filter(
        (device_interface) => device_interface.pivot.status === 'COMPLETED',
    );
    const displayColumns = usePage().props.display_columns

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Dashboard',
            href: dashboard().url,
        },
        {
            title: 'Client',
            href: clientIndex().url,
        },
        {
            title: 'Deployment',
            href: showDeployment(deployment.id).url,
        },
        {
            title: 'Task',
            href: showTask(task.id).url,
        },
    ];

    usePoll(2000)

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="absolute top-4 right-4">
                <Button variant="destructive" onClick={() => router.patch(cancel(task.id).url)}>
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
