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
            href: showSystemInfo(task.id).url,
        },
    ];

    useEcho(
        `deployments.channel.${deployment.name.replaceAll(' ', '-')}`,
        'DeploymentEvent',
        (event) => {
        }
    )

    usePoll(2000)

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="flex gap-4 max-w-6xl mx-auto">
                <div className="fixed top-1/8 left-1/8">
                    <h1 className="text-2xl text-center font-bold">Progress</h1>
                    <div className="mt-4 mx-auto rounded-full border-4 border-green-500/80 h-36 w-36 flex justify-center items-center">
                        <span className="text-3xl text-slate-500 font-bold p-1">
                            { completedDevices.length }
                        </span>
                        <ChevronRightCircleIcon/>
                        <span className="text-3xl text-slate-600 font-bold p-1">
                            {devices.length}
                        </span>
                    </div>
                    <div className="mt-4 p-4 w-[350px]">
                        <p className="text-bold text-center text-slate-700">
                            Status Logs
                        </p>
                        <ul className="mt-2">
                            {task.status_log
                                .split('\n')
                                .map((message, index) => (
                                    <li
                                        key={index}
                                    >
                                        {message}
                                    </li>
                                    )
                                )
                            }
                        </ul>
                    </div>
                </div>
                <div className="flex-1" >
            <h1 className="text-center text-2xl font-bold">
                Deployment: {deployment.name} : {task.task_type}
            </h1>
            <table className="mt-6 mx-auto min-w-[50dvw] table-auto border border-slate-400">
                <thead>
                    <tr className="text-left">
                        <th className="p-2">Device</th>
                        <th className="p-2">Serial</th>
                        <th className="p-2">Device Function</th>
                    </tr>
                </thead>
                <tbody>
                    {devices.length > 0 &&
                        devices.map((device, index) => (
                            <tr
                                key={device.id}
                                className={cn(
                                    device.pivot.status === 'COMPLETED' && index % 2 === 0
                                        ? 'bg-emerald-100 text-emerald-400'
                                        : '',
                                    device.pivot.status === 'COMPLETED' && index % 2 === 1
                                        ? 'bg-emerald-400 text-emerald-100'
                                        : '',
                                )}
                            >
                                <td className="border border-slate-300 p-2">{device.name}</td>
                                <td className="border border-slate-300 p-2">{device.serial}</td>
                                <td className="border border-slate-300 p-2">{device.device_function}</td>
                            </tr>
                        ))}
                </tbody>
            </table>
                </div>
            </div>
        </AppLayout>
    );
}
