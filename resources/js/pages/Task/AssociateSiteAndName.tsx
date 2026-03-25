import { usePage, usePoll } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { useEffect, useState } from 'react';
import { useEcho } from '@laravel/echo-react';
import { cn } from '@/lib/utils';
import {
    ChevronRightCircleIcon,
} from 'lucide-react';
import type { BreadcrumbItem } from '@/types';
import { dashboard } from '@/routes';
import { index as clientIndex } from '@/routes/clients';
import  { index as deploymentIndex } from '@/routes/deployments';
import { showAssociateSiteAndName } from '@/routes/tasks';
import { Card, CardContent, CardDescription, CardHeader } from '@/components/ui/card';

export default function Show() {
    const task = usePage().props.task
    const devices = usePage().props.devices
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
            href: deploymentIndex().url,
        },
        {
            title: 'Task',
            href: showAssociateSiteAndName(task.id).url,
        },
    ];

    // useEcho(
    //     `deployments.channel.${deployment.name.replaceAll(' ', '-')}`,
    //     'DeploymentEvent',
    //     (event) => {
    //     }
    // )

    usePoll(2000)

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="mx-auto my-6 flex min-w-7xl gap-4">
                <div className="max-w-[400px]">
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
                    <Card className="h-[75vh] overflow-y-auto p-4">
                        <CardHeader className="text-center text-2xl font-bold">
                            Devices Provisioned
                        </CardHeader>
                        <CardContent>
                            {devices.map((device, index) => (
                                <div
                                    key={device.id}
                                    className={cn(
                                        'p-2 flex items-center justify-between',
                                        device.pivot.status === 'COMPLETED' &&
                                            index % 2 === 0 &&
                                            'bg-green-100 text-green-500',
                                        device.pivot.status === 'COMPLETED' &&
                                            index % 2 === 1 &&
                                            'bg-green-400 text-green-100',
                                    )}
                                >
                                    <span>{device.name}</span>
                                    <span className="text-sm">
                                        {device.serial}
                                    </span>
                                    <span className="text-sm">
                                        {device.site}
                                    </span>
                                    <span className="text-sm">
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
