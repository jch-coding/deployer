import { Form, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import { storeMany } from '@/actions/App/Http/Controllers/DeviceController';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardTitle,
} from '@/components/ui/card';
import { DataTable } from '@/components/ui/data-table';
import { columns } from '@/components/ui/devices-columns';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import LaravelPaginator from '@/components/ui/LaravelPaginator';
import TaskCard from '@/components/ui/TaskCard';
import TaskItemsCard from '@/components/ui/TaskItemsCard';
import AppLayout from '@/layouts/app-layout';
import { show as showTask } from '@/routes/tasks';
import type { SharedData } from '@/types';
import type { Paginator } from '@/types/deployer';

type Device = {
    id: string;
    name: string;
}

type Deployment = {
    id: string;
    name: string;
    devices: Device[];
}

type Task = {
    id: number;
    task_type: string;
    devices_count: number;
    status: string;
    created_at: string;
    updated_at: string;
    human_updated_at: string;
    human_created_at: string;
    friendly_name: string;
    friendly_description: string;
}

type DeploymentPageProps = {
    paginator: Paginator<Device>;
    base_urls: string[];
    deployment: Deployment;
    tasks: Task[];
    latest_tasks: Task[];
} & SharedData;
export default function Show() {
    const deployment = usePage<DeploymentPageProps>().props.deployment;
    const devicesPaginator = usePage<DeploymentPageProps>().props.devices as Paginator<Device>;
    const devices = devicesPaginator.data;
    const allDevices = deployment.devices
    const { setData, post, progress, errors } = useForm({
        devices: null,
    })
    const tasks = usePage<DeploymentPageProps>().props.tasks;
    const latest_tasks = usePage<DeploymentPageProps>().props.latest_tasks;
    const [submitting, setSubmitting] = useState(false);

    const isDeviceBasedTask = (task_type: string) => {
        return task_type in [
            'UPDATE_SYSTEM_INFO',
            'ASSIGN_DEVICE_FUNCTION',
            'PREPROVISION_DEVICE_TO_GROUP',
            'ASSOCIATE_SITE_AND_NAME',
            'CREATE_VSF_PROFILE',
        ]
    }

    const isInterfaceBasedTask = (task_type: string) => {
        return task_type in [
            'CONFIGURE_ETHERNET_INTERFACE',
            'CONFIGURE_LAG_INTERFACE',
            'CONFIGURE_VLAN_INTERFACE',
        ]
    }

    function handleSubmit(e ) {
        e.preventDefault()
        post(storeMany(deployment.id).url)
    }

    return (
        <AppLayout>
            <h1 className="text-center text-3xl font-semibold">
                {deployment.name}
            </h1>
            <div className="mt-4 grid grid-cols-2 gap-5 p-4">
                <div className="col-span-2 mx-auto">
                    <h2 className="text-center text-xl font-semibold">
                        Latest Tasks
                    </h2>
                    {latest_tasks.length > 0 && (
                        <div className="mt-2 flex flex-wrap gap-2">
                            {latest_tasks.map((task, index) => (
                                <Card index={index} className="max-w-sm px-2">
                                    <CardTitle className="text-center text-xs">
                                        {task.friendly_name}
                                    </CardTitle>
                                    <CardDescription className="text-center text-xs">
                                        latest updated: {task.human_updated_at}
                                    </CardDescription>
                                    <CardContent className="text-sm">
                                        <div className="flex justify-between space-x-2">
                                            <p>Devices: {task.devices_count}</p>
                                            <p>Status: {task.status}</p>
                                        </div>
                                        <a
                                            href={showTask(task.id).url}
                                            className="text-emerald-500 hover:underline"
                                        >
                                            View Details
                                        </a>
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    )}
                </div>
                <div>
                    {devices.length > 0 ? (
                        <div className="mt-6">
                            <DataTable data={devices} columns={columns} />
                            {devices.length > 0 &&
                                devicesPaginator.total >
                                    devicesPaginator.per_page && (
                                    <LaravelPaginator
                                        TPaginator={devicesPaginator}
                                    />
                                )}
                        </div>
                    ) : (
                        <p>No devices assigned to this deployment</p>
                    )}
                </div>
                <div>
                    <div className="mt-6 flex flex-wrap justify-center gap-2">
                        {tasks.map((task, index) => {
                            const TaskComponent = isDeviceBasedTask(task.task_type) ? TaskCard : TaskItemsCard;
                            return (
                                <TaskComponent
                                    index={index}
                                    task={task.task_type}
                                    task_friendly_name={task.friendly_name}
                                    task_friendly_description={task.friendly_description}
                                    devices={allDevices}
                                    deployment={deployment}
                                />
                            );
                        })}
                    </div>
                </div>
                <Dialog>
                    <DialogTrigger asChild>
                        <Button
                            data-test="add-devices"
                            className="absolute top-4 right-4"
                        >
                            Add Devices
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogTitle>Add Device</DialogTitle>
                        <DialogDescription>
                            Add devices to this deployment
                        </DialogDescription>
                        <Form
                            action={storeMany(deployment.id).url}
                            method="POST"
                            onSuccess={() => {
                                toast.success('Devices added successfully');
                                setSubmitting(false);
                            }}
                            onError={() => {
                                toast.error('Failed to add devices');
                                setSubmitting(false);
                            }}
                            data-test="add-devices-form"
                            className="flex flex-col gap-4"
                            as="form"
                            encType="multipart/form-data"
                            onSubmit={(e) => {
                                e.preventDefault();
                                setSubmitting(true);
                                handleSubmit(e);
                            }}
                        >
                            <input
                                type="file"
                                name="devices"
                                onChange={(e) =>
                                    setData('devices', e.target.files[0])
                                }
                                className="block cursor-pointer rounded-lg border border-gray-300 bg-gray-50 p-2 text-sm text-gray-900 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-gray-400 dark:placeholder-gray-400"
                                disabled={submitting}
                            />
                            {errors && (
                                <p className="text-xs text-red-500">
                                    {errors.devices}
                                </p>
                            )}
                            <DialogFooter className="mt-4 flex-row-reverse sm:justify-start">
                                <Button
                                    data-test="upload-devices"
                                    type="submit"
                                >
                                    Add Devices
                                </Button>
                                {progress && (
                                    <progress
                                        value={progress.percentage}
                                        max="100"
                                    >
                                        {progress.percentage}%
                                    </progress>
                                )}
                            </DialogFooter>
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
