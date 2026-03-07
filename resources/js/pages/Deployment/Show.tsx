import { Form, useForm, usePage } from '@inertiajs/react';
import { useEcho, useEchoPublic } from '@laravel/echo-react';
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
import { router } from '@inertiajs/react';
import { store, test } from '@/actions/App/Http/Controllers/TaskController'
import { storeMany } from '@/actions/App/Http/Controllers/DeviceController';
import { useEffect, useState, useRef } from 'react';
import { columns } from '@/components/ui/devices-columns';
import { DataTable } from '@/components/ui/data-table';
import { toast } from 'sonner';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
export default function Show() {
    const deployment = usePage().props.deployment;
    const devices = deployment.devices;
    const { data, setData, post, progress, errors } = useForm({
        devices: null,
    })
    const [todos, setTodos] = useState({})
    const [tasks_progress, setTasksProgress] = useState({})
    const tasks = usePage().props.tasks;
    const [submitting, setSubmitting] = useState(false)
    const closeTriggerRef = useRef(null)

    const init_devices_status = {}
    const devices_status = tasks.map(task => init_devices_status[task] = devices.map((device) => (device.completed ? device : {...device, completed: false})))
    // const devicesStatusByTask = tasks.reduce(
    //     (acc, task) => ({
    //         ...acc,
    //         [task]: devices.map((device) => ({
    //             ...device,
    //             completed: false,
    //         })),
    //     }),
    //     init_devices_status,
    // );

    const [deploymentDevices, setDeploymentDevices] = useState(init_devices_status)
    function handleSubmit(e ) {
        e.preventDefault()
        post(storeMany(deployment.id).url)
    }

    function handleCheckboxChange(task_name : string, device_id : string, checked : boolean) {
        if (checked) {
            if (!todos[task_name]) {
                setTodos({
                    ...todos,
                    [task_name]: [
                        device_id
                    ],
                });
            } else {
                setTodos({
                    ...todos,
                    [task_name]: [
                        ...todos[task_name],
                        device_id
                    ]
                })
            }
        } else {
            if (todos[task_name].includes(device_id))
                setTodos(
                    {
                        ...todos,
                        [task_name]: todos[task_name].filter(id => id !== device_id)
                    }
                )
        }
    }

    function handleDeploymentClose(task_name : string) {
        const devices_completed = deploymentDevices[task_name] && deploymentDevices[task_name].filter(device => device.completed)
        if (devices_completed.length === devices.length) {
            const resetCompleted = deploymentDevices[task_name].map(device => ({...device, completed: false}))
            setDeploymentDevices({...deploymentDevices, [task_name]: resetCompleted})
        }
    }

    const dispatch_task_with_devices = (task, devices, all: boolean = false) => {
        const task_devices = all ? devices.map(device => device.id) : todos[task];
        if (all) {
            setTodos({...todos, [task]: task_devices})
        }
        router.post(store(deployment.id).url, {task_type: task, devices: task_devices})
    }



    const [statusMessage, setStatusMessage] = useState('')

    // useEchoPublic(
    //     'test.event',
    //     'TestEvent',
    //     (event) => {
    //         setStatusMessage(event.data.task_type)
    //         setDeploymentDevices({...deploymentDevices, [event.data.task_type] : deploymentDevices[event.data.task_type].map(device => device.id == parseInt(event.data.device_id) ? {...device, completed: true} : device)})
    //     }
    // )

    useEcho(
        `deployments.channel.${deployment.name.replaceAll(' ', '-')}`,
        'DeploymentEvent',
        (event) => {
            const task_type = event.data.task_type;
            setStatusMessage(event.data.message)
            // setDeploymentDevices({...deploymentDevices, [event.data.task_type] : deploymentDevices[event.data.task_type].map(device => device.id == parseInt(event.data.device_id) ? {...device, completed: true} : device)})
            const todos_task_without_device = todos[task_type] && todos[task_type].filter(id => id !== parseInt(event.data.device_id))
            setTodos({...todos, [task_type]: todos_task_without_device})
            const todos_task_progress = tasks_progress[task_type] ? tasks_progress[task_type] + 1 : 0
            setTasksProgress({
                ...tasks_progress,
                [task_type]: todos_task_progress
            });
        }
    )

    useEffect(() => {
        if (!submitting) return;
        closeTriggerRef.current?.click()
    })


    return (
        <AppLayout>
            <h1 className="text-3xl font-semibold text-center">{deployment.name}</h1>
            <div className="grid grid-cols-2 gap-5 mt-4 p-4">
                <div>
                    {devices.length > 0 ?
                        <DataTable data={devices} columns={columns} />
                     :
                        <p>No devices assigned to this deployment</p>
                    }
                    <p className="text-sm text-gray-500 mt-3">finished: {statusMessage}</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {
                        tasks.map((task,index) =>
                        <Card key={index} className="mt-4 min-w-md mx-auto">
                            <CardHeader>
                                <CardTitle>{task}</CardTitle>
                                <CardDescription>
                                    {todos[task] && todos[task].length > 0 && todos[task].length < devices.length ? `${todos[task].length} devices selected` : 'All Devices Selected'}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex gap-2">
                                <Dialog>
                                    <DialogTrigger asChild>
                                        <Button data-test="associate-devices-with-task">Filter Devices</Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogTitle>Associate Devices</DialogTitle>
                                        <DialogDescription>
                                            Filter devices associated with this task
                                        </DialogDescription>
                                        {
                                            devices.length > 0 ?
                                                devices.map((device, index) =>
                                                        <div className="flex gap-2" key={index}>
                                                            <Checkbox
                                                                id={`device-${index}`}
                                                                checked={todos[task] && todos[task].includes(device.id)}
                                                                onCheckedChange={(checked) => handleCheckboxChange(task, device.id, checked)}
                                                                />
                                                            <label htmlFor={`device-${index}`}>{device.name}</label>
                                                        </div>
                                                    ) :
                                                <p>Add devices to deployment before adding tasks</p>
                                        }
                                    </DialogContent>
                                </Dialog>
                                <Dialog>
                                    <DialogTrigger asChild>
                                        {
                                            todos[task] && todos[task].length > 0 && todos[task].length < devices.length ?
                                            <Button onClick={() => dispatch_task_with_devices(task, devices)}>Deploy Selected</Button> :
                                            <Button onClick={() => dispatch_task_with_devices(task, devices, true)}>Deploy with All Devices</Button>
                                        }
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogTitle>{task} Progress</DialogTitle>
                                        <DialogDescription>
                                            Remaining: {todos[task] && todos[task].length > 0 ? todos[task].length : 'None'} / {statusMessage}
                                        </DialogDescription>
                                        <DialogClose asChild>
                                            <Button onClick={() => handleDeploymentClose(task)}>Close</Button>
                                        </DialogClose>
                                        <ul>
                                        {
                                            todos[task] && devices
                                                .filter(device => todos[task].includes(device.id))
                                                .map((device, index) =>
                                            <li key={index} className='text-slate-500'>{device.name}</li>
                                            )
                                        }
                                        </ul>
                                    </DialogContent>
                                </Dialog>
                            </CardContent>
                        </Card>
                        )
                    }
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
                                <DialogClose asChild>
                                    <Button
                                        className="hidden"
                                        ref={closeTriggerRef}
                                    >
                                        Close
                                    </Button>
                                </DialogClose>
                            </DialogFooter>
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
