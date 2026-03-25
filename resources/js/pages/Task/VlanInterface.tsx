import { usePage, usePoll } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import {
    ChevronRightCircleIcon,
} from 'lucide-react';
import type { BreadcrumbItem } from '@/types';
import { dashboard } from '@/routes';
import { index as clientIndex } from '@/routes/clients';
import  { index as deploymentIndex } from '@/routes/deployments';
import { showVlanInterface } from '@/routes/tasks';
import { toast } from 'sonner';
import { Card, CardContent, CardDescription, CardHeader } from '@/components/ui/card';

export default function Show() {
    const task = usePage().props.task
    const devices = usePage().props.devices
    const interfaces = usePage().props.interfaces
    const completedDeviceInterfaces = interfaces.filter(
        (device_interface) => device_interface.pivot.status === 'COMPLETED',
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
            href: showVlanInterface(task.id).url,
        },
    ];

    usePoll(2000)

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="flex gap-4 min-w-7xl my-6 mx-auto">
                <div className="max-w-[400px]">
                    <Card className="h-[75vh] overflow-y-auto">
                        <CardHeader className="font-bold text-center text-2xl">Progress</CardHeader>
                        <CardDescription className="flex justify-center items-center">
                            <span className="text-3xl font-bold p-1">{completedDeviceInterfaces.length}</span>
                            <ChevronRightCircleIcon/>
                            <span className="text-3xl font-bold p-1">{interfaces.length}</span>
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
                            {interfaces.map((device_interface) => {
                                const deviceForInterface = devices.find(
                                    (device) =>
                                        device.id ===
                                        device_interface.device_id,
                                );
                                return (
                                    <div key={device_interface.id} className={cn("flex items-center justify-between mb-2", device_interface.pivot.status === 'COMPLETED' && 'text-green-500')}>
                                        <span>{deviceForInterface.name}</span>
                                        <span className="text-sm">{device_interface.interface}</span>
                                        <span className="text-sm">{device_interface.ip_address}</span>
                                        <span className="text-sm">{device_interface.pivot.status}</span>
                                    </div>
                                )
                            })}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
