import { usePage } from '@inertiajs/react';
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
    const [completedDevices, setCompletedDevices] = useState([])
    const completedDevicesFromBackend = devices.filter(
        (device) => device.pivot.status === 'COMPLETED',
    );
    const [totalCompletedDevices, setTotalCompletedDevices] = useState(0);
    const [newCompletedDevice, setNewCompletedDevice] = useState()
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
            const completed_device_id = event.data.device_id
            const new_completed_device = devices.find(device => device.id === completed_device_id)
            setNewCompletedDevice({...new_completed_device})
        }
    )

    useEffect(() => {
        setCompletedDevices([...completedDevices, newCompletedDevice])
        const newTotal = completedDevices.length == 0 ? 0 : totalCompletedDevices + 1;
        setTotalCompletedDevices(newTotal);
    },[newCompletedDevice])

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="flex gap-4 max-w-6xl mx-auto">
                <div className="fixed top-1/2 left-1/6">
                    <h1 className="text-2xl text-center font-bold">Progress</h1>
                    <div className="mt-4 rounded-full border-4 border-green-500/80 h-36 w-36 flex justify-center items-center">
                        <span className="text-3xl text-slate-500 font-bold p-1">
                            {totalCompletedDevices + completedDevicesFromBackend.length - 1}
                        </span>
                        <ChevronRightCircleIcon/>
                        <span className="text-3xl text-slate-600 font-bold p-1">
                            {devices.length}
                        </span>
                    </div>
                </div>
                <div className="flex-1" >
            <h1 className="text-center text-2xl font-bold">
                {deployment.name} | {task.task_type}
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
                        devices.map((device) => (
                            <tr
                                key={device.id}
                                className={cn(
                                    device.pivot.status === 'COMPLETED' ||
                                        completedDevices.find(
                                            (completedDevice) =>
                                                completedDevice?.id ===
                                                device.id,
                                        )
                                        ? 'bg-green-100 text-green-500'
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
