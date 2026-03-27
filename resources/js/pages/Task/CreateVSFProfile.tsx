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
import { showSystemInfo } from '@/routes/tasks';
import { Card, CardContent, CardDescription, CardHeader } from '@/components/ui/card';

export default function Show() {
    const task = usePage().props.task
    const devices = usePage().props.devices
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
            href: showSystemInfo(task.id).url,
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
            <div className="flex gap-4 min-w-7xl my-6 mx-auto">
                <div className="max-w-[400px]">
                    <Card className="h-[75vh] overflow-y-auto">
                        <CardHeader className="font-bold text-center text-2xl">Progress</CardHeader>
                        <CardDescription className="flex justify-center items-center">
                            <span className="text-3xl font-bold p-1">{completedDevices.length}</span>
                            <ChevronRightCircleIcon/>
                            <span className="text-3xl font-bold p-1">{devices.length}</span>
                        </CardDescription>
                        <CardContent>
                            {task.status_log.split('\\n').map((message, index) => (
                                    <div key={index} className="mb-2 text-sm">
                                        {message}
                                    </div>
                                )
                            )}
                        </CardContent>
                    </Card>
                </div>
                <div className="flex-1" >
                    <Card className="h-[75vh] overflow-y-auto">
                        <CardHeader className="font-bold text-center text-2xl">Devices Provisioned</CardHeader>
                        <CardContent>
                            {devices.map((device) => (
                                <div key={device.id} className={cn("flex items-center justify-between mb-2", device.pivot.status === 'COMPLETED' && 'text-green-500')}>
                                    <span>{device.name}</span>
                                    <span className="text-sm text-gray-500">{device.serial}</span>
                                    <span className="text-sm text-gray-500">{device.device_function}</span>
                                    <span className="text-sm text-gray-500">{device.pivot.status}</span>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
