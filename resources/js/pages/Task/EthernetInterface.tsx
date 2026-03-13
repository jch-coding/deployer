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
import { showEthernetInterface } from '@/routes/tasks';

export default function Show() {
    const task = usePage().props.task
    const devices = usePage().props.devices
    const interfaces = devices.map(device => device.interfaces.map(interface => interface)).flat()
    const deployment = usePage().props.deployment
    const [completedInterfaces, setCompletedInterfaces] = useState([])
    const completedDeviceInterfaces = interfaces.filter(
        (interface) => interface.pivot.status === 'COMPLETED',
    );
    const [totalCompletedInterfaces, setTotalCompletedInterfaces] = useState(0);
    const [newCompletedInterface, setNewCompletedInterface] = useState()
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
        'DeploymentEvent',
        (event) => {
            const completed_interface_id = event.data.device_id
            const new_completed_interface = interfaces.find(interface=> interface.id === completed_interface_id)
            setNewCompletedInterface(new_completed_interface)
        }
    )

    useEffect(() => {
        setCompletedInterfaces([...completedInterfaces, newCompletedInterface])
        const newTotal = completedInterfaces.length == 0 ? 0 : totalCompletedInterfaces + 1;
        setTotalCompletedInterfaces(newTotal);
    },[newCompletedInterface])

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="flex gap-4 max-w-6xl mx-auto">
                <div className="fixed top-1/2 left-1/6">
                    <h1 className="text-2xl text-center font-bold">Progress</h1>
                    <div className="mt-4 rounded-full border-4 border-green-500/80 h-36 w-36 flex justify-center items-center">
                        <span className="text-3xl text-slate-500 font-bold p-1">
                            {totalCompletedInterfaces + completedDeviceInterfaces.length - 1}
                        </span>
                        <ChevronRightCircleIcon/>
                        <span className="text-3xl text-slate-600 font-bold p-1">
                            {interfaces.length}
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
                        <th className="p-2">Interface</th>
                        <th className="p-2">Serial</th>
                    </tr>
                </thead>
                <tbody>
                    {interfaces.length > 0 &&
                        interfaces.map((interface) => {
                            const deviceForInterface = devices.find(device => device.id === interface.device_id);
                            return (
                            <tr
                                key={interface.id}
                                className={cn(
                                    interface.pivot.status === 'COMPLETED' ||
                                        completedInterfaces.find(
                                            (completedInterface) =>
                                                completedInterface?.id ===
                                                interface.id,
                                        )
                                        ? 'bg-green-100 text-green-500'
                                        : '',
                                )}
                            >
                                <td className="border border-slate-300 p-2">{deviceForInterface?.name}</td>
                                <td className="border border-slate-300 p-2">{interface.interface}</td>
                                <td className="border border-slate-300 p-2">{deviceForInterface?.serial}</td>
                            </tr>
                                )
                            }
                        )}
                </tbody>
            </table>
                </div>
            </div>
        </AppLayout>
    );
}
