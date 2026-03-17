import { Form, useForm, usePage } from '@inertiajs/react';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { storeMany } from '@/actions/App/Http/Controllers/DeviceController';
import { showSystemInfo, showEthernetInterface } from '@/actions/App/Http/Controllers/TaskController';
import { useEffect, useState, useRef } from 'react';
import { columns } from '@/components/ui/devices-columns';
import { DataTable } from '@/components/ui/data-table';
import { toast } from 'sonner';
import TaskCard from '@/components/ui/TaskCard';
import type { Paginator } from '@/types/deployer';
import type { Client } from '@/types/clients/client';
import type { SharedData } from '@/types';
import LaravelPaginator from '@/components/ui/LaravelPaginator';
import {
    Card,
    CardContent,
    CardDescription,
    CardTitle,
} from '@/components/ui/card';

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

    const taskShow = (task_type: string, task_id: number) => {
        switch (task_type) {
            case 'UPDATE_SYSTEM_INFO':
                return showSystemInfo(task_id).url;
            case 'UPDATE_ETHERNET_INTERFACE':
                return showEthernetInterface(task_id).url;
        }
    }

    function handleSubmit(e ) {
        e.preventDefault()
        post(storeMany(deployment.id).url)
    }

    return (
        <AppLayout>
            <h1 className="text-3xl font-semibold text-center">{deployment.name}</h1>
            <div className="grid grid-cols-2 gap-5 mt-4 p-4">
                <div>
                    {devices.length > 0 ?
                        <div>
                            <DataTable data={devices} columns={columns} />
                            { devices.length > 0 && devicesPaginator.total > devicesPaginator.per_page &&
                                <LaravelPaginator TPaginator={devicesPaginator} />
                            }
                        </div>
                     :
                        <p>No devices assigned to this deployment</p>
                    }
                </div>
                <div>
                    <h2 className="text-xl font-semibold text-center">Latest Tasks</h2>
                    {
                        latest_tasks.length > 0 &&
                        <div className="flex flex-wrap gap-2 mt-2">
                            {
                                latest_tasks.map((task, index) =>
                                <Card index={index} className="max-w-sm px-2">
                                    <CardTitle className="text-center text-xs">{task.task_type}</CardTitle>
                                    <CardDescription className="text-xs text-center">latest updated: {task.human_updated_at}</CardDescription>
                                    <CardContent className="text-sm">
                                        <div className="flex justify-between space-x-2">
                                            <p>Devices: {task.devices_count}</p>
                                            <p>Status: {task.status}</p>
                                        </div>
                                        <a href={taskShow(task.task_type, task.id)} className="text-emerald-500 hover:underline">View Details</a>
                                    </CardContent>
                                </Card>)
                            }
                        </div>
                    }
                    <div className="flex flex-wrap gap-2 justify-center mt-6">
                        {
                            tasks.map((task,index) =>
                                <TaskCard index={index} task={task} devices={allDevices} deployment={deployment}/>
                            )
                        }
                    </div>
                </div>
                <Dialog>
                    <DialogTrigger asChild>
                        <Button data-test="add-devices" className="absolute top-4 right-4">
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
                            onSuccess={() => {toast.success('Devices added successfully'); setSubmitting(false)}}
                            onError={() => {toast.error('Failed to add devices'); setSubmitting(false)}}
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
                                <Button data-test="upload-devices" type="submit">Add Devices</Button>
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
