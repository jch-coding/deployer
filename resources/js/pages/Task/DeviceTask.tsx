import { usePage, usePoll } from '@inertiajs/react';
import {
    ChevronRightCircleIcon,
} from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as clientIndex } from '@/routes/clients';
import  { show as showDeployment } from '@/routes/deployments';
import { show as showTask } from '@/routes/tasks';
import type { BreadcrumbItem } from '@/types';

export default function Show() {
    const task = usePage().props.task
    const devices = usePage().props.devices
    const displayColumns= usePage().props.display_columns
    const deployment = usePage().props.deployment
    const completedDevices= devices.filter(
        (device) => device.pivot.status === 'COMPLETED',
    );

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
    ]

    usePoll(2000)

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="mx-auto my-6 flex min-w-7xl gap-4">
                <div className="max-w-[400px]">
                    <Card className="h-1/2 overflow-y-auto">
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
                    <Card>
                        <CardHeader className="text-center text-2xl font-bold">
                            Devices Provisioned
                        </CardHeader>
                        <CardContent>
                            {devices.map((device) => (
                                <div
                                    key={device.id}
                                    className={cn(
                                        'mb-2 flex items-center justify-between text-sm',
                                        device.pivot.status === 'COMPLETED' &&
                                            'text-green-500',
                                    )}
                                >
                                    <span>{device.name}</span>
                                    <span>
                                        {device.serial}
                                    </span>
                                    {displayColumns.map((column) => (
                                        <span>
                                            {device[column]}
                                        </span>
                                    ))}
                                    {
                                        task.task_type === 'CONFIGURE_LAG_INTERFACE' &&
                                        <span>
                                            {device.lacp_profile.port_list}
                                        </span>
                                    }
                                    <span>
                                        {device.pivot.status}
                                    </span>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
