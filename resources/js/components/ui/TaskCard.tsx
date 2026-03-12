import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { store } from '@/routes/tasks';

type DeviceType = {
    id: number,
    name: string,
    completed: boolean
}

type DeploymentType = {
    id: number,
    name: string,
}

export default function TaskCard({ task, devices, deployment } : { task: string, devices: DeviceType[], deployment: DeploymentType}) {
    const [taskDevices, setTaskDevices] = useState<DeviceType[]>([])
    const [completedDevices, setCompletedDevices] = useState<DeviceType[]>([])
    const [statusMessage, setStatusMessage] = useState('')
    const [newDevice, setNewDevice] = useState('')
    const handleCheckboxChange = (deviceId : number, checked : boolean) => {
        const newDevice = devices.find(device => device.id === deviceId)
        if (checked) {
            setTaskDevices([...taskDevices, {...newDevice, completed: false}])
        } else {
            if (taskDevices.find(device => device.id === deviceId)) {
                setTaskDevices(taskDevices.filter(device => device.id !== deviceId))
            }
        }
    }

    const dispatch_task_with_devices = (task, devices, allDevices = false) => {
        const devices_for_task= allDevices ? devices : devices.filter(device => taskDevices.find(dev => device.id === dev.id) !== undefined)
        const devices_with_completed_status = devices_for_task.map(device => ({...device, completed: false}))
        setTaskDevices(devices_with_completed_status)
        const taskData = {
            task_type: task,
            devices: devices_for_task
        }
        router.post(store(deployment.id).url, taskData)
    }

    const resetCompletedDevices = () => setCompletedDevices([])

    useEcho(
        `deployments.channel.${deployment.name.replaceAll(' ', '-')}`,
        'DeploymentEvent',
        (event) => {
            const updatedDevicesStatus : DeviceType[] = taskDevices.map(device => { device.id == parseInt(event.data.device_id) ? { ...device, completed: true} : device })
            const newDeviceUpdated = devices.find(device => device.id === parseInt(event.data.device_id))
            setNewDevice(newDeviceUpdated)
            setTaskDevices(updatedDevicesStatus)
            setStatusMessage(event.data.message)
    })

    useEffect(() => {
        setCompletedDevices([...completedDevices, newDevice])
    },[newDevice])

    return (
        <Card>
            <CardHeader>
                <CardTitle>{task}</CardTitle>
                <CardDescription>
                    {taskDevices.length > 0 && taskDevices.length < devices.length ? `${taskDevices.length} devices selected` : 'All Devices Selected'}
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
                            <div className="-mx-4 no-scrollbar max-h-[50vh] overflow-y-auto px-4">
                        {
                            devices.length > 0 ?
                                devices.map((device, index) =>
                                    <div className="flex gap-2" key={index}>
                                        <Checkbox
                                            id={`device-${index}`}
                                            checked={taskDevices.find(dev => dev.id === device.id) !== undefined}
                                            onCheckedChange={(checked) => handleCheckboxChange(device.id, checked)}
                                        />
                                        <label htmlFor={`device-${index}`}>{device.name}</label>
                                    </div>
                                ) :
                                <p>Add devices to deployment before adding tasks</p>
                        }
                            </div>
                    </DialogContent>
                </Dialog>
                <Dialog>
                    <DialogTrigger asChild>
                        {
                            taskDevices.length > 0 && taskDevices.length < devices.length ?
                                <Button onClick={() => dispatch_task_with_devices(task, devices)}>Deploy Selected</Button> :
                                <Button onClick={() => dispatch_task_with_devices(task, devices, true)}>Deploy with All Devices</Button>
                        }
                    </DialogTrigger>
                    <DialogContent>
                        <DialogTitle>{task} Progress</DialogTitle>
                        <DialogDescription>
                            {completedDevices.length} / {devices.length} {statusMessage}
                        </DialogDescription>
                        <DialogClose asChild>
                            <Button onClick={() => resetCompletedDevices()}>Close</Button>
                        </DialogClose>
                        <div className="-mx-4 no-scrollbar max-h-[50vh] overflow-y-auto px-4">
                            <ul>
                                {
                                    completedDevices.length > 0 ?
                                        completedDevices.map((device, index) =>
                                            <li key={index} className='text-green-500'>{device.name}</li>
                                        ) : <li>Deployment started</li>
                                }
                            </ul>
                        </div>
                    </DialogContent>
                </Dialog>
            </CardContent>
        </Card>
    )
}
