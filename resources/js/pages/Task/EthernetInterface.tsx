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
import { showEthernetInterface } from '@/routes/tasks';
import { toast } from 'sonner';

export default function Show() {
    const task = usePage().props.task
    const devices = usePage().props.devices
    const interfaces = usePage().props.interfaces
    const deployment = usePage().props.deployment
    const completedDeviceInterfaces = interfaces.filter(
        (device_interface) => device_interface.pivot.status === 'COMPLETED',
    );
    const [statusMessages, setStatusMessages] = useState([])

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
            href: showEthernetInterface(task.id).url,
        },
    ];

    useEcho(
        `deployments.channel.${deployment.name.replaceAll(' ', '-')}`,
        ['DeploymentEvent', 'FailureEvent'],
        (event) => {
            setStatusMessages((prevStatusMessages) => [...prevStatusMessages, event.data.message])
        }
    )

    usePoll(4000)

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="mx-auto flex gap-2">
                <div className="fixed top-1/4 left-1/6">
                    <h1 className="text-center text-2xl font-bold">Progress</h1>
                    <div className="mt-4 flex h-36 w-36 items-center justify-center rounded-full border-4 border-emerald-500/80">
                        <span className="p-1 text-3xl font-bold text-slate-500">
                            {completedDeviceInterfaces.length}
                        </span>
                        <ChevronRightCircleIcon />
                        <span className="p-1 text-3xl font-bold text-slate-600">
                            {interfaces.length}
                        </span>
                    </div>
                </div>
                <div className="col-span-2">
                    <h1 className="text-center text-2xl font-bold">
                        {deployment.name} | {task.task_type}
                    </h1>
                    <table className="mt-6 min-w-[50dvw] table-auto border border-slate-400">
                        <thead>
                            <tr className="text-left">
                                <th className="p-2">Device</th>
                                <th className="p-2">Interface</th>
                                <th className="p-2">Mode</th>
                                <th className="p-2">Port Profile</th>
                                <th className="p-2">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {interfaces.length > 0 &&
                                interfaces.map((device_interface, index) => {
                                    const deviceForInterface = devices.find(
                                        (device) =>
                                            device.id ===
                                            device_interface.device_id,
                                    );
                                    return (
                                        <tr
                                            key={device_interface.id}
                                            className={cn(
                                                device_interface.pivot
                                                    .status === 'COMPLETED' &&
                                                    index % 2 === 0
                                                    ? 'bg-emerald-100 text-emerald-500'
                                                    : '',
                                                device_interface.pivot
                                                    .status === 'COMPLETED' &&
                                                    index % 2 === 1
                                                    ? 'bg-emerald-400 text-emerald-100'
                                                    : '',
                                            )}
                                        >
                                            <td className="border border-slate-300 p-2">
                                                {deviceForInterface?.name}
                                            </td>
                                            <td className="border border-slate-300 p-2">
                                                {device_interface.interface}
                                            </td>
                                            <td className="border border-slate-300 p-2">
                                                {
                                                    device_interface.switch_port
                                                        ?.interface_mode
                                                }
                                            </td>
                                            <td className="border border-slate-300 p-2">
                                                {device_interface.sw_profile}
                                            </td>
                                            <td className="border border-slate-300 p-2">
                                                {device_interface.pivot.status}
                                            </td>
                                        </tr>
                                    );
                                })}
                        </tbody>
                    </table>
                </div>
                <div className="fixed top-1/4 right-1/50 max-w-[350px]">
                    <p className="text-bold text-center text-slate-700">
                        Status Logs
                    </p>
                    <div className="mt-2">
                    {statusMessages.map((message, index) => (
                        <p key={index} className="text-slate-500 text-xs">
                            {message}
                        </p>
                    ))}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
